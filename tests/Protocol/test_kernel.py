"""The wrapper kernel, over the real Jupyter protocol.

Two layers. `jupyter_kernel_test`'s ``KernelTests`` drives a real kernel over
ZeroMQ and validates every message against the version 5 schema, which is the
conformance floor. The tests after it cover what a conformance suite has no
opinion about: an engine that dies mid-cell, an interrupt, output ordering, a
cell that prints megabytes, the cursor offsets either side of a multibyte
character, and that a shutdown leaves no process and no temporary directory.

The kernel under test is started from the kernelspec `juphyter install` writes,
into a temporary ``JUPYTER_DATA_DIR``, so these tests exercise the installer's
own output and never touch the real Jupyter data directory.

Running them needs Python with `ipykernel` and `jupyter_kernel_test`; without
either, the module skips rather than failing. The PHP binary comes from
``JUPHYTER_TEST_PHP`` and defaults to the ``php`` on ``PATH``.
"""

from __future__ import annotations

import atexit
import json
import os
import shutil
import signal
import subprocess
import sys
import tempfile
import time
from pathlib import Path
from typing import Any

import pytest

jupyter_kernel_test = pytest.importorskip(
    "jupyter_kernel_test",
    reason="The protocol tests need jupyter_kernel_test: "
    "pip install -r tests/Protocol/requirements.txt",
)
pytest.importorskip(
    "ipykernel",
    minversion="7.0",
    reason="The protocol tests need ipykernel 7 or newer: "
    "pip install -r tests/Protocol/requirements.txt",
)

from jupyter_client.manager import start_new_kernel  # noqa: E402

#: The package root, three levels up from this file.
ROOT = Path(__file__).resolve().parents[2]

#: The PHP that will run the cells.
PHP = os.environ.get("JUPHYTER_TEST_PHP", "php")

#: Unique per run, so two runs on one machine cannot collide.
KERNEL_NAME = f"juphyter-protocol-{os.getpid()}"

#: How long to wait for a reply before calling the kernel hung.
TIMEOUT = 60


def install_kernel() -> Path:
    """Install the kernelspec into a temporary Jupyter data directory.

    ``JUPYTER_DATA_DIR`` is set for this process before any kernel manager
    exists, which is both how the installer decides where to write and how
    `jupyter_client` later finds what it wrote. The directory is removed when
    the run ends.
    """
    directory = tempfile.mkdtemp(prefix="juphyter-protocol-")
    atexit.register(shutil.rmtree, directory, ignore_errors=True)
    os.environ["JUPYTER_DATA_DIR"] = directory

    command = [
        PHP,
        str(ROOT / "bin" / "juphyter"),
        "install",
        "--name",
        KERNEL_NAME,
        "--display-name",
        "PHP (juphyter protocol tests)",
        # This interpreter, rather than whichever one owns the jupyter on PATH:
        # it is the one running these tests, and the one with ipykernel in it.
        "--python",
        sys.executable,
    ]

    try:
        result = subprocess.run(command, capture_output=True, text=True, check=False)
    except OSError as error:
        raise RuntimeError(
            f"juphyter's protocol tests could not run {PHP} ({error}). Set "
            "JUPHYTER_TEST_PHP to the PHP binary to test."
        ) from error

    if result.returncode != 0:
        raise RuntimeError(
            "juphyter install failed, so there is no kernelspec to test:\n"
            + (result.stdout + result.stderr).strip()
        )

    return Path(directory) / "kernels" / KERNEL_NAME / "kernel.json"


SPEC_FILE = install_kernel()
SPEC = json.loads(SPEC_FILE.read_text())


def watchdog_interval() -> float:
    """The watchdog's check interval, from the attribute that sets it."""
    sys.path.insert(0, str(ROOT / "kernel"))

    from juphyter_kernel.kernel import Engine

    return float(Engine.WATCHDOG_INTERVAL)


def php_version() -> str:
    """The version of the PHP the kernelspec names."""
    result = subprocess.run(
        [SPEC["env"]["JUPHYTER_PHP"], "-r", "echo PHP_VERSION;"],
        capture_output=True,
        text=True,
        check=True,
    )

    return result.stdout.strip()


class Session:
    """One kernel, with the few protocol moves these tests need."""

    def __init__(self, manager: Any, client: Any) -> None:
        self.manager = manager
        self.client = client

    def execute(self, code: str, **content: Any) -> str:
        return self.client.execute(code, **content)

    def outputs(self, message_id: str, timeout: int = TIMEOUT) -> list[dict[str, Any]]:
        """Every iopub message for one request, up to its idle status."""
        collected: list[dict[str, Any]] = []
        deadline = time.monotonic() + timeout

        while time.monotonic() < deadline:
            message = self.client.get_iopub_msg(timeout=timeout)

            if message["parent_header"].get("msg_id") != message_id:
                continue

            if message["msg_type"] == "status":
                if message["content"]["execution_state"] == "idle":
                    return collected

                continue

            collected.append(message)

        raise AssertionError("the kernel never went idle")

    def reply(self, message_id: str, timeout: int = TIMEOUT) -> dict[str, Any]:
        deadline = time.monotonic() + timeout

        while time.monotonic() < deadline:
            message = self.client.get_shell_msg(timeout=timeout)

            if message["parent_header"].get("msg_id") == message_id:
                return message["content"]

        raise AssertionError("the kernel never replied")

    def run(self, code: str, **content: Any) -> tuple[dict[str, Any], list[dict[str, Any]]]:
        """Run one cell, and return its reply with its iopub messages."""
        message_id = self.execute(code, **content)
        outputs = self.outputs(message_id)

        return self.reply(message_id), outputs

    def wait_for_stream(self, message_id: str, text: str, timeout: int = TIMEOUT) -> None:
        """Wait until a cell has printed something, so it is known to be running."""
        deadline = time.monotonic() + timeout

        while time.monotonic() < deadline:
            message = self.client.get_iopub_msg(timeout=timeout)

            if message["parent_header"].get("msg_id") != message_id:
                continue

            if message["msg_type"] == "stream" and text in message["content"]["text"]:
                return

        raise AssertionError(f"the cell never printed {text!r}")

    def engine(self) -> tuple[int, Path]:
        """The engine's process id and the private directory holding its socket.

        Asked of the engine itself, which is the one process that can answer
        without looking at the process table.
        """
        reply, outputs = self.run('echo getmypid(), "\\n", getenv("JUPHYTER_SOCKET");')

        assert reply["status"] == "ok"

        text = "".join(
            message["content"]["text"]
            for message in outputs
            if message["msg_type"] == "stream"
        )
        pid, socket = text.strip().splitlines()

        return int(pid), Path(socket).parent


def stream_text(outputs: list[dict[str, Any]], name: str) -> str:
    """Everything one stream carried, concatenated."""
    return "".join(
        message["content"]["text"]
        for message in outputs
        if message["msg_type"] == "stream" and message["content"]["name"] == name
    )


@pytest.fixture
def session() -> Any:
    """A kernel of this test's own, shut down afterwards.

    The shutdown is the polite one, through a `shutdown_request`, because that
    is the path a front end takes and the one that has the kernel reap its own
    engine and remove its own temporary directory.
    """
    manager, client = start_new_kernel(kernel_name=KERNEL_NAME)

    try:
        yield Session(manager, client)
    finally:
        client.stop_channels()

        if manager.is_alive():
            manager.shutdown_kernel()


def dead(pid: int, timeout: float = 15.0) -> bool:
    """Whether a process is gone, waiting for it to go."""
    deadline = time.monotonic() + timeout

    while time.monotonic() < deadline:
        try:
            os.kill(pid, 0)
        except ProcessLookupError:
            return True
        except PermissionError:  # pragma: no cover - a pid we cannot signal
            return False

        time.sleep(0.05)

    return False


class JuphyterKernelTests(jupyter_kernel_test.KernelTests):
    """`jupyter_kernel_test`'s conformance suite, against real PHP."""

    kernel_name = KERNEL_NAME

    language_name = "php"
    file_extension = ".php"

    code_hello_world = 'echo "hello, world\n";'

    # A warning, notice or deprecation is stream output on stderr rather than
    # an error, because it did not end the cell.
    code_stderr = 'trigger_error("careful", E_USER_WARNING);'

    code_execute_result = [{"code": "1 + 1;", "result": "2"}]

    code_display_data = [
        {"code": 'Juphyter\\display("shown");', "mime": "text/plain"},
        {"code": 'Juphyter\\display(new ArrayObject([1, 2]));', "mime": "text/plain"},
    ]

    # Nothing is printed, because the suite requires an erroring cell to
    # produce the error message and nothing else.
    code_generate_error = 'throw new RuntimeException("boom");'

    completion_samples = [{"text": "str_repea", "matches": {"str_repeat"}}]

    complete_code_samples = [
        'echo "done";',
        "$x = 1;",
        "if (true) {\n    $x = 1;\n}",
        "function twice(int $n): int { return $n * 2; }",
    ]

    incomplete_code_samples = [
        "if (true) {",
        "function twice(int $n): int {",
        '$x = "unterminated',
    ]

    def test_kernel_info_reports_the_running_php(self) -> None:
        """The version in `language_info` is the engine's, not a guess."""
        self.flush_channels()
        self.kc.kernel_info()
        reply = self.kc.get_shell_msg(timeout=TIMEOUT)
        language_info = reply["content"]["language_info"]

        self.assertEqual(language_info["version"], php_version())
        self.assertIn(php_version(), reply["content"]["banner"])
        self.assertEqual(reply["content"]["implementation"], "juphyter")


def test_the_kernelspec_starts_the_wrapper_as_a_module() -> None:
    """The installed kernelspec is what these tests have been driving."""
    assert SPEC["argv"][1:] == ["-m", "juphyter_kernel", "-f", "{connection_file}"]
    assert SPEC["language"] == "php"
    assert SPEC["interrupt_mode"] == "signal"
    assert Path(SPEC["env"]["PYTHONPATH"], "juphyter_kernel", "kernel.py").is_file()
    assert Path(SPEC["env"]["JUPHYTER_ENGINE"]).is_file()


def test_state_carries_from_one_cell_to_the_next(session: Session) -> None:
    assert session.run("$carried = 41;")[0]["status"] == "ok"

    reply, outputs = session.run("$carried + 1;")

    assert reply["status"] == "ok"
    assert [
        message["content"]["data"]["text/plain"]
        for message in outputs
        if message["msg_type"] == "execute_result"
    ] == ["42"]


def test_a_silent_cell_shows_nothing_but_still_reports_an_error(
    session: Session,
) -> None:
    """`silent` and `store_history` are the engine's to apply, so they go through.

    A silent cell publishes no result and no display, does not advance the
    execution count, and is still allowed to fail out loud.
    """
    assert session.run("$kept = 1;")[0]["execution_count"] == 1

    reply, outputs = session.run("2 + 2;", silent=True, store_history=False)

    assert reply["status"] == "ok"
    assert reply["execution_count"] == 1
    assert [message["msg_type"] for message in outputs] == []

    reply, outputs = session.run(
        'throw new RuntimeException("loud");', silent=True, store_history=False
    )

    assert reply["status"] == "error"
    assert [message["msg_type"] for message in outputs] == ["error"]

    # And the session is where it was, because nothing was stored.
    assert session.run("$kept;")[0]["execution_count"] == 2


def test_an_engine_killed_mid_cell_is_reported_rather_than_hanging(
    session: Session,
) -> None:
    """A cell whose engine is killed outright ends, and the kernel goes with it."""
    pid, _ = session.engine()

    message_id = session.execute('echo "working\n"; usleep(30_000_000);')
    session.wait_for_stream(message_id, "working")

    os.kill(pid, 9)

    reply = session.reply(message_id, timeout=30)

    assert reply["status"] == "error"
    assert reply["ename"] == "EngineDied"
    assert "SIGKILL" in reply["evalue"]

    # And then this kernel is of no further use, so it exits and the front end
    # offers the restart that starts a new engine.
    deadline = time.monotonic() + 30

    while time.monotonic() < deadline and session.manager.is_alive():
        time.sleep(0.1)

    assert not session.manager.is_alive()


def test_an_interrupt_stops_a_running_cell(session: Session) -> None:
    """The stop button reaches the engine, and this kernel survives it."""
    message_id = session.execute(
        'echo "working\n"; for ($i = 0; $i < 60_000; $i++) { usleep(1_000); }'
    )
    session.wait_for_stream(message_id, "working")

    session.manager.interrupt_kernel()

    reply = session.reply(message_id, timeout=30)

    assert reply["status"] == "error"
    assert reply["ename"] == "KeyboardInterrupt"

    # The interrupt ended the cell and nothing else: the same kernel, the same
    # engine and the same session answer the next one.
    assert session.run('echo "after";')[0]["status"] == "ok"


def test_stdout_and_stderr_arrive_in_the_order_they_were_written(
    session: Session,
) -> None:
    reply, outputs = session.run(
        'echo "first\n"; trigger_error("careful", E_USER_WARNING); echo "last\n";'
    )

    assert reply["status"] == "ok"

    streams = [
        (message["content"]["name"], message["content"]["text"])
        for message in outputs
        if message["msg_type"] == "stream"
    ]
    names = [name for name, _ in streams]
    text = "".join(piece for _, piece in streams)

    assert names == ["stdout", "stderr", "stdout"]
    assert text.index("first") < text.index("careful") < text.index("last")


def test_a_cell_that_prints_megabytes_does_not_deadlock(session: Session) -> None:
    """Five megabytes through the channel, and the cell still returns a value."""
    reply, outputs = session.run(
        'for ($i = 0; $i < 50; $i++) { echo str_repeat("x", 100 * 1024); } 42;'
    )

    assert reply["status"] == "ok"
    assert len(stream_text(outputs, "stdout")) == 50 * 100 * 1024
    assert [
        message["content"]["data"]["text/plain"]
        for message in outputs
        if message["msg_type"] == "execute_result"
    ] == ["42"]


def test_what_the_engine_writes_to_its_own_descriptors_is_streamed(
    session: Session,
) -> None:
    """The reason the protocol is not carried on the engine's stdout.

    ``fwrite(STDOUT, ...)`` goes to the real file descriptor, past PHP's output
    buffering, so it reaches the transport as pipe output rather than as an
    event. A megabyte of it is more than a pipe buffer holds, which is what
    makes reading both pipes concurrently rather than in turn the difference
    between output and a deadlock.
    """
    reply, outputs = session.run(
        'fwrite(STDOUT, str_repeat("o", 1024 * 1024));'
        'fwrite(STDERR, str_repeat("e", 1024 * 1024));'
        '"done";'
    )

    assert reply["status"] == "ok"
    assert len(stream_text(outputs, "stdout")) == 1024 * 1024
    assert len(stream_text(outputs, "stderr")) == 1024 * 1024


def test_the_cursor_is_converted_across_a_multibyte_character(
    session: Session,
) -> None:
    """Jupyter counts characters where the engine counts bytes.

    ``echo $äl`` is eight characters and nine bytes. The completion covers the
    partial name, so the reply has to come back as characters 6 to 8; the
    engine's own answer is bytes 6 to 9.
    """
    assert session.run("$älpha = 1;")[0]["status"] == "ok"

    code = "echo $äl"
    message_id = session.client.complete(code, len(code))
    reply = session.reply(message_id)

    assert reply["status"] == "ok"
    assert reply["matches"] == ["älpha"]
    assert (reply["cursor_start"], reply["cursor_end"]) == (6, 8)
    assert code[reply["cursor_start"] : reply["cursor_end"]] == "äl"


def test_a_kernel_killed_outright_leaves_no_engine_running() -> None:
    """The engine exits on end of input, which is what covers a `kill -9`.

    Nothing of ours runs in a process that has been SIGKILLed, so this
    guarantee cannot come from the kernel. It comes from the socket: the
    engine's end of it reaches end of input when the kernel process dies, and
    the engine's loop returns on that. A cell still running at that moment is
    not reading the socket, which is the engine's own watchdog's business and
    the next test's.
    """
    manager, client = start_new_kernel(kernel_name=KERNEL_NAME)
    session = Session(manager, client)
    engine, directory = session.engine()
    kernel = manager.provisioner.process.pid

    try:
        os.kill(kernel, signal.SIGKILL)

        assert dead(engine)
    finally:
        client.stop_channels()

        if manager.is_alive():
            manager.shutdown_kernel(now=True)

        # Nothing was left to remove this, which is the one case where the
        # directory outlives the kernel. It holds no socket by now, the engine
        # having closed it, and is removed here so the test leaves nothing.
        shutil.rmtree(directory, ignore_errors=True)


def test_an_orphaned_engine_in_a_runaway_cell_stops_itself() -> None:
    """A cell that never finishes cannot outlive the kernel either.

    End of input is no help while a cell is running, because the engine only
    reads the socket between requests: a `while (true) {}` cell would spin at
    whatever a core costs for as long as the machine was up. The watchdog
    process is what ends it, by noticing that the kernel that started it has
    gone, and this is the test that says so. The kernel is killed outright,
    which is what a front end does to one that has stopped answering, and
    nothing reaps it here on purpose: an unreaped process still has a process
    id, and the guarantee must not rest on somebody collecting it.
    """
    manager, client = start_new_kernel(kernel_name=KERNEL_NAME)
    session = Session(manager, client)
    engine, directory = session.engine()
    kernel = manager.provisioner.process.pid

    try:
        message_id = session.execute('echo "spinning\n"; while (true) { $i = 1; }')
        session.wait_for_stream(message_id, "spinning")

        # The kernel's whole process group, which is how jupyter_client kills
        # a kernel (LocalProvisioner.kill sends SIGKILL to the group). Anything
        # of ours inside that group goes with it, so this is the kill that
        # matters rather than one aimed at the kernel's own process id.
        os.killpg(os.getpgid(kernel), signal.SIGKILL)

        assert dead(engine, timeout=10 * watchdog_interval())
    finally:
        client.stop_channels()

        if manager.is_alive():
            manager.shutdown_kernel(now=True)

        # As in the test above: a SIGKILLed kernel removes nothing.
        shutil.rmtree(directory, ignore_errors=True)


def test_the_watchdog_leaves_a_long_cell_and_its_sleep_alone(
    session: Session,
) -> None:
    """The watchdog is an orphan check, not a time limit or an interruption.

    The cell sleeps through several checks under a live kernel and has to come
    back having slept all of it. This is the test that keeps the watchdog out
    of the engine's own process: a version that compared the parent process id
    from a repeating SIGALRM handler ended every sleep a cell made, because a
    signal delivered during a libc sleep ends the sleep and PHP does not
    restart it. A cell's `sleep(8)` came back after two seconds with six still
    to go.
    """
    seconds = int(3 * watchdog_interval()) + 1
    engine, _ = session.engine()

    assert session.run("$before = 1;")[0]["status"] == "ok"

    reply, outputs = session.run(
        f"$started = microtime(true); $left = sleep({seconds}); "
        '$after = $before + 1; '
        'echo $left, " ", (int) round(microtime(true) - $started);'
    )

    assert reply["status"] == "ok"

    left, elapsed = stream_text(outputs, "stdout").split()

    # sleep() returns what was left of it, so nothing cut it short.
    assert int(left) == 0
    assert int(elapsed) >= seconds

    # And the same engine is still there, with what the long cell bound.
    assert session.run("$after;")[0]["status"] == "ok"
    assert session.engine()[0] == engine


def test_a_shutdown_leaves_no_engine_and_no_temporary_directory() -> None:
    manager, client = start_new_kernel(kernel_name=KERNEL_NAME)
    session = Session(manager, client)

    pid, directory = session.engine()

    assert directory.is_dir()

    client.stop_channels()
    manager.shutdown_kernel()

    assert dead(pid)
    assert not directory.exists()
    assert not manager.is_alive()
