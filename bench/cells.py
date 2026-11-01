"""B2: per-cell round trip, and formatting a 1,000-row data frame.

    python bench/cells.py [--cells N] [--repeats N] [--frames N] [--out DIR]

Trivial cells: one session per repetition runs N cells of `1;` (`1` in
Python) and the value is the median time per cell. The reps are the
per-session medians.

Data frame: a 1,000-row phibble data frame is built once, then a cell whose
value is that frame runs `--frames` times, which makes the kernel format it
into its MIME bundle each time. Two rows time the same formatting without any
kernel, in one PHP process: juphyter's formatter producing the bundle, and
phibble's own text rendering, `(string) $frame`.

Memory: after the trivial cells, the PHP engine's peak resident set size
(VmHWM from /proc, Linux only) and PHP's allocator peak.
"""

from __future__ import annotations

import argparse
import subprocess
import tempfile
import time
from pathlib import Path

from common import (
    PHP_KERNEL,
    PROJECT,
    PYTHON_KERNEL,
    ROOT,
    Engine,
    Kernel,
    environment,
    median,
    peak_rss_mib,
    php_binary,
    write_record,
)

FRAME = """
$frame = \\Phibble\\DataFrame::fromRows(array_map(
    fn (int $i) => [
        'id' => $i,
        'group' => ['a', 'b', 'c', 'd'][$i % 4],
        'x' => $i * 0.5,
        'y' => sin($i),
        'label' => 'row '.$i,
    ],
    range(1, 1000),
));
"""

PHP_PEAK = "round(memory_get_peak_usage(true) / 1048576, 1);"


def per_cell(run, code: str, cells: int) -> float:
    times = []
    for _ in range(cells):
        start = time.perf_counter()
        run(code)
        times.append((time.perf_counter() - start) * 1000)
    return median(times)


def trivial(repeats: int, cells: int) -> list[dict]:
    empty = Path(tempfile.mkdtemp(prefix="juphyter-bench-empty-"))
    label = f"{cells:,} trivial cells"
    rows = []

    reps, rss, php_peak = [], [], []
    for _ in range(repeats):
        kernel = Kernel(PHP_KERNEL, empty)
        kernel.start()
        per_cell(kernel.execute, "1;", 20)
        reps.append(per_cell(kernel.execute, "1;", cells))
        rss.append(peak_rss_mib(kernel.engine_pid()))
        php_peak.append(float(kernel.execute(PHP_PEAK)["text/plain"]))
        kernel.stop()
    rows.append(row(label, "juphyter kernel", reps, rss, php_peak))

    reps, php_peak = [], []
    for _ in range(repeats):
        engine = Engine(empty)
        engine.start()
        per_cell(engine.execute, "1;", 20)
        reps.append(per_cell(engine.execute, "1;", cells))
        php_peak.append(float(engine.execute(PHP_PEAK)["text/plain"]))
        engine.stop()
    rows.append(row(label, "PHP engine alone", reps, None, php_peak))

    reps = []
    for _ in range(repeats):
        kernel = Kernel(PYTHON_KERNEL, empty)
        kernel.start()
        per_cell(kernel.execute, "1", 20)
        reps.append(per_cell(kernel.execute, "1", cells))
        kernel.stop()
    rows.append(row(label, "python3 ipykernel", reps, None, None))
    return rows


def frames(repeats: int, count: int) -> list[dict]:
    case = "1,000-row data frame"
    rows = []

    reps = []
    for _ in range(repeats):
        kernel = Kernel(PHP_KERNEL, PROJECT)
        kernel.start()
        kernel.execute(FRAME)
        per_cell(kernel.execute, "$frame;", 3)
        reps.append(per_cell(kernel.execute, "$frame;", count))
        kernel.stop()
    rows.append(row(case, "juphyter kernel", reps, None, None))

    reps = []
    for _ in range(repeats):
        engine = Engine(PROJECT)
        engine.start()
        engine.execute(FRAME)
        per_cell(engine.execute, "$frame;", 3)
        reps.append(per_cell(engine.execute, "$frame;", count))
        engine.stop()
    rows.append(row(case, "PHP engine alone", reps, None, None))

    for implementation, expression in (
        ("juphyter formatter, no kernel", "\\Juphyter\\Display\\Registry::default()->format($frame)"),
        ("phibble text output, no kernel", "(string) $frame"),
    ):
        reps = [in_process(expression, count) for _ in range(repeats)]
        rows.append(row(case, implementation, reps, None, None))
    return rows


def in_process(expression: str, count: int) -> float:
    """The median time of one expression over count runs, in one PHP process."""
    script = f"""
require {str(PROJECT / 'vendor' / 'autoload.php')!r};
require {str(ROOT / 'vendor' / 'autoload.php')!r};
{FRAME}
for ($i = 0; $i < 3; $i++) {{ {expression}; }}
$times = [];
for ($i = 0; $i < {count}; $i++) {{
    $start = hrtime(true);
    {expression};
    $times[] = (hrtime(true) - $start) / 1e6;
}}
sort($times);
$n = count($times);
echo $n % 2 ? $times[intdiv($n, 2)] : ($times[$n / 2 - 1] + $times[$n / 2]) / 2;
"""
    output = subprocess.run([php_binary(), "-r", script], capture_output=True, text=True, check=True).stdout
    return round(float(output), 3)


def row(case: str, implementation: str, reps: list[float], rss: list | None, php_peak: list | None) -> dict:
    entry = {"case": case, "implementation": implementation, "value": median(reps), "reps": reps}
    measured = [value for value in (rss or []) if value is not None]
    if measured:
        entry["peak_rss_mib"] = max(measured)
        entry["reps_peak_rss_mib"] = rss
    if php_peak:
        entry["php_peak_mib"] = max(php_peak)
    print(f"bench: {case}, {implementation}: {entry['value']:.3f} ms")
    return entry


def text(cells: int = 1000, frames: int = 100) -> dict:
    """The record's wording, kept apart from its values so bench/retext.py can apply it."""
    return {
        "title": "Cell round trip",
        "label": "Cell round trip",
        "description": (
            f"Time per cell for {cells:,} trivial cells in one session, and for {frames} cells whose "
            "value is a 1,000-row phibble data frame formatted into its MIME bundle. Formatting is "
            "also timed without a kernel. The text and HTML representations each render the first "
            "10 rows."
        ),
        "workload": f"{cells:,} trivial cells; {frames} cells returning a 1,000-row, 5-column data frame",
        "implementations": {
            "juphyter kernel": "php kernel through jupyter_client; ipykernel transport and PHP engine",
            "PHP engine alone": "php bin/juphyter engine over its Unix socket; no Jupyter",
            "python3 ipykernel": "stock ipykernel Python kernel; cell 1",
            "juphyter formatter, no kernel": "Juphyter\\Display\\Registry::default()->format($frame) in one PHP process",
            "phibble text output, no kernel": "(string) $frame in one PHP process",
        },
        "measure": "Elapsed time per cell",
        "unit": "ms",
        "statistic": "median",
        "method": (
            "Kernels: from execute_request to execute_reply and idle status. PHP engine alone: from "
            "writing the request to the socket to the engine's ok event. No-kernel rows: hrtime around "
            "the expression. Each repetition is a new session with 20 trivial or 3 data-frame warmup "
            "cells. Value: median of the per-session median times. Data-frame construction excluded."
        ),
        "time_spent_in": (
            "PHP engine alone: the PHP process, parsing, running, and formatting the cell. juphyter "
            "kernel minus PHP engine alone: ZeroMQ messaging, ipykernel, and the transport's socket "
            "hop. No-kernel rows: formatting in PHP."
        ),
        "memory_method": (
            "Peak resident set size: VmHWM of the PHP engine process from /proc/<pid>/status after "
            "the trivial cells; engine only, excluding the Python transport and Jupyter. PHP allocator "
            "peak: memory_get_peak_usage(true) in the engine after the trivial cells."
        ),
        "reproduce": ["bench/setup", "bench/run cells"],
        "source": "bench/cells.py",
    }


def main() -> None:
    parser = argparse.ArgumentParser()
    parser.add_argument("--cells", type=int, default=1000)
    parser.add_argument("--frames", type=int, default=100)
    parser.add_argument("--repeats", type=int, default=5)
    parser.add_argument("--out", type=Path)
    arguments = parser.parse_args()

    rows = trivial(arguments.repeats, arguments.cells) + frames(arguments.repeats, arguments.frames)

    record = {
        **text(arguments.cells, arguments.frames),
        "environment": environment("warm", arguments.repeats, None),
        "rows": rows,
    }
    write_record("cell-round-trip", record, arguments.out)


if __name__ == "__main__":
    main()
