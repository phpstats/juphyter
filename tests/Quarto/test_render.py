"""The kernel under a real `quarto render`, asserted on the rendered output.

These are Python tests rather than Pest tests, for two reasons. The suite that
`phpunit.xml` declares collects `tests` recursively by the `Test.php` suffix, so
a Pest test here would join the default `vendor/bin/pest` run, and a render of
the fixture needs Quarto, a Jupyter, a PHP 8.5, and a Composer install of three
packages that juphyter does not depend on. The second reason is that the stack
under test is already driven from Python: Quarto shells out to its jupyter
engine, which runs in a Python interpreter and talks to the kernel over ZeroMQ.
`test_the_kernel_publishes_four_representations_of_a_plot` drives that same
kernel directly with `jupyter_client`, to see the whole MIME bundle of which
Quarto keeps one part.

Everything these tests need is arranged by `conftest.py`, which skips with the
reason when a prerequisite is missing.
"""

from __future__ import annotations

import json
import os
import re
import subprocess
import time
from dataclasses import dataclass
from pathlib import Path

import pytest

from conftest import KERNEL_NAME, ROOT, Kernel, Project, Renderer, normalized, vl_convert

#: Text from the setup cell, which `echo: false` has to keep out of the output.
SETUP_SOURCE = "use Phibble\\DataFrame;"

#: The caption the plot cell sets with `fig-cap`.
CAPTION = "Highway mileage against engine displacement, by vehicle class."

#: The string the fixture echoes and returns. It is 23 characters and 34 bytes.
MULTIBYTE = "Æthelred's résumé — 日本語"


# --------------------------------------------------------------------------
# One render per format, shared by the assertions that read it.
# --------------------------------------------------------------------------


@pytest.fixture(scope="session")
def html(renderer: Renderer) -> str:
    renderer.run("report.qmd", "html")

    return renderer.output("report.html").read_text()


@pytest.fixture(scope="session")
def notebook(renderer: Renderer) -> dict:
    renderer.run("report.qmd", "ipynb")

    return json.loads(renderer.output("report.ipynb").read_text())


def section(html: str, anchor: str) -> str:
    """One `<section>` of the rendered page, by its heading anchor.

    Taken from the opening tag rather than from the first match of the anchor,
    because the table of contents names every section before the sections
    themselves begin.
    """
    opening = html.index('id="{}" class="level'.format(anchor))

    return html[opening : html.index("</section>", opening)]


def code_cells(notebook: dict) -> list[dict]:
    return [cell for cell in notebook["cells"] if cell["cell_type"] == "code"]


def outputs(notebook: dict) -> list[dict]:
    return [output for cell in code_cells(notebook) for output in cell.get("outputs", [])]


def representations(notebook: dict) -> list[list[str]]:
    """The MIME types of each display output, in order."""
    return [
        sorted(output["data"].keys())
        for output in outputs(notebook)
        if output["output_type"] in ("display_data", "execute_result")
    ]


# --------------------------------------------------------------------------
# The kernel the renders run on
# --------------------------------------------------------------------------


def test_the_kernelspec_was_written_by_juphyter_into_a_temporary_directory(
    kernel: Kernel,
) -> None:
    spec = kernel.spec()

    assert spec["language"] == "php"
    assert spec["argv"][1:] == ["-m", "juphyter_kernel", "-f", "{connection_file}"]
    assert Path(spec["env"]["JUPHYTER_ENGINE"]) == ROOT / "bin" / "juphyter"
    assert kernel.spec_directory.is_relative_to(kernel.data_directory)


def test_the_notebook_sits_below_the_project_it_finds_its_packages_in(
    project: Project,
) -> None:
    """The engine's walk up from the notebook's directory is what is exercised."""
    assert project.notebooks.parent == project.root
    assert project.autoloader.is_file()
    assert not (project.notebooks / "vendor").exists()

    for package in ("phibble", "phplyr"):
        assert (project.root / "vendor" / "phpstats" / package).is_dir()

    assert (project.root / "vendor" / "erikwestlund" / "phiz").is_dir()


# --------------------------------------------------------------------------
# HTML
# --------------------------------------------------------------------------


def test_html_renders_the_phibble_frame_as_a_table(html: str) -> None:
    """Three tables: the frame, the summary, and the summary displayed again."""
    assert html.count('class="juphyter-table"') == 3
    assert "A tibble: 234 × 11" in html
    assert "… with 224 more rows" in html
    assert "manufacturer" in html and "&lt;chr&gt;" in html


def test_html_renders_the_phplyr_summary_as_a_table(html: str) -> None:
    """The summary groups 234 rows into the seven classes of the mpg data."""
    assert "A tibble: 7 × 3" in html
    assert "2seater" in html and "subcompact" in html


def test_html_draws_the_plot_from_the_vega_lite_specification(html: str) -> None:
    """Quarto takes `text/html` for HTML output, which is phiz's own embed."""
    assert html.count("data-phiz=") == 1
    assert "vega.github.io/schema/vega-lite/v6.json" in html
    assert "Displacement (L)" in html


def test_html_keeps_the_figure_caption_beside_the_plot(html: str) -> None:
    """The caption is a paragraph rather than a figcaption.

    Quarto wraps an output in a `<figure>` when it chose an image for it. For
    HTML it chooses `text/html`, which is a div it cannot caption, so `fig-cap`
    arrives as a paragraph after the chart. The PDF render of the same cell gets
    the figure, because there Quarto chooses the SVG.
    """
    assert CAPTION in html
    assert "<figure" not in html


def test_html_honors_echo_false(html: str) -> None:
    assert SETUP_SOURCE not in html
    assert "Phiz\\Datasets" not in html


def test_html_carries_state_from_one_cell_to_the_next(html: str) -> None:
    """Two cells read variables that earlier cells bound.

    The summary pipeline reads the frame the setup cell built, and the counts
    below read both the frame and the summary, so the numbers in the rendered
    page are only there if the kernel kept its session between cells.
    """
    assert "A tibble: 7 × 3" in html
    assert "classes' =&gt; 7, 'rows' =&gt; 234" in html


def test_html_keeps_a_multibyte_string_intact(html: str) -> None:
    """The echoed counts and the returned value, both read from the page.

    The value is the dumper's quoted form, where the apostrophe is escaped and
    the multibyte characters are not, so the assertion is on the two ends of
    the string rather than on the literal.
    """
    assert "23 characters, 34 bytes" in html

    result = section(html, "state-and-a-multibyte-string")

    assert "'Æthelred\\'s résumé — 日本語'" in result


def test_html_shows_the_traceback_with_cell_frames(html: str) -> None:
    """`error: true` keeps the failing cell's traceback in the page."""
    assert "DivisionByZeroError" in html
    assert re.search(r"Cell \d+, line \d+", html)
    assert "eval()&#39;d code" not in html


def test_html_shows_both_values_a_cell_displayed(html: str) -> None:
    """`Juphyter\\display()` publishes a bundle part way through a cell."""
    assert section(html, "several-outputs-from-one-cell").count("cell-output-display") == 2


def test_php_cells_are_syntax_highlighted(html: str) -> None:
    """Pandoc highlights PHP cells although no cell carries a `<?php` tag.

    Pandoc's PHP syntax definition expects the opening tag, which a notebook
    cell has no place for, so whether the cells came out highlighted was an
    open question in the plan. They do: Quarto labels the block `sourceCode
    php` and Pandoc fills it with token spans.
    """
    blocks = re.findall(
        r'<code class="sourceCode php">(.*?)</code>', html, flags=re.DOTALL
    )

    assert len(blocks) >= 5

    classes = set(re.findall(r'<span class="(\w+)">', "".join(blocks)))

    # The variable, string, keyword, and operator tokens of PHP's definition.
    assert {"va", "st", "kw", "ot"} <= classes


# --------------------------------------------------------------------------
# ipynb
# --------------------------------------------------------------------------


def test_ipynb_records_the_kernel_and_the_php_that_ran_the_cells(
    notebook: dict, kernel: Kernel
) -> None:
    assert notebook["metadata"]["kernelspec"]["name"] == kernel.name
    assert notebook["metadata"]["kernelspec"]["language"] == "php"

    language = notebook["metadata"]["language_info"]

    assert language["name"] == "php"
    assert language["file_extension"] == ".php"
    assert language["version"].startswith("8.")


def test_ipynb_carries_the_plot_the_stream_output_and_the_plain_text(
    notebook: dict,
) -> None:
    html_outputs = [
        output
        for output in outputs(notebook)
        if output["output_type"] in ("display_data", "execute_result")
        and "text/html" in output["data"]
    ]
    rendered = "".join("".join(output["data"]["text/html"]) for output in html_outputs)

    assert rendered.count("data-phiz=") == 1
    assert "vega.github.io/schema/vega-lite/v6.json" in rendered

    streams = "".join(
        "".join(output["text"])
        for output in outputs(notebook)
        if output["output_type"] == "stream"
    )

    assert "23 characters, 34 bytes" in streams

    plain = [
        "".join(output["data"]["text/plain"])
        for output in outputs(notebook)
        if output["output_type"] in ("display_data", "execute_result")
        and "text/plain" in output["data"]
    ]

    # The dumper quotes a string and escapes the quote inside it, so the
    # multibyte characters are the part that has to survive untouched.
    assert any("Æthelred" in text and "日本語" in text for text in plain)
    assert "['classes' => 7, 'rows' => 234]" in plain


def test_ipynb_holds_one_representation_per_output_rather_than_all_four(
    notebook: dict,
) -> None:
    """Quarto filters the notebook it writes, which the plan did not expect.

    `quarto render --to ipynb` is a conversion of the document rather than the
    notebook that was executed. Quarto runs the same `displayDataMimeType`
    choice it runs for every other format and keeps only what it chose, so the
    plot arrives as `text/html` alone and the Vega-Lite spec and the SVG are
    dropped. The kernel does publish all four, which
    `test_the_kernel_publishes_four_representations_of_a_plot` asserts against
    the kernel itself.
    """
    assert all(len(types) == 1 for types in representations(notebook))


def test_ipynb_loses_an_html_table_the_way_any_kernels_table_is_lost(
    notebook: dict,
) -> None:
    """The phibble tables do not survive Quarto's ipynb conversion.

    Quarto reaches ipynb through pandoc's markdown reader and writer, and an
    HTML `<table>` in a cell output does not come out the other side: the
    output is dropped rather than degraded. A `<p>`, a `<div>` holding text,
    and a `<span>` all survive, and a stock `python3` kernel loses an
    `IPython.display.HTML('<table>...')` output in exactly the same way, so
    this is Quarto's conversion and not something juphyter publishes wrongly.

    The HTML and PDF renders of the same cells do carry the tables. This test
    pins the loss so that a Quarto release which fixes it is noticed here.
    """
    rendered = "".join(
        "".join(output["data"]["text/html"])
        for output in outputs(notebook)
        if output["output_type"] in ("display_data", "execute_result")
        and "text/html" in output["data"]
    )

    assert "juphyter-table" not in rendered
    assert "A tibble" not in rendered
    assert "<table" not in rendered


def test_ipynb_drops_the_error_output_of_a_failing_cell(notebook: dict) -> None:
    """The traceback reaches HTML and PDF but not Quarto's own ipynb.

    The error cell comes out with an empty `outputs` list, for the same reason
    the tables do: this is a document conversion. It is recorded here so that a
    change in either direction is visible.
    """
    assert any(cell["outputs"] == [] for cell in code_cells(notebook))
    assert not [output for output in outputs(notebook) if output["output_type"] == "error"]


# --------------------------------------------------------------------------
# The whole MIME bundle, from the kernel rather than from Quarto
# --------------------------------------------------------------------------


def test_the_kernel_publishes_four_representations_of_a_plot(
    kernel: Kernel, project: Project, renderer: Renderer, svg_available: bool
) -> None:
    """What a front end is offered for one phiz plot.

    Quarto keeps one representation per output, so the four the plan specifies
    cannot be read back out of a rendered document. They are asked of the
    kernel directly, over the protocol, with the working directory set to the
    fixture's notebook directory so that the engine finds phiz by the same walk
    a render uses.
    """
    client = pytest.importorskip(
        "jupyter_client.manager",
        reason="Reading the whole MIME bundle needs jupyter_client: "
        "pip install -r tests/Quarto/requirements.txt",
    )

    manager, connection = client.start_new_kernel(
        kernel_name=kernel.name,
        cwd=str(project.notebooks),
        env=renderer.environment,
    )

    try:
        message = connection.execute(
            'Phiz\\Plot::of(Phiz\\Datasets::mpg())->aes(x: "displ", y: "hwy")->point();'
        )
        bundles = []
        deadline = time.monotonic() + 120

        while time.monotonic() < deadline:
            event = connection.get_iopub_msg(timeout=120)

            if event["parent_header"].get("msg_id") != message:
                continue

            if event["msg_type"] == "status":
                if event["content"]["execution_state"] == "idle":
                    break

                continue

            if event["msg_type"] in ("display_data", "execute_result"):
                bundles.append(event["content"]["data"])

            if event["msg_type"] == "error":
                pytest.fail(
                    "the plot cell failed: {}: {}".format(
                        event["content"]["ename"], event["content"]["evalue"]
                    )
                )

        assert len(bundles) == 1

        types = bundles[0]
        expected = {
            "application/vnd.vegalite.v5+json",
            "text/html",
            "text/plain",
        }

        if svg_available:
            expected.add("image/svg+xml")

        assert set(types) == expected

        # The v5 MIME type carries a v6 spec, which is the newest type
        # JupyterLab's renderer defines. The spec itself says v6.
        spec = types["application/vnd.vegalite.v5+json"]

        assert spec["$schema"].endswith("/vega-lite/v6.json")
        assert spec["mark"]["type"] == "point"

        if svg_available:
            assert "".join(types["image/svg+xml"]).lstrip().startswith("<svg")
    finally:
        connection.stop_channels()

        if manager.is_alive():
            manager.shutdown_kernel()


def test_the_run_says_whether_vl_convert_was_found(
    renderer: Renderer, svg_available: bool
) -> None:
    """Which of the two SVG paths this run took, said rather than left implicit."""
    found = vl_convert(renderer.environment)

    if not svg_available:
        pytest.skip(
            "vl-convert is not on PATH, so phiz publishes no image/svg+xml and "
            "the PDF render has no figure to place. Install the binary from "
            "https://github.com/vega/vl-convert/releases, or set PHIZ_VL_CONVERT."
        )

    assert Path(found).name.startswith("vl-convert")


# --------------------------------------------------------------------------
# PDF
# --------------------------------------------------------------------------


def test_pdf_renders_the_plot_as_a_figure_from_the_static_svg(
    renderer: Renderer, svg_available: bool
) -> None:
    """PDF through Typst, which takes the SVG as it is.

    For LaTeX output Quarto prefers `image/svg+xml` and then needs
    `rsvg-convert` to turn it into a PDF, so `--to pdf` fails on a machine
    without librsvg. Typst ships with Quarto and places an SVG directly, so it
    is the PDF path these tests take and the one the documentation recommends.
    """
    if not svg_available:
        pytest.skip(
            "The PDF figure comes from phiz's static SVG, and vl-convert is not "
            "on PATH to produce one. Install it from "
            "https://github.com/vega/vl-convert/releases."
        )

    renderer.run("report.qmd", "typst")
    pdf = renderer.output("report.pdf")

    assert pdf.is_file()
    assert pdf.read_bytes().startswith(b"%PDF-")

    text = pdf_text(pdf)

    if text is None:
        pytest.skip(
            "pdftotext is not on PATH, so the PDF was produced and checked for "
            "its header and its figure file but not read. Install poppler to "
            "read it."
        )

    collapsed_text = collapsed(text)

    # Typst numbers a captioned figure, which is what the SVG became. The axis
    # title and the legend entries are text inside that SVG, so finding them in
    # the PDF is what says the chart was drawn rather than merely referenced.
    assert "Figure 1: " + CAPTION in collapsed_text
    assert "Displacement (L)" in collapsed_text
    assert "Highway mpg" in collapsed_text
    assert "subcompact" in collapsed_text

    # The frames come through as native tables, which is why their cells are
    # here. Their dimension note and truncation footer do not: those are HTML
    # divs, and a raw HTML block has nowhere to go in Typst output.
    assert "audi" in collapsed_text and "compact" in collapsed_text
    assert "2seater" in collapsed_text
    assert "A tibble" not in collapsed_text
    assert "DivisionByZeroError" in text
    assert MULTIBYTE in collapsed_text


def pdf_text(pdf: Path) -> str | None:
    """The PDF's text through pdftotext, or None when it is not installed."""
    try:
        result = subprocess.run(
            ["pdftotext", str(pdf), "-"],
            capture_output=True,
            text=True,
            check=False,
            timeout=120,
        )
    except (OSError, subprocess.SubprocessError):
        return None

    return result.stdout if result.returncode == 0 else None


def collapsed(text: str) -> str:
    """One line, so a phrase broken across the page's line breaks still matches."""
    return re.sub(r"\s+", " ", text)


# --------------------------------------------------------------------------
# Quarto's kernel daemon
# --------------------------------------------------------------------------


@dataclass
class Daemonized:
    """What two renders against a kept-alive kernel produced."""

    first: str
    second: str
    started_twice: bool
    engines: list[int]
    watchdogs: list[int]
    survivors: list[int]


@pytest.fixture(scope="session")
def daemonized(renderer: Renderer, kernel: Kernel) -> Daemonized:
    """Render twice against a daemonized kernel, then shut the daemon down.

    Quarto only keeps a kernel alive for a language it can write a setup or a
    cleanup cell for. It looks for `lang/<language>` among its own resources and
    then for a `quarto_setup_cell` or `quarto_cleanup_cell` beside the
    kernelspec, and juphyter's install writes neither, so a PHP kernel is
    started fresh for every render. The file is written here, into this run's
    own temporary kernelspec, because the daemon path is what this fixture is
    for. The cell prints the JSON Quarto reads as the kernel's dependencies,
    which is also how it decides whether to restart between renders.

    The daemon is then ended by a one-shot render, which aborts a running
    server before executing. That is deterministic, where waiting out the
    keepalive timeout is not.
    """
    cleanup_cell = kernel.spec_directory / "quarto_cleanup_cell"
    cleanup_cell.write_text('echo json_encode(["php" => PHP_VERSION]);\n')

    try:
        first = renderer.run("report.qmd", "html", "--execute-daemon", "120")
        first_html = renderer.output("report.html").read_text()

        # Longer than the engine's watchdog interval, so the kept-alive engine
        # sits through several of its orphan checks before the second render.
        time.sleep(8)

        second = renderer.run("report.qmd", "html", "--execute-daemon", "120")
        second_html = renderer.output("report.html").read_text()

        engines = engine_processes()
        watchdogs = watchdog_processes(engines)

        # A one-shot render aborts the server first, which is the shutdown path
        # a front end's own "restart" takes.
        renderer.run("report.qmd", "html", "--no-execute-daemon")

        survivors = [pid for pid in engines + watchdogs if not gone(pid)]

        return Daemonized(
            first=first_html,
            second=second_html,
            started_twice="Starting {} kernel".format(KERNEL_NAME) in second.stdout,
            engines=engines,
            watchdogs=watchdogs,
            survivors=survivors,
        )
    finally:
        cleanup_cell.unlink(missing_ok=True)


def test_a_second_render_reuses_the_daemonized_kernel(daemonized: Daemonized) -> None:
    assert not daemonized.started_twice, (
        "the second render started a new kernel, so the daemon was not reused"
    )
    assert daemonized.engines, "no engine was running between the two renders"


def test_the_second_render_produces_the_same_document(daemonized: Daemonized) -> None:
    """Identical once Quarto's per-render cell ids and counts are removed.

    A daemonized kernel keeps its execution counter, so the count on an output
    rises between renders, and Quarto gives every cell a fresh random id. A
    stock `python3` kernel varies both in the same way. What the cells produced
    is the same.
    """
    assert normalized(daemonized.first) == normalized(daemonized.second)


def test_the_daemon_leaves_no_engine_or_watchdog_behind(daemonized: Daemonized) -> None:
    """The engine and its watchdog go when the kept-alive kernel does."""
    assert daemonized.watchdogs, "the engine was running without a watchdog"
    assert daemonized.survivors == [], (
        "these processes outlived the daemon: {}".format(daemonized.survivors)
    )


def test_a_kept_alive_kernel_survives_a_long_idle_period(
    renderer: Renderer, kernel: Kernel
) -> None:
    """Minutes of idleness between two renders, rather than seconds.

    `test_a_second_render_reuses_the_daemonized_kernel` leaves eight seconds
    between its renders, which is several of the engine's watchdog intervals
    and is as long as a test worth running every time should take. Quarto keeps
    a kernel for five minutes by default, so the question this one asks is
    whether an engine idle for that order of time is still there.

    It is, and it was not until the read timeout on the engine's socket was
    disabled: an engine used to exit after `default_socket_timeout` seconds
    without a request, 60 by default, and the next cell failed with a broken
    pipe. `tests/Engine/IdleTest.php` is the fast test for that, against a
    one-second timeout rather than the real one. This one is the slow check
    that the same holds through Quarto's daemon over a realistic period, so it
    is opt-in: `JUPHYTER_QUARTO_IDLE=180` runs it with a three-minute gap.
    Anything above the keepalive Quarto is asked for would expire the daemon
    instead of testing the engine, so the keepalive is set from it.
    """
    seconds = int(os.environ.get("JUPHYTER_QUARTO_IDLE", "0"))

    if seconds <= 0:
        pytest.skip(
            "Set JUPHYTER_QUARTO_IDLE to a number of seconds to leave a "
            "kept-alive kernel idle between two renders. It takes that long, "
            "which is why tests/Engine/IdleTest.php covers the same ground "
            "against a one-second timeout."
        )

    keepalive = seconds + 120
    cleanup_cell = kernel.spec_directory / "quarto_cleanup_cell"
    cleanup_cell.write_text('echo json_encode(["php" => PHP_VERSION]);\n')

    try:
        renderer.run("report.qmd", "html", "--execute-daemon", str(keepalive))
        first = renderer.output("report.html").read_text()
        engines = engine_processes()

        assert engines, "no engine was running after the first render"

        watchdogs = watchdog_processes(engines)

        time.sleep(seconds)

        try:
            # Still there, after sitting idle for the whole period.
            assert engine_processes() == engines

            second = renderer.run("report.qmd", "html", "--execute-daemon", str(keepalive))

            assert "Starting {} kernel".format(KERNEL_NAME) not in second.stdout
            assert normalized(first) == normalized(
                renderer.output("report.html").read_text()
            )
        finally:
            # The daemon is ended whichever way the assertions went, so a
            # failure here does not leave a server and a kernel behind.
            renderer.run("report.qmd", "html", "--no-execute-daemon", check=False)

        assert [pid for pid in engines + watchdogs if not gone(pid)] == []
    finally:
        cleanup_cell.unlink(missing_ok=True)


def processes() -> list[tuple[int, str]]:
    """Every process on this machine, as a pid and a command line."""
    result = subprocess.run(
        ["ps", "-eo", "pid=,command="],
        capture_output=True,
        text=True,
        check=True,
        timeout=120,
    )
    listing = []

    for line in result.stdout.splitlines():
        pid, _, command = line.strip().partition(" ")

        if pid.isdigit():
            listing.append((int(pid), command))

    return listing


def engine_processes() -> list[int]:
    """The engines started from this checkout, which no other run can share."""
    engine = str(ROOT / "bin" / "juphyter")

    return [pid for pid, command in processes() if engine in command and " engine" in command]


def watchdog_processes(engines: list[int]) -> list[int]:
    """The watchdogs guarding those engines, found by the pid in their argv."""
    wanted = {str(pid) for pid in engines}

    return [
        pid
        for pid, command in processes()
        if "os.kill(engine" in command and set(command.split()[-2:]) & wanted
    ]


def gone(pid: int, timeout: float = 20.0) -> bool:
    """Whether a process has exited, waiting for it to."""
    deadline = time.monotonic() + timeout

    while time.monotonic() < deadline:
        if not any(pid == running for running, _ in processes()):
            return True

        time.sleep(0.2)

    return False


# --------------------------------------------------------------------------
# The other way of naming the kernel
# --------------------------------------------------------------------------


def test_the_explicit_kernelspec_front_matter_needs_the_spec_directory(
    renderer: Renderer, kernel: Kernel
) -> None:
    """The second form of the front matter, and the field the plan's example lacks.

    `jupyter: <name>` resolves a name against the installed kernelspecs and
    fills in everything else, including the directory the kernelspec was found
    in. A hand-written `kernelspec` block does not, and Quarto's jupyter engine
    reads that directory when it looks beside the kernelspec for a
    `quarto_setup_cell`. It only looks there for a language it has no
    `lang/<language>` resources of its own for, which is every language it does
    not ship support for, so the block fails for PHP with `KeyError: 'path'`
    where the same block works for Python.

    Adding `path` to the block is the whole fix. Both halves are rendered here,
    because a documented form that nobody renders is a form that stops working
    quietly.
    """
    without_path = renderer.run("kernelspec.qmd", "html", check=False)

    assert without_path.returncode != 0
    assert "path" in without_path.stdout + without_path.stderr

    document = renderer.notebooks / "kernelspec-with-path.qmd"
    document.write_text(
        (renderer.notebooks / "kernelspec.qmd")
        .read_text()
        .replace(
            '    display_name: "PHP (juphyter render tests)"',
            '    display_name: "PHP (juphyter render tests)"\n'
            '    path: "{}"'.format(kernel.spec_directory),
        )
    )

    renderer.run("kernelspec-with-path.qmd", "html")
    html = renderer.output("kernelspec-with-path.html").read_text()

    assert "2 fields, 10 characters" in html
    assert 'class="sourceCode php"' in html
