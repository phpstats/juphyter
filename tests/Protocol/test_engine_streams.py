"""The engine's own descriptors against the end of the cell that wrote to them.

What a cell writes with ``fwrite(STDOUT, ...)`` reaches the transport through a
pipe and a reader thread, while the cell's terminal event comes over the
socket. Nothing orders the two, so a terminal event that overtakes the pipe
ends the cell with the last of its output still unread, and that output is
then published after the reply, outside the cell. A reader slowed down here
makes the race lose every time instead of now and then.
"""

from __future__ import annotations

import os
import sys
import time
from pathlib import Path
from typing import Any

ROOT = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(ROOT / "kernel"))

from juphyter_kernel.kernel import Engine  # noqa: E402

PHP = os.environ.get("JUPHYTER_TEST_PHP", "php")
MIB = 1024 * 1024


class SlowStream:
    """A pipe that hands over at most 16 KiB per read, with a pause before each."""

    def __init__(self, stream: Any) -> None:
        self.stream = stream

    def read1(self, size: int) -> bytes:
        time.sleep(0.01)

        return self.stream.read1(min(size, 16384))


class SlowEngine(Engine):
    def _pump(self, stream: Any, name: str) -> None:
        super()._pump(SlowStream(stream), name)


def run_cell(engine: Engine, code: str) -> tuple[dict[str, Any], dict[str, str]]:
    """The cell's terminal event, and what each stream carried before it."""
    request = engine.send("execute", code=code, silent=False, store_history=True)
    streams = {"stdout": "", "stderr": ""}
    deadline = time.monotonic() + 60

    while time.monotonic() < deadline:
        for event in engine.drain(1.0):
            if event.get("type") == "stream" and event.get("id") is None:
                streams[event["name"]] += event["text"]
            elif event.get("id") == request and event.get("type") in ("ok", "error"):
                return event, streams

    raise AssertionError("the cell never finished")


def test_a_cell_ends_only_after_its_descriptor_output_has_been_read() -> None:
    engine = SlowEngine(php=PHP, engine=str(ROOT / "bin" / "juphyter"))
    engine.start()

    try:
        terminal, streams = run_cell(
            engine,
            'fwrite(STDOUT, str_repeat("o", 1024 * 1024));'
            'fwrite(STDERR, str_repeat("e", 1024 * 1024));'
            '"done";',
        )

        assert terminal["type"] == "ok"
        assert len(streams["stdout"]) == MIB
        assert len(streams["stderr"]) == MIB
        assert set(streams["stdout"]) == {"o"}
        assert set(streams["stderr"]) == {"e"}
    finally:
        engine.stop()


def test_the_marker_is_found_across_a_read_boundary_and_never_shown() -> None:
    """16 KiB reads split the output at many points, the marker among them."""
    engine = SlowEngine(php=PHP, engine=str(ROOT / "bin" / "juphyter"))
    engine.start()

    try:
        for length in (16384 - 5, 16384 - 1, 16384, 16384 + 3):
            terminal, streams = run_cell(
                engine, f'fwrite(STDOUT, str_repeat("x", {length})); null;'
            )

            assert terminal["type"] == "ok"
            assert streams["stdout"] == "x" * length
            assert "\0" not in streams["stderr"]
    finally:
        engine.stop()


def test_a_nul_the_cell_writes_itself_is_passed_through() -> None:
    engine = SlowEngine(php=PHP, engine=str(ROOT / "bin" / "juphyter"))
    engine.start()

    try:
        terminal, streams = run_cell(engine, r'fwrite(STDOUT, "a\0b\0"); null;')

        assert terminal["type"] == "ok"
        assert streams["stdout"] == "a\0b\0"
    finally:
        engine.stop()


def test_a_cell_that_closes_stdout_still_ends() -> None:
    engine = SlowEngine(php=PHP, engine=str(ROOT / "bin" / "juphyter"))
    engine.start()

    try:
        started = time.monotonic()
        terminal, streams = run_cell(engine, 'fwrite(STDOUT, "bye"); fclose(STDOUT); null;')

        assert terminal["type"] == "ok"
        assert streams["stdout"] == "bye"
        assert time.monotonic() - started < 5
    finally:
        engine.stop()
