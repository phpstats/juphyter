"""The Jupyter transport: an ipykernel wrapper over juphyter's PHP engine.

juphyter is split in two. The *engine* is a PHP process that parses, executes
and renders cells; the *transport* owns the Jupyter protocol and knows nothing
about PHP. This module is the transport. It subclasses
``ipykernel.kernelbase.Kernel``, so ZeroMQ, message signing, protocol version
negotiation and the heartbeat are ipykernel's, and every request is forwarded
to the engine as one JSON line over a unix domain socket.

The protocol between the two halves is documented on the PHP side, in
``src/Protocol/Request.php`` and ``src/Protocol/Event.php``. In short: one JSON
object per line in each direction, every request carries a string ``id``, and
every request is answered by exactly one terminal event (``ok``, ``error`` or
a matching ``*_reply``).

## Why a socket, and not the engine's stdin and stdout

A cell can write to the real file descriptors rather than through PHP's output
buffering: an extension writing at the C level, or a plain
``fwrite(STDOUT, ...)``. On a stdout-based protocol that corrupts the channel.
With a socket of its own the engine's own descriptors stay free, and this
module pumps whatever appears on them as ordinary ``stream`` output.

## Threads, not asyncio

The engine's socket and the child's two pipes are read by three plain threads
that put what they read into one :class:`queue.Queue`, and the asynchronous
hooks drain that queue through :func:`asyncio.to_thread`. The reason is that
ipykernel 7 runs the control channel on a thread of its own with its own event
loop, so ``do_shutdown`` and ``do_execute`` do not share one: an
:class:`asyncio.StreamReader` created under the shell loop could not be touched
from the control thread. Threads and a thread-safe queue are loop-agnostic.

Reading all three sources concurrently is not a detail. The child's stdout and
stderr are pipes with a fixed buffer, so anything that reads one to completion
before the other deadlocks the moment a cell prints more than fits.

## Outliving the kernel

An engine must not outlive the kernel that spawned it. Three things see to
that between them: the engine returns from its loop when the socket reaches
end of input, this module reaps the child on shutdown and on SIGTERM, and a
third process ends the engine when this one is killed outright with a cell
still running. :meth:`Engine._watch_from_outside` has the reasoning.

## Interrupts

The kernelspec asks for ``interrupt_mode: signal``, and the engine is spawned
in a session of its own so that the front end's signal reaches it exactly once,
through :meth:`Engine.interrupt`. :meth:`Engine._spawn` says why that matters.

## Ordering

The single queue is also what keeps output in order. A pump thread does not
publish a Jupyter message itself; it hands the text to the queue, and whichever
request is in flight publishes it in the order it was queued, with that
request's parent header. Output that arrives while no request is in flight
waits in the queue and is published with the next one, which is the only
attribution available for it.
"""

from __future__ import annotations

import asyncio
import atexit
import codecs
import itertools
import json
import logging
import os
import queue
import shutil
import signal
import socket
import subprocess
import sys
import tempfile
import threading
from contextlib import contextmanager
from pathlib import Path
from typing import Any, Iterator

from ipykernel.kernelbase import Kernel
from traitlets import Any as TraitAny

__all__ = ["Engine", "EngineError", "JuphyterKernel"]

#: What the reader thread queues when the engine's socket reaches end of input.
_END_OF_INPUT = "__juphyter_eof__"

#: The watchdog: a process that ends the engine when this kernel is killed
#: outright. See :meth:`Engine._watch_from_outside` for why it is a process of
#: its own and why its own parent is the measure of whether we are still here.
_WATCHDOG = """
import os
import signal
import sys
import time

engine, interval = int(sys.argv[1]), float(sys.argv[2])
started_by = os.getppid()

while os.getppid() == started_by:
    time.sleep(interval)

try:
    os.kill(engine, signal.SIGKILL)
except OSError:
    pass
"""

#: The event types that answer a request and nothing follows them.
_TERMINAL = ("ok", "error")

#: The errors that mean the engine process is gone, whatever its exit status.
_FATAL_ENAMES = ("SystemExit", "FatalError")

logger = logging.getLogger(__name__)


class EngineError(RuntimeError):
    """The engine could not be started, or went away mid-request."""


def to_byte_offset(code: str, position: int) -> int:
    """Turn a character offset into the byte offset the engine counts in.

    Jupyter measures ``cursor_pos`` in characters and PHP's string functions
    count bytes. The two agree on ASCII and part ways on anything else, so
    every offset crossing this boundary is converted rather than passed on.
    """
    position = max(0, min(position, len(code)))

    return len(code[:position].encode("utf-8"))


def to_character_offset(code: str, position: int) -> int:
    """Turn a byte offset from the engine back into a character offset.

    An offset that lands inside a multibyte character is moved back to the
    character boundary before it, which is where a front end can put a cursor.
    """
    encoded = code.encode("utf-8")
    position = max(0, min(position, len(encoded)))

    return len(encoded[:position].decode("utf-8", "ignore"))


class Engine:
    """The PHP engine process, and the socket the transport talks to it over.

    One instance owns one child process for the life of the kernel. It creates
    a private directory, listens on a socket inside it, spawns
    ``php <engine> engine`` with ``JUPHYTER_SOCKET`` pointing at that socket,
    and waits for the engine to connect. Requests go out through
    :meth:`send`; everything the engine says, and everything it writes to its
    own descriptors, comes back through :meth:`drain`.
    """

    #: How long to wait for the engine to connect before giving up on it.
    CONNECT_TIMEOUT = 30.0

    #: How long a `shutdown` request has to end the process on its own.
    SHUTDOWN_TIMEOUT = 5.0

    #: How long SIGTERM has, after that, before SIGKILL.
    TERMINATE_TIMEOUT = 2.0

    #: How much of the engine's stderr to quote when it fails to start.
    STDERR_QUOTE = 4096

    #: Seconds between the watchdog's checks on this process.
    WATCHDOG_INTERVAL = 2.0

    def __init__(
        self,
        php: str | None = None,
        engine: str | None = None,
        log: logging.Logger | None = None,
    ) -> None:
        self.php = php or os.environ.get("JUPHYTER_PHP") or "php"
        self.engine = engine or os.environ.get("JUPHYTER_ENGINE") or self._bundled_engine()
        self.log = log or logger

        self._events: queue.Queue[dict[str, Any]] = queue.Queue()
        self._pending: list[dict[str, Any]] = []
        self._ids = itertools.count(1)
        self._lifecycle = threading.Lock()
        self._writing = threading.Lock()
        self._threads: list[threading.Thread] = []

        self._directory: str | None = None
        self._path: str | None = None
        self._server: socket.socket | None = None
        self._connection: socket.socket | None = None
        self._process: subprocess.Popen[bytes] | None = None
        self._watchdog: subprocess.Popen[bytes] | None = None
        self._started = False
        self._stopped = False
        self._finished = False

    @staticmethod
    def _bundled_engine() -> str:
        """The `bin/juphyter` beside this module, for a kernel started by hand.

        A kernelspec written by ``juphyter install`` always names the engine in
        ``JUPHYTER_ENGINE``, PHAR installs included. This is the fallback for
        someone running ``python -m juphyter_kernel`` out of a checkout.
        """
        candidate = Path(__file__).resolve().parents[2] / "bin" / "juphyter"

        return str(candidate)

    @property
    def started(self) -> bool:
        return self._started

    @property
    def alive(self) -> bool:
        """Whether the child is still running."""
        process = self._process

        return process is not None and process.poll() is None

    @property
    def finished(self) -> bool:
        """Whether the socket has reached end of input."""
        return self._finished

    @property
    def pid(self) -> int | None:
        return self._process.pid if self._process is not None else None

    @property
    def directory(self) -> str | None:
        """The private directory holding the socket, for a test to look at."""
        return self._directory

    def start(self) -> None:
        """Spawn the engine and wait for it to connect. Idempotent.

        Blocking, and safe to call from any thread: the hooks reach it through
        :func:`asyncio.to_thread`.
        """
        with self._lifecycle:
            if self._started:
                return

            if self._stopped:
                raise EngineError("juphyter: the kernel is shutting down.")

            # 0o700, which is what mkdtemp gives, because the socket is the way
            # into this kernel's PHP session and nobody else needs it.
            self._directory = tempfile.mkdtemp(prefix="juphyter-")
            os.chmod(self._directory, 0o700)
            self._path = os.path.join(self._directory, "engine.sock")

            try:
                self._listen()
                self._spawn()
                self._accept()
            except EngineError:
                self._clean_up()

                raise

            self._watch_from_outside()
            self._started = True

    def _listen(self) -> None:
        server = socket.socket(socket.AF_UNIX, socket.SOCK_STREAM)

        try:
            server.bind(self._path)
            server.listen(1)
        except OSError as error:
            server.close()

            raise EngineError(
                f"juphyter: could not listen on {self._path} ({error})."
            ) from error

        server.settimeout(self.CONNECT_TIMEOUT)
        self._server = server

    def _spawn(self) -> None:
        """Start the engine in a session of its own.

        The environment is inherited, because the kernelspec's env is already
        in it and the engine reads JUPHYTER_AUTOLOAD from there itself. The
        working directory is inherited too: Jupyter sets it to the notebook's
        directory, which is where the engine looks for the project.

        ``start_new_session`` is what makes the interrupt exact. A front end
        interrupting a signal-mode kernel sends SIGINT to the kernel's process
        group, so a child in that group would receive the signal twice: once
        from the group and once from :meth:`interrupt`. The engine's SIGINT
        handler is installed for the length of a cell and throws, and a second
        signal arriving while the first is still unwinding throws again from
        inside the handling of the first — which abandons the error report and
        the collection of the cell's variables half way through. One session of
        its own means exactly one signal, the one sent here.

        The cost is that a process-group signal no longer reaps the engine, so
        this class does it: on `shutdown`, from `atexit`, and on SIGTERM, and
        through the watchdog in :meth:`_watch_from_outside` when this process
        is killed outright. The engine also returns from its loop by itself
        when the socket reaches end of input, which covers an idle one.
        """
        environment = dict(os.environ, JUPHYTER_SOCKET=str(self._path))

        try:
            self._process = subprocess.Popen(
                [self.php, self.engine, "engine"],
                stdin=subprocess.DEVNULL,
                stdout=subprocess.PIPE,
                stderr=subprocess.PIPE,
                env=environment,
                close_fds=True,
                start_new_session=True,
            )
        except OSError as error:
            raise EngineError(
                f"juphyter: could not run {self.php} {self.engine} ({error}). "
                "The kernelspec's JUPHYTER_PHP and JUPHYTER_ENGINE name the PHP "
                "binary and the engine to run; reinstall the kernel with "
                "`juphyter install` if either has moved."
            ) from error

    def _accept(self) -> None:
        assert self._server is not None

        try:
            connection, _ = self._server.accept()
        except OSError as error:
            raise EngineError(self._never_connected(error)) from error

        connection.settimeout(None)
        self._connection = connection

        self._threads = [
            threading.Thread(target=self._read_events, name="juphyter-events", daemon=True),
            threading.Thread(
                target=self._pump,
                args=(self._process.stdout, "stdout"),  # type: ignore[union-attr]
                name="juphyter-stdout",
                daemon=True,
            ),
            threading.Thread(
                target=self._pump,
                args=(self._process.stderr, "stderr"),  # type: ignore[union-attr]
                name="juphyter-stderr",
                daemon=True,
            ),
        ]

        for thread in self._threads:
            thread.start()

    def _never_connected(self, error: OSError) -> str:
        """Why the engine did not connect, with whatever it managed to say."""
        process = self._process
        status = process.poll() if process is not None else None

        if status is None:
            detail = f"it was still running after {self.CONNECT_TIMEOUT:g} seconds"
        else:
            detail = f"it exited with status {status}"

        # The pumps are not running yet, so the child's stderr is read here.
        # It is a pipe with a fixed buffer, but the engine writes at most a
        # line or two before connecting, and the process has already gone.
        complaint = ""

        if process is not None and process.stderr is not None:
            try:
                complaint = process.stderr.read(self.STDERR_QUOTE).decode("utf-8", "replace")
            except OSError:
                complaint = ""

        message = (
            f"juphyter: the PHP engine never connected to {self._path}: {detail} "
            f"({error})."
        )

        return message + ("\n" + complaint.strip() if complaint.strip() else "")

    def _watch_from_outside(self) -> None:
        """Start the process that ends the engine if this kernel is killed.

        Everything else here depends on this process running. A front end
        kills a kernel that has stopped answering, and nothing registered in a
        process that has been SIGKILLed runs. Two cases are covered without
        help: an idle engine reaches end of input on the socket when this
        process dies, and a kernel that is asked to stop, or is sent SIGTERM,
        reaps the engine itself. What is left is a cell that does not finish —
        `while (true) {}` — because the engine reads the socket only between
        requests and so never learns that there is nobody to answer. It would
        hold a core for as long as the machine stayed up.

        So the watch is kept by a third process, started here and reaped by
        :meth:`stop`. Its parent is this kernel, and a process whose parent
        dies is reparented at once, to init or to launchd, whether or not
        anybody has collected the parent's exit status. `os.getppid()` changing
        is therefore an immediate and unambiguous report that this kernel has
        gone, which asking after the kernel's own process id is not: an unreaped
        process still has one.

        It holds no socket, no pipe and nothing of this process (`close_fds` is
        the default and the three standard descriptors go to os.devnull), it
        runs in isolated mode so that the notebook's own directory is not on
        its import path, it sits outside this kernel's process group so that a
        front end's group-wide kill cannot take it along, and it costs one
        wakeup every :attr:`WATCHDOG_INTERVAL` seconds. The engine is ended with SIGKILL,
        which no cell can catch and no loop can outrun; by then there is nobody
        left to report a tidier ending to.
        """
        process = self._process

        if process is None:
            return

        try:
            self._watchdog = subprocess.Popen(
                [
                    sys.executable,
                    "-I",
                    "-c",
                    _WATCHDOG,
                    str(process.pid),
                    str(self.WATCHDOG_INTERVAL),
                ],
                stdin=subprocess.DEVNULL,
                stdout=subprocess.DEVNULL,
                stderr=subprocess.DEVNULL,
                # In a session of its own, because a front end kills a kernel
                # by signalling its process group: a watchdog inside that group
                # would die alongside the kernel it is watching for, which is
                # the one moment it is needed.
                start_new_session=True,
            )
        except OSError as error:
            # Worth running without, and worth saying. The kernel works; a
            # runaway cell would outlive a kernel that was killed outright.
            self.log.warning(
                "juphyter: could not start the watchdog process (%s). A cell that does "
                "not finish could outlive this kernel.",
                error,
            )

    def _read_events(self) -> None:
        """Queue every event the engine sends, until end of input."""
        connection = self._connection
        assert connection is not None

        try:
            with connection.makefile("rb") as lines:
                for line in lines:
                    text = line.strip()

                    if not text:
                        continue

                    self._events.put(self._decode(text))
        except OSError:
            # The socket was closed under us, which is how a stop ends this
            # thread and is not worth reporting.
            pass
        finally:
            self._finished = True
            self._events.put({"id": None, "type": _END_OF_INPUT})

    def _decode(self, line: bytes) -> dict[str, Any]:
        """One line as the event it carries, or a complaint about the channel."""
        try:
            event = json.loads(line)
        except ValueError as error:
            complaint = str(error)
        else:
            if isinstance(event, dict):
                return event

            complaint = "the line was not a JSON object"

        return {
            "id": None,
            "type": "error",
            "ename": "ProtocolError",
            "evalue": f"The kernel could not read a line from the engine: {complaint}.",
            "traceback": [f"ProtocolError: {complaint}"],
        }

    def _pump(self, stream: Any, name: str) -> None:
        """Queue what the engine writes to one of its own descriptors.

        ``read1`` returns as soon as there is anything to return, so output
        appears as the cell produces it rather than when the buffer fills. The
        decoder is incremental because a chunk boundary can fall inside a
        multibyte character.
        """
        decoder = codecs.getincrementaldecoder("utf-8")(errors="replace")

        try:
            while True:
                chunk = stream.read1(65536)

                if not chunk:
                    break

                text = decoder.decode(chunk)

                if text:
                    self._events.put(
                        {"id": None, "type": "stream", "name": name, "text": text}
                    )
        except (OSError, ValueError):
            pass
        finally:
            text = decoder.decode(b"", True)

            if text:
                self._events.put({"id": None, "type": "stream", "name": name, "text": text})

    def send(self, type: str, **fields: Any) -> str:
        """Write one request, and return the id its events will carry."""
        request_id = f"j{next(self._ids)}"
        line = json.dumps({"id": request_id, "type": type, **fields}, ensure_ascii=False)

        with self._writing:
            connection = self._connection

            if connection is None:
                raise EngineError("juphyter: the engine is not connected.")

            try:
                connection.sendall((line + "\n").encode("utf-8"))
            except OSError as error:
                raise EngineError(
                    f"juphyter: could not send a {type} request to the engine ({error})."
                ) from error

        return request_id

    def drain(self, timeout: float) -> list[dict[str, Any]]:
        """Wait for the next event, and take everything queued behind it.

        Returning a batch keeps a cell that prints a great deal to one thread
        hop per batch rather than per line. An empty list means nothing arrived
        within the timeout.
        """
        if self._pending:
            events, self._pending = self._pending, []

            return events

        try:
            events = [self._events.get(timeout=timeout)]
        except queue.Empty:
            return []

        while True:
            try:
                events.append(self._events.get_nowait())
            except queue.Empty:
                return events

    def requeue(self, events: list[dict[str, Any]]) -> None:
        """Hand back events read but not used, to be returned by the next drain.

        A batch can hold the terminal event of one request with output queued
        behind it. That output still belongs to the notebook, so it goes back
        rather than being dropped. Only the one task draining the queue calls
        this, which is why a plain list is enough.
        """
        if events:
            self._pending = events + self._pending

    def interrupt(self) -> bool:
        """Forward SIGINT to the engine. True when it was delivered."""
        process = self._process

        if process is None or process.poll() is not None:
            return False

        try:
            process.send_signal(signal.SIGINT)
        except OSError:
            return False

        return True

    def wait(self, timeout: float) -> int | None:
        """The child's exit status, or None while it is still running."""
        process = self._process

        if process is None:
            return None

        try:
            return process.wait(timeout=timeout)
        except subprocess.TimeoutExpired:
            return None

    def status(self) -> int | None:
        """The child's exit status without waiting for it."""
        process = self._process

        return process.poll() if process is not None else None

    def stop(self, graceful: bool = True) -> None:
        """Ask the engine to stop, then make sure it has. Idempotent.

        Three escalating steps, because the first two are the ones that let the
        engine run its own shutdown handling: a `shutdown` request, then
        closing the socket, which the engine reads as end of input and exits
        on, then SIGTERM and SIGKILL.
        """
        with self._lifecycle:
            if self._stopped:
                return

            self._stopped = True
            process = self._process

            if graceful and process is not None and process.poll() is None:
                try:
                    self.send("shutdown", restart=False)
                except EngineError:
                    pass
                else:
                    self.wait(self.SHUTDOWN_TIMEOUT)

            self._disconnect()

            if process is not None and process.poll() is None:
                self._signal(process, signal.SIGTERM)

                if self.wait(self.TERMINATE_TIMEOUT) is None:
                    self._signal(process, signal.SIGKILL)
                    self.wait(self.TERMINATE_TIMEOUT)

            self._close_pipes()
            self._stop_watchdog()

            for thread in self._threads:
                thread.join(timeout=1.0)

            self._clean_up()
            self._started = False

    def _stop_watchdog(self) -> None:
        """End the watchdog, which has nothing left to watch."""
        watchdog, self._watchdog = self._watchdog, None

        if watchdog is None or watchdog.poll() is not None:
            return

        try:
            watchdog.kill()
            watchdog.wait(timeout=self.TERMINATE_TIMEOUT)
        except (OSError, subprocess.TimeoutExpired):
            pass

    def _signal(self, process: subprocess.Popen[bytes], number: int) -> None:
        """Signal the engine's whole process group, and then the engine itself.

        The engine leads a process group of its own, so this reaches the
        watchdog child it forked as well. The child would notice the engine
        going within its own interval and leave, but there is no reason to let
        it sleep that long when it can be told.
        """
        try:
            os.killpg(os.getpgid(process.pid), number)

            return
        except (OSError, AttributeError):
            pass

        try:
            process.send_signal(number)
        except OSError:
            pass

    def _disconnect(self) -> None:
        connection, self._connection = self._connection, None

        if connection is None:
            return

        try:
            connection.shutdown(socket.SHUT_RDWR)
        except OSError:
            pass

        try:
            connection.close()
        except OSError:
            pass

    def _close_pipes(self) -> None:
        process = self._process

        if process is None:
            return

        for stream in (process.stdout, process.stderr):
            if stream is not None:
                try:
                    stream.close()
                except OSError:
                    pass

    def _clean_up(self) -> None:
        """Remove the socket and the directory it lives in."""
        if self._server is not None:
            try:
                self._server.close()
            except OSError:
                pass

            self._server = None

        if self._directory is not None:
            shutil.rmtree(self._directory, ignore_errors=True)
            self._directory = None
            self._path = None


class JuphyterKernel(Kernel):
    """A Jupyter kernel for PHP, forwarding every request to the engine."""

    implementation = "juphyter"
    implementation_version = "0.1.0"

    #: What `kernel_info` reports when the engine has not answered yet. The
    #: version is deliberately absent rather than guessed: the engine reports
    #: the PHP it is actually running, and this is only ever seen when it could
    #: not be asked.
    language_info: dict[str, object] = {
        "name": "php",
        "mimetype": "application/x-php",
        "file_extension": ".php",
        "pygments_lexer": "php",
        "codemirror_mode": "php",
    }

    banner = "juphyter, a PHP kernel."

    # IPKernelApp builds whatever kernel_class it is given with IPythonKernel's
    # constructor arguments. Declaring the two that only exist there keeps
    # traitlets from warning about them on every start.
    debugpy_stream = TraitAny(allow_none=True)
    user_ns = TraitAny(allow_none=True)

    #: How long to block on the queue before looking at the child again.
    POLL = 1.0

    #: How long to wait, after a terminal error that means the engine is gone,
    #: for the process to actually exit.
    REAP_TIMEOUT = 2.0

    #: How long the reply has to reach the front end before the kernel exits.
    EXIT_DELAY = 0.5

    def __init__(self, **kwargs: Any) -> None:
        super().__init__(**kwargs)

        self._engine = Engine(log=self.log)
        self._kernel_info_loaded = False

        # On the kernel's own death, so a front end that kills the kernel
        # leaves no PHP process and no socket behind. The engine also exits by
        # itself when the socket reaches end of input, which is what covers a
        # SIGKILL that runs none of this.
        atexit.register(self._engine.stop, False)
        self._reap_on_terminate()

    # -- lifecycle ---------------------------------------------------------

    async def engine(self) -> Engine:
        """The running engine, starting it on first use."""
        await asyncio.to_thread(self._engine.start)

        return self._engine

    def _on_interrupt(self, signum: int, frame: Any) -> None:
        """Forward the signal and return, so this process survives it."""
        self._engine.interrupt()

    @contextmanager
    def _interruptible(self) -> Iterator[None]:
        """Hand SIGINT to the engine for the length of one cell.

        The kernelspec asks for ``interrupt_mode: signal``, so the front end's
        stop button arrives as SIGINT to this process. ipykernel keeps SIGINT
        on ``SIG_IGN`` between messages and installs Python's default handler
        for the length of one, which raises ``KeyboardInterrupt`` wherever the
        interpreter happens to be — in the middle of the event loop, since the
        cell is running in another process. Neither disposition forwards
        anything, so the handler goes on here, over ipykernel's, and comes off
        with the cell. Between cells there is nothing to interrupt.
        """
        if threading.current_thread() is not threading.main_thread():
            yield

            return

        try:
            previous = signal.signal(signal.SIGINT, self._on_interrupt)
        except (OSError, ValueError):  # pragma: no cover - not the main thread
            yield

            return

        try:
            yield
        finally:
            try:
                signal.signal(signal.SIGINT, previous)
            except (OSError, ValueError):  # pragma: no cover
                pass

    def _reap_on_terminate(self) -> None:
        """Clean up on SIGTERM, which ends the process without running atexit.

        A front end that cannot shut the kernel down politely sends SIGTERM,
        and the default action for it skips `atexit`. The handler is installed
        only when nothing else has claimed the signal.
        """
        if threading.current_thread() is not threading.main_thread():
            return

        try:
            if signal.getsignal(signal.SIGTERM) is not signal.SIG_DFL:
                return

            signal.signal(signal.SIGTERM, self._on_terminate)
        except (OSError, ValueError):  # pragma: no cover - not the main thread
            pass

    def _on_terminate(self, signum: int, frame: Any) -> None:  # pragma: no cover
        self._engine.stop(graceful=False)
        os._exit(128 + signal.SIGTERM)

    def _exit_soon(self, status: int) -> None:
        """End the kernel once the reply in hand has reached the front end.

        Used when the engine is gone: this process is of no further use, and a
        front end that sees the kernel die offers a restart, which is how a new
        engine is started. The delay gives the reply and the iopub messages
        time to go out, and the exit is immediate rather than an unwinding of
        the event loop, which is not reliable with the engine's threads in this
        state.
        """
        try:
            loop = asyncio.get_running_loop()
        except RuntimeError:  # pragma: no cover - always called from a hook
            self._die(status)

            return

        loop.call_later(self.EXIT_DELAY, self._die, status)

    def _die(self, status: int) -> None:
        self.log.warning("juphyter: the PHP engine is gone, so the kernel is exiting.")
        self._engine.stop(graceful=False)
        os._exit(status)

    # -- requests ----------------------------------------------------------

    async def do_execute(  # noqa: PLR0913 - the signature is ipykernel's
        self,
        code: str,
        silent: bool,
        store_history: bool = True,
        user_expressions: dict[str, Any] | None = None,
        allow_stdin: bool = False,
        *,
        cell_meta: dict[str, Any] | None = None,
        cell_id: str | None = None,
    ) -> dict[str, Any]:
        """Run one cell, publishing everything the engine reports about it."""
        try:
            engine = await self.engine()
            request = engine.send(
                "execute", code=code, silent=silent, store_history=store_history
            )
        except EngineError as error:
            return self._engine_unavailable(error)

        with self._interruptible():
            terminal = await self._collect(engine, request, publish=True)

        return await self._execute_reply(engine, terminal)

    async def do_complete(self, code: str, cursor_pos: int) -> dict[str, Any]:
        """Complete the code before the cursor, converting the offsets."""
        empty = {
            "matches": [],
            "cursor_start": cursor_pos,
            "cursor_end": cursor_pos,
            "metadata": {},
            "status": "ok",
        }

        reply = await self._ask(
            "complete", code=code, cursor_pos=to_byte_offset(code, cursor_pos)
        )

        if reply is None or reply.get("type") != "complete_reply":
            return empty

        matches = [str(match) for match in reply.get("matches") or []]
        start = reply.get("cursor_start")
        end = reply.get("cursor_end")

        return {
            "matches": matches,
            "cursor_start": to_character_offset(code, start)
            if isinstance(start, int)
            else cursor_pos,
            "cursor_end": to_character_offset(code, end)
            if isinstance(end, int)
            else cursor_pos,
            "metadata": {},
            "status": "ok",
        }

    async def do_is_complete(self, code: str) -> dict[str, Any]:
        """Say whether the source is finished, and what to indent by."""
        reply = await self._ask("is_complete", code=code)

        if reply is None or reply.get("type") != "is_complete_reply":
            return {"status": "unknown"}

        status = reply.get("status")

        if status not in ("complete", "incomplete", "invalid"):
            return {"status": "unknown"}

        if status != "incomplete":
            return {"status": status}

        indent = reply.get("indent")

        return {"status": status, "indent": indent if isinstance(indent, str) else ""}

    async def kernel_info_request(self, stream: Any, ident: Any, parent: Any) -> None:
        """Answer `kernel_info`, asking the engine what PHP it is running.

        ipykernel reads :attr:`language_info` and :attr:`banner` as plain
        attributes, so the engine is asked before the reply is built rather
        than from inside it. The version in a reply is the version of the PHP
        that will run the cells, which is the whole point of asking.
        """
        await self._load_kernel_info()
        await super().kernel_info_request(stream, ident, parent)

    async def do_shutdown(self, restart: bool) -> dict[str, Any]:
        """Stop the engine, leaving no process, socket or directory behind.

        The engine is asked to stop and then made to: its own `shutdown_reply`
        is not waited for, because its exit is the stronger signal and the
        event is discarded with the rest of the queue. The Jupyter reply
        therefore goes out only once the engine has actually stopped.

        Restarting is the front end's business: it starts a new kernel process,
        which spawns a new engine. Nothing is restarted in place here.
        """
        await asyncio.to_thread(self._engine.stop)

        return {"status": "ok", "restart": restart}

    async def _load_kernel_info(self) -> None:
        if self._kernel_info_loaded:
            return

        reply = await self._ask("kernel_info")

        if reply is None or reply.get("type") != "kernel_info_reply":
            self.banner = (
                "juphyter, a PHP kernel. The PHP engine could not be reached, so this "
                "kernel cannot run cells; see the kernel's log for why."
            )
            self._kernel_info_loaded = True

            return

        language_info = reply.get("language_info")
        banner = reply.get("banner")

        if isinstance(language_info, dict) and language_info.get("name") == "php":
            self.language_info = {
                str(key): value for key, value in language_info.items()
            }

        if isinstance(banner, str) and banner:
            self.banner = banner

        self._kernel_info_loaded = True

    async def _ask(self, type: str, **fields: Any) -> dict[str, Any] | None:
        """Send a request that has one reply, and return it.

        None means the engine could not be reached or died answering, which
        every caller turns into the emptiest valid reply it has. Anything the
        engine printed on the way is still published, because it is output the
        user should see.
        """
        try:
            engine = await self.engine()
            request = engine.send(type, **fields)
        except EngineError as error:
            self.log.warning("juphyter: %s", error)

            # As in _engine_unavailable(): an engine that has been and gone
            # leaves this kernel with nothing to forward to.
            if self._engine.status() is not None:
                self._exit_soon(1)

            return None

        terminal = await self._collect(engine, request, publish=False)

        if terminal.get("type") == _END_OF_INPUT:
            self._exit_soon(1)

            return None

        return terminal

    # -- the queue ---------------------------------------------------------

    async def _collect(
        self, engine: Engine, request: str, publish: bool
    ) -> dict[str, Any]:
        """Drain events until the one that ends this request.

        Returns that terminal event, or an end-of-input marker when the engine
        went away without sending one.
        """
        while True:
            events = await asyncio.to_thread(engine.drain, self.POLL)

            # A child that has gone is the end of this request whether or not
            # the socket said so. The queue was empty for a whole POLL, so
            # nothing is being left behind, and end of input can be missed if
            # anything else is holding a copy of the engine's socket.
            if not events and not engine.alive:
                return {"id": request, "type": _END_OF_INPUT}

            for index, event in enumerate(events):
                if event.get("type") == _END_OF_INPUT:
                    return {"id": request, "type": _END_OF_INPUT}

                identifier = event.get("id")

                if identifier is None:
                    self._publish_unattached(event)

                    continue

                if identifier != request:
                    # An answer to a request nobody is waiting for any more.
                    self.log.debug("juphyter: dropping a late %s event.", event.get("type"))

                    continue

                if publish:
                    self._publish(event)

                if self._is_terminal(event):
                    engine.requeue(events[index + 1 :])

                    return event

    @staticmethod
    def _is_terminal(event: dict[str, Any]) -> bool:
        type = str(event.get("type", ""))

        return type in _TERMINAL or type.endswith("_reply")

    def _publish_unattached(self, event: dict[str, Any]) -> None:
        """Handle an event that answers no request.

        Two kinds arrive. A `stream` is what the engine wrote to its own
        descriptors, which is published as the output it is. An `error` with a
        null id is a complaint about the channel itself, which goes to the log
        and to stderr, because it usually says why nothing else will work.
        """
        type = event.get("type")

        if type == "stream":
            self._publish(event)

            return

        if type == "error":
            message = "{}: {}".format(
                event.get("ename", "Error"), event.get("evalue", "")
            )
            self.log.warning("juphyter: %s", message)
            self._publish({"type": "stream", "name": "stderr", "text": message + "\n"})

            return

        self.log.debug("juphyter: ignoring an unattached %s event.", type)

    def _publish(self, event: dict[str, Any]) -> None:
        """Send one engine event on as the Jupyter message it maps to."""
        type = event.get("type")

        if type == "stream":
            name = event.get("name")
            self.send_response(
                self.iopub_socket,
                "stream",
                {
                    "name": "stderr" if name == "stderr" else "stdout",
                    "text": str(event.get("text", "")),
                },
            )

            return

        if type == "display":
            self.send_response(
                self.iopub_socket,
                "display_data",
                {
                    "data": self._bundle(event),
                    "metadata": self._metadata(event),
                    "transient": {},
                },
            )

            return

        if type == "result":
            self.send_response(
                self.iopub_socket,
                "execute_result",
                {
                    "execution_count": self._count(event),
                    "data": self._bundle(event),
                    "metadata": self._metadata(event),
                },
            )

            return

        if type == "error":
            self.send_response(self.iopub_socket, "error", self._error(event))

    @staticmethod
    def _bundle(event: dict[str, Any]) -> dict[str, Any]:
        bundle = event.get("bundle")

        return bundle if isinstance(bundle, dict) else {}

    @staticmethod
    def _metadata(event: dict[str, Any]) -> dict[str, Any]:
        metadata = event.get("metadata")

        return metadata if isinstance(metadata, dict) else {}

    @staticmethod
    def _count(event: dict[str, Any]) -> int:
        count = event.get("execution_count")

        return count if isinstance(count, int) else 0

    @staticmethod
    def _error(event: dict[str, Any]) -> dict[str, Any]:
        """The three fields a Jupyter `error` message carries."""
        ename = str(event.get("ename", "Error"))
        evalue = str(event.get("evalue", ""))
        traceback = event.get("traceback")

        if isinstance(traceback, list) and traceback:
            lines = [str(line) for line in traceback]
        else:
            lines = [f"{ename}: {evalue}"]

        return {"ename": ename, "evalue": evalue, "traceback": lines}

    # -- replies -----------------------------------------------------------

    async def _execute_reply(
        self, engine: Engine, terminal: dict[str, Any]
    ) -> dict[str, Any]:
        """The `execute_reply` content for a finished cell."""
        type = terminal.get("type")

        if type == _END_OF_INPUT:
            return await self._engine_died(engine)

        if type == "ok":
            # The engine owns the execution count, so the number the front end
            # shows and the number a traceback is labelled with cannot drift.
            self.execution_count = self._count(terminal)

            return {
                "status": "ok",
                "execution_count": self.execution_count,
                "payload": [],
                "user_expressions": {},
            }

        if type == "error":
            error = self._error(terminal)

            # A cell that called exit, or a fatal the engine reported from its
            # shutdown handler, has taken the process with it. The error is
            # relayed first and the kernel then goes, so the front end offers
            # the restart that brings a new engine.
            if error["ename"] in _FATAL_ENAMES or engine.status() is not None:
                self._exit_soon(1)

            return self._error_reply(error)

        # A reply to something else, which cannot happen for an execute unless
        # the engine's own loop is confused. Reported rather than swallowed.
        self.log.warning("juphyter: an execute ended with a %s event.", type)

        return self._error_reply(
            {
                "ename": "ProtocolError",
                "evalue": f"The engine answered an execute request with a {type} event.",
                "traceback": [f"ProtocolError: unexpected {type} event"],
            }
        )

    def _error_reply(self, error: dict[str, Any]) -> dict[str, Any]:
        """An `execute_reply` for a cell that failed.

        The execution count is in it because a front end reads it from every
        execute_reply, erroring or not: jupyter_console raises a KeyError
        without it. The version 5 error structure names four keys, and a
        protocol minor above 5.1 allows others alongside them, which is what
        every 5.4 client sends and reads.
        """
        return {"status": "error", "execution_count": self.execution_count, **error}

    async def _engine_died(self, engine: Engine) -> dict[str, Any]:
        """Report an engine that went away mid-cell, and end the kernel.

        The reliable signal is the child's exit status, not anything the engine
        managed to say on its way out: a cell that exhausts memory, or an
        engine killed outright, may send nothing at all. The status is included
        because it is often the whole diagnosis.
        """
        status = await asyncio.to_thread(engine.wait, self.REAP_TIMEOUT)
        self._exit_soon(1)

        evalue = (
            "The PHP engine stopped while this cell was running, so the cell did not "
            f"finish ({self._describe_status(status)}). The kernel is exiting; restart "
            "it to carry on."
        )
        reply = self._error_reply(
            {
                "ename": "EngineDied",
                "evalue": evalue,
                "traceback": ["EngineDied: " + evalue],
            }
        )
        self._publish({"type": "error", **reply})

        return reply

    @staticmethod
    def _describe_status(status: int | None) -> str:
        if status is None:
            return "it has not reported an exit status"

        if status < 0:
            name = signal.Signals(-status).name

            return f"it was killed by {name}"

        return f"it exited with status {status}"

    def _engine_unavailable(self, error: EngineError) -> dict[str, Any]:
        """Report an engine that could not be started at all."""
        message = str(error)
        self.log.error("juphyter: %s", message)

        # An engine that started and has since gone leaves this kernel with
        # nothing to forward to, and no way back: the front end has to restart
        # it to get a new one. An engine that never started is a different
        # case, and the next cell tries again.
        if self._engine.status() is not None:
            self._exit_soon(1)

        reply = self._error_reply(
            {
                "ename": "EngineError",
                "evalue": message,
                "traceback": ("EngineError: " + message).splitlines(),
            }
        )
        self._publish({"type": "error", **reply})

        return reply
