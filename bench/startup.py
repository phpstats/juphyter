"""B1: kernel startup to the first result.

    python bench/startup.py [--repeats N] [--cache warm|cold|both] [--out DIR]

Times, for each implementation, the interval from starting the kernel to
holding the result of its first trivial cell: `1;` in PHP, `1` in Python. A
juphyter kernel starts its PHP engine on the first cell, so that interval
covers the whole start.

Two working directories: an empty one, where the engine finds no autoloader,
and build/bench/project, a Composer project with phibble, phplyr, and phiz,
whose vendor/autoload.php the engine loads before the first cell.

A cold run empties the page cache before every repetition, which needs Linux
and passwordless sudo.
"""

from __future__ import annotations

import argparse
import tempfile
import time
from pathlib import Path

from common import (
    PHP_KERNEL,
    PROJECT,
    PYTHON_KERNEL,
    Engine,
    Kernel,
    drop_caches,
    environment,
    median,
    write_record,
)


def time_kernel(name: str, cwd: Path, code: str) -> float:
    kernel = Kernel(name, cwd)
    start = time.perf_counter()
    kernel.start()
    kernel.execute(code)
    elapsed = (time.perf_counter() - start) * 1000
    kernel.stop()
    return elapsed


def time_engine(cwd: Path) -> float:
    engine = Engine(cwd)
    start = time.perf_counter()
    engine.start()
    engine.execute("1;")
    elapsed = (time.perf_counter() - start) * 1000
    engine.stop()
    return elapsed


def measure(repeats: int, cold: bool) -> list[dict]:
    empty = Path(tempfile.mkdtemp(prefix="juphyter-bench-empty-"))
    plans = [
        ("no autoloader", "juphyter kernel", lambda: time_kernel(PHP_KERNEL, empty, "1;")),
        ("no autoloader", "PHP engine alone", lambda: time_engine(empty)),
        ("no autoloader", "python3 ipykernel", lambda: time_kernel(PYTHON_KERNEL, empty, "1")),
        ("phibble, phplyr, and phiz autoloaded", "juphyter kernel", lambda: time_kernel(PHP_KERNEL, PROJECT, "1;")),
        ("phibble, phplyr, and phiz autoloaded", "PHP engine alone", lambda: time_engine(PROJECT)),
    ]
    label = "cold" if cold else "warm"
    rows = []
    for case, implementation, timer in plans:
        if not cold:
            timer()  # one warmup, which also fills the page cache
        reps = []
        for _ in range(repeats):
            if cold:
                drop_caches()
            reps.append(round(timer(), 3))
        rows.append({"case": f"{case}, {label} cache", "implementation": implementation, "value": median(reps), "reps": reps})
        print(f"bench: {case}, {label}, {implementation}: {median(reps):.1f} ms")
    return rows


def main() -> None:
    parser = argparse.ArgumentParser()
    parser.add_argument("--repeats", type=int, default=20)
    parser.add_argument("--cache", choices=["warm", "cold", "both"], default="both")
    parser.add_argument("--out", type=Path)
    arguments = parser.parse_args()

    rows = []
    if arguments.cache in ("warm", "both"):
        rows += measure(arguments.repeats, cold=False)
    if arguments.cache in ("cold", "both"):
        rows += measure(arguments.repeats, cold=True)

    cache = {"warm": "warm", "cold": "cold", "both": {"warm cases": "warm", "cold cases": "cold"}}[arguments.cache]
    record = {
        "title": "Kernel startup: from start to the first result",
        "label": "Kernel startup",
        "description": (
            "Starts a kernel, runs one trivial cell, and stops the clock when the cell's reply and "
            "idle status have arrived. The juphyter kernel starts its PHP engine on that first cell. "
            "The PHP engine alone row spawns the same engine and runs the same cell over its socket, "
            "with no Jupyter. python3 ipykernel is a stock Python kernel started the same way."
        ),
        "workload": "One kernel start and one trivial cell (1;), in an empty directory and in a Composer project",
        "implementations": {
            "juphyter kernel": "the php kernel through jupyter_client: ipykernel transport plus the PHP engine",
            "PHP engine alone": "php bin/juphyter engine, driven over its Unix socket",
            "python3 ipykernel": "a stock ipykernel Python kernel, as the Jupyter baseline",
        },
        "measure": "Elapsed time",
        "unit": "ms",
        "statistic": "median",
        "method": (
            "juphyter kernel and python3 ipykernel: from KernelManager.start_kernel() to receiving the "
            "first cell's execute_reply and its idle status. PHP engine alone: from spawning the PHP "
            "process to its ok event for the cell. Stopping the kernel is excluded. A cold case empties "
            "the page cache with drop_caches before each repetition; a warm case runs one warmup first."
        ),
        "time_spent_in": (
            "The PHP engine alone row is the PHP process: starting PHP, juphyter's engine, loading the "
            "autoloader, and the cell. The juphyter kernel row adds Jupyter and the Python transport, so "
            "the difference between the two rows is spent in jupyter_client, ipykernel, and the transport."
        ),
        "environment": environment(cache, arguments.repeats, None),
        "rows": rows,
        "reproduce": ["bench/setup", "bench/run startup"],
        "source": "bench/startup.py",
    }
    write_record("kernel-startup", record, arguments.out)


if __name__ == "__main__":
    main()
