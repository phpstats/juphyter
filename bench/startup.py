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


def text() -> dict:
    """The record's wording, kept apart from its values so bench/retext.py can apply it."""
    return {
        "title": "Kernel startup",
        "label": "Kernel startup",
        "description": (
            "Time from starting a kernel to the result of its first trivial cell, in an empty "
            "directory and in a Composer project whose autoloader loads phibble, phplyr, and phiz."
        ),
        "workload": "One kernel start and one trivial cell",
        "implementations": {
            "juphyter kernel": "php kernel through jupyter_client; ipykernel transport and PHP engine",
            "PHP engine alone": "php bin/juphyter engine over its Unix socket; no Jupyter",
            "python3 ipykernel": "stock ipykernel Python kernel",
        },
        "measure": "Elapsed time",
        "unit": "ms",
        "statistic": "median",
        "method": (
            "Kernels: from KernelManager.start_kernel() to the first cell's execute_reply and idle "
            "status. PHP engine alone: from spawning the PHP process to the engine's ok event. Kernel "
            "shutdown excluded. Cold cases: page cache emptied with drop_caches before each repetition. "
            "Warm cases: one warmup run."
        ),
        "time_spent_in": (
            "PHP engine alone: the PHP process, including PHP startup, the engine, the autoloader, and "
            "the cell. juphyter kernel minus PHP engine alone: jupyter_client, ipykernel, and the "
            "Python transport."
        ),
        "reproduce": ["bench/setup", "bench/run startup"],
        "source": "bench/startup.py",
    }


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
        **text(),
        "environment": environment(cache, arguments.repeats, None),
        "rows": rows,
    }
    write_record("kernel-startup", record, arguments.out)


if __name__ == "__main__":
    main()
