# Changelog

All notable changes to juphyter are recorded here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the project uses
[semantic versioning](https://semver.org/spec/v2.0.0.html).

## Unreleased

First milestone: a PHP kernel that runs in JupyterLab and in Quarto.

### Added

- **The kernel.** A PHP execution engine behind a Python transport that owns the
  Jupyter protocol. The engine and the transport exchange JSON lines over a unix
  socket, so the engine never computes a Jupyter message and a native PHP
  transport can replace the Python one later without touching execution.
- **Cells with state.** Variables, objects, and declared functions and classes
  carry from one cell to the next. `use` statements accumulate across a session,
  and every cell runs under `declare(strict_types=1)`.
- **Readable errors.** A traceback names the cell and the line the user wrote,
  across cells, instead of `eval()`'d code. Syntax errors, unsupported syntax,
  and redeclarations are refused before anything runs, which keeps four
  otherwise uncatchable compile errors from ending the kernel.
- **Rich display.** phibble tables render as HTML with their column types and as
  phibble's own text output. phiz plots render as Vega-Lite, as HTML, and as
  static SVG where `vl-convert` is installed. A class with `toMimeBundle()` or
  `toHtml()` renders itself. `Juphyter\display()` publishes from the middle of a
  cell.
- **Interrupts.** The front end's stop button ends a running cell and leaves the
  session usable. A cell that calls `exit` or `die` is reported rather than
  disappearing, and an uncatchable fatal error is reported from a shutdown
  handler.
- **Completion.** Session variables, declared functions and classes, and member
  names, by reflection.
- **Quarto.** A `.qmd` naming the kernel in its front matter renders with
  `quarto render`, cell options included.
- **Installation.** `juphyter install` writes a kernelspec, choosing the Python
  interpreter that owns the `jupyter` on `PATH` and verifying `ipykernel` there.
  Several PHP versions install side by side under their own names. A PHAR covers
  installing without a Composer project.
- **Project discovery.** The engine loads the nearest `vendor/autoload.php` by
  walking up from the notebook's directory, overridable with
  `JUPHYTER_AUTOLOAD`, `--autoload`, or a `juphyter.json`.

### Known limitations

A class or function cannot be redeclared, a cell cannot declare a `namespace`,
a closure called from another cell has no line in its traceback, and the kernel
runs on macOS and Linux. See `docs/limitations.qmd` for the full list and the
reason for each.
