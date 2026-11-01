"""Shared pieces of the juphyter benchmarks: kernels, the bare engine, and records.

The benchmarks compare three ways of running a cell:

- "juphyter kernel": the php kernel started by jupyter_client, which runs the
  Python transport (ipykernel) and the PHP engine it spawns.
- "PHP engine alone": the same PHP engine, spawned and driven over its Unix
  socket directly, with no Jupyter and no Python transport. The difference
  between the two rows is the time spent in Jupyter and the transport.
- "python3 ipykernel": a stock Python kernel, as a baseline for the Jupyter
  side of the cost.
"""

from __future__ import annotations

import datetime
import json
import os
import platform
import socket
import statistics
import subprocess
import sys
import tempfile
import time
from pathlib import Path
from typing import Any

from jupyter_client.manager import KernelManager

ROOT = Path(__file__).resolve().parent.parent
BUILD = ROOT / "build" / "bench"
JUPYTER_DATA = BUILD / "jupyter"
PROJECT = BUILD / "project"
PHP_KERNEL = "bench-php"
PYTHON_KERNEL = "bench-python3"


def php_binary() -> str:
    return os.environ.get("JUPHYTER_BENCH_PHP", "php")


# -- kernels -----------------------------------------------------------------


class Kernel:
    """A kernel started through jupyter_client, with a blocking client."""

    def __init__(self, name: str, cwd: Path) -> None:
        os.environ["JUPYTER_DATA_DIR"] = str(JUPYTER_DATA)
        self.manager = KernelManager(kernel_name=name)
        self.cwd = cwd
        self.client = None

    def start(self) -> None:
        self.manager.start_kernel(cwd=str(self.cwd))
        self.client = self.manager.client()
        self.client.start_channels()
        self.client.wait_for_ready(timeout=120)

    def execute(self, code: str, timeout: float = 120) -> dict[str, Any]:
        """Run a cell and return once its reply and its idle status are in.

        Returns the execute_result's data, or an empty dict.
        """
        assert self.client is not None
        message_id = self.client.execute(code, store_history=True)
        result: dict[str, Any] = {}
        replied = idle = False
        deadline = time.monotonic() + timeout
        while not (replied and idle):
            remaining = deadline - time.monotonic()
            if remaining <= 0:
                raise TimeoutError(f"no reply to {code!r}")
            if not replied:
                try:
                    reply = self.client.get_shell_msg(timeout=0.0 if idle else remaining)
                except Exception:
                    reply = None
                if reply and reply["parent_header"].get("msg_id") == message_id:
                    if reply["content"]["status"] != "ok":
                        raise RuntimeError(f"cell failed: {reply['content']}")
                    replied = True
                    continue
            if not idle:
                try:
                    message = self.client.get_iopub_msg(timeout=min(remaining, 0.05))
                except Exception:
                    continue
                if message["parent_header"].get("msg_id") != message_id:
                    continue
                kind = message["header"]["msg_type"]
                if kind == "execute_result":
                    result = message["content"]["data"]
                elif kind == "error":
                    raise RuntimeError(f"cell failed: {message['content']}")
                elif kind == "status" and message["content"]["execution_state"] == "idle":
                    idle = True
        return result

    def engine_pid(self) -> int | None:
        """The PHP engine process under the kernel, on Linux."""
        provisioner = self.manager.provisioner
        kernel = getattr(getattr(provisioner, "process", None), "pid", None)
        if kernel is None:
            return None
        for child in children(kernel):
            command = Path(f"/proc/{child}/cmdline")
            if command.is_file() and b"\0engine" in command.read_bytes():
                return child
        return None

    def stop(self) -> None:
        if self.client is not None:
            self.client.stop_channels()
        if self.manager.is_alive():
            self.manager.shutdown_kernel(now=False)


def children(pid: int) -> list[int]:
    found = []
    for task in Path(f"/proc/{pid}/task").glob("*"):
        file = task / "children"
        if file.is_file():
            found += [int(part) for part in file.read_text().split()]
    return found


def peak_rss_mib(pid: int | None) -> float | None:
    """VmHWM, the peak resident set size, from /proc. None off Linux."""
    if pid is None:
        return None
    status = Path(f"/proc/{pid}/status")
    if not status.is_file():
        return None
    for line in status.read_text().splitlines():
        if line.startswith("VmHWM:"):
            return round(int(line.split()[1]) / 1024, 1)
    return None


# -- the bare engine ---------------------------------------------------------


class Engine:
    """The PHP engine driven over its socket, the way the Python transport does."""

    def __init__(self, cwd: Path) -> None:
        self.cwd = cwd
        self.ids = 0

    def start(self) -> None:
        self.directory = tempfile.mkdtemp(prefix="juphyter-bench-")
        path = os.path.join(self.directory, "engine.sock")
        server = socket.socket(socket.AF_UNIX, socket.SOCK_STREAM)
        server.bind(path)
        server.listen(1)
        server.settimeout(60)
        self.process = subprocess.Popen(
            [php_binary(), str(ROOT / "bin" / "juphyter"), "engine"],
            cwd=str(self.cwd),
            env=dict(os.environ, JUPHYTER_SOCKET=path),
            stdin=subprocess.DEVNULL,
            stdout=subprocess.DEVNULL,
            stderr=subprocess.DEVNULL,
        )
        self.connection, _ = server.accept()
        server.close()
        self.connection.settimeout(120)
        self.lines = self.connection.makefile("rb")

    def execute(self, code: str) -> dict[str, Any]:
        """Run a cell and return its result bundle once the engine says it is done."""
        self.ids += 1
        request = f"b{self.ids}"
        line = json.dumps({"id": request, "type": "execute", "code": code, "silent": False, "store_history": True})
        self.connection.sendall((line + "\n").encode())
        bundle: dict[str, Any] = {}
        for raw in self.lines:
            event = json.loads(raw)
            if event.get("id") != request:
                continue
            if event["type"] == "result":
                bundle = event["bundle"]
            elif event["type"] == "error":
                raise RuntimeError(f"cell failed: {event}")
            elif event["type"] == "ok":
                return bundle
        raise RuntimeError("the engine closed the socket")

    def stop(self) -> None:
        self.lines.close()
        try:
            self.connection.shutdown(socket.SHUT_RDWR)
        except OSError:
            pass
        self.connection.close()
        try:
            self.process.wait(10)
        except subprocess.TimeoutExpired:
            self.process.kill()
            self.process.wait()


# -- page cache --------------------------------------------------------------


def drop_caches() -> None:
    """Empty the page cache, on Linux with passwordless sudo, or fail loudly."""
    if not Path("/proc/sys/vm/drop_caches").exists():
        raise RuntimeError("a cold run needs Linux's /proc/sys/vm/drop_caches")
    subprocess.run(["sync"], check=True)
    subprocess.run(
        ["sudo", "-n", "sh", "-c", "echo 3 > /proc/sys/vm/drop_caches"],
        check=True,
    )


# -- records -----------------------------------------------------------------


def run(*command: str) -> str:
    try:
        return subprocess.run(command, capture_output=True, text=True, check=True).stdout.strip()
    except (OSError, subprocess.CalledProcessError):
        return ""


def environment(cache: str | dict, repeats: int, warmups: int) -> dict[str, Any]:
    from importlib.metadata import version

    cpu = None
    memory_gb = None
    if Path("/proc/cpuinfo").is_file():
        for line in Path("/proc/cpuinfo").read_text().splitlines():
            if line.startswith("model name"):
                cpu = line.split(":", 1)[1].strip()
                break
        for line in Path("/proc/meminfo").read_text().splitlines():
            if line.startswith("MemTotal:"):
                memory_gb = round(int(line.split()[1]) / 1024 / 1024, 1)
    elif sys.platform == "darwin":
        cpu = run("sysctl", "-n", "machdep.cpu.brand_string") or None
        memory = run("sysctl", "-n", "hw.memsize")
        memory_gb = round(int(memory) / 1024**3, 1) if memory else None

    return {
        "cpu": cpu,
        "cores": os.cpu_count(),
        "memory_gb": memory_gb,
        "os": os_name(),
        "php": run(php_binary(), "-r", "echo PHP_VERSION;") or None,
        "versions": {
            "juphyter": run("git", "-C", str(ROOT), "describe", "--tags", "--always") or None,
            "Python": platform.python_version(),
            "ipykernel": version("ipykernel"),
            "jupyter_client": version("jupyter_client"),
            "pyzmq": version("pyzmq"),
            **project_versions(),
        },
        "threads": 1,
        "cache": cache,
        "repeats": repeats,
        "warmups": warmups,
        "date": datetime.date.today().isoformat(),
        "commit": run("git", "-C", str(ROOT), "rev-parse", "--short", "HEAD"),
    }


def project_versions() -> dict[str, str]:
    lock = PROJECT / "composer.lock"
    if not lock.is_file():
        return {}
    packages = json.loads(lock.read_text()).get("packages", [])
    return {
        p["name"].split("/")[1]: p["version"].lstrip("v")
        for p in packages
        if p["name"] in ("phpstats/phibble", "phpstats/phplyr", "phpstats/phiz")
    }


def os_name() -> str:
    machine = platform.machine()
    if sys.platform == "darwin":
        return f"macOS {run('sw_vers', '-productVersion')} ({machine})"
    pretty = platform.release()
    release = Path("/etc/os-release")
    if release.is_file():
        for line in release.read_text().splitlines():
            if line.startswith("PRETTY_NAME="):
                pretty = line.split("=", 1)[1].strip('"')
    return f"Linux, {pretty} ({machine})"


def median(values: list[float]) -> float:
    return round(statistics.median(values), 3)


def write_record(slug: str, record: dict[str, Any], directory: Path | None) -> Path:
    """Write site/benchmarks/{slug}.json on Linux, or build/bench/ elsewhere.

    Only Linux runs belong in published records, so a run on any other system
    lands in build/ unless a directory is named explicitly.
    """
    if directory is None:
        directory = ROOT / "site" / "benchmarks" if sys.platform.startswith("linux") else BUILD / "records"
    directory.mkdir(parents=True, exist_ok=True)
    path = directory / f"{slug}.json"
    path.write_text(json.dumps(record, indent=4, ensure_ascii=False) + "\n")
    print(f"bench: wrote {path}")
    return path
