# juphyter: design and first-milestone plan

2026-10-19. Plan/review workspace `2-juphyter-1-plan-review-azure-jaguar`.

juphyter is a Jupyter kernel for PHP, aimed at statistical work with the
phpstats packages and usable from both Jupyter and Quarto. The repository
currently holds a LICENSE and nothing else, so this plan covers the design and
the first milestone together.

## Goal

A PHP kernel that someone can select in JupyterLab or name in a `.qmd` front
matter, which executes cells with persistent state, prints phibble tables the
way phibble prints them, renders phiz plots as figures, installs with one
command, and is covered by tests at the unit, protocol, and document-render
levels.

## Constraints

- PHP 8.3 is the floor, matching phibble and phplyr. phiz requires PHP 8.5, so
  phiz support is conditional on phiz being installed rather than required.
- juphyter must install and run with any subset of the family present,
  including none of it. It therefore has no hard dependency on phibble, phplyr,
  phiz, phit, phpolars, or cmdstanphp.
- Nothing in juphyter may reference a sibling package by relative path, per the
  family's `AGENTS.md`.
- juphyter must not change any sibling package. Changes that would help, such
  as HTML output on phibble tables, are recorded here as proposals for their
  own repositories.
- macOS and Linux only, matching the `os-families` that phit and phpolars
  declare. The kernel relies on `pcntl` and POSIX signals, which the Windows
  PHP build does not provide.
- Testing follows the siblings: Pest 4, run with `vendor/bin/pest`.

## Out of scope for milestone 1

ipywidgets, the Jupyter debugger protocol, `inspect_request` (documentation on
hover), `input_request` (reading from stdin inside a cell), notebook magics, a
native PHP ZeroMQ transport, Windows support, and a JupyterLab mime-renderer
extension for Vega-Lite 6. Each is noted below where the design leaves room
for it.

## What exists today

Four PHP kernels have been attempted. The Coder-Spirit (formerly Litipk)
Jupyter-PHP kernel and its installer were both archived in December 2023.
`rabrennie/jupyter-php-kernel` last tagged a release in 2022.
`wundii/jupyter-php-kernel` is the most recent and requires PHP 8.1 or later
with PsySH 0.12. `psysh_kernel` on PyPI is a MetaKernel-based wrapper around
the PsySH binary.

Every one of the three native kernels speaks ZeroMQ through `ext-zmq`, and that
extension is unmaintained. PECL lists 1.0.8 from 2013 as the last release and
marks the package unmaintained, the PHP manual describes the extension as dead,
the published Windows builds stop at PHP 7.2, and the FreeBSD port expired in
November 2022. A new kernel cannot build on it.

The following facts were measured on this machine rather than assumed, and the
engine tasks should not re-litigate them.

- `declare(strict_types=1);` as the first statement of `eval()`'d code is
  honored. A `TypeError` is raised for a weak argument on PHP 8.3, 8.4, and
  8.5 alike. Strict types are therefore available per cell.
- `use` statements do not survive from one `eval()` to the next, because each
  `eval()` is its own compilation unit. The cleaner must accumulate them and
  re-emit them ahead of every cell.
- `require` of a per-cell temporary file executes in the calling function's
  variable scope, so variables persist there as they do under `eval()`. This
  is the fallback if `eval()` tracebacks prove too coarse.
- JupyterLab's bundled renderer defines `application/vnd.vega.v5+json` and
  Vega-Lite MIME types v3, v4, and v5. `packages/vega5-extension/src/index.ts`
  has no v6 constant. phiz emits Vega-Lite 6.
- Quarto's display-data handling covers `text/html`, images, markdown, LaTeX,
  JSON, and JavaScript, plus the two Jupyter widget MIME types. It has no
  Vega-Lite MIME type anywhere in its bundle, so a Vega-Lite MIME bundle alone
  will not render in a Quarto document.
- Quarto's `kLangCommentChars` table has no `php` entry and falls back to `#`.
  PHP accepts `#` line comments, so `#| echo: false` works in a PHP cell under
  Quarto 1.10.18 as installed.
- Quarto's jupyter engine requires Python 3 and `jupyter`. Its bundle carries
  the strings "Unable to locate an installed version of Python 3" and
  "install jupyter".
- This machine has Quarto 1.10.18, PHP 8.3, 8.4, and 8.5 through Herd with
  FFI, pcntl, posix, sockets, and readline enabled, and neither `jupyter`,
  `jupyter_client`, `libzmq`, nor `vl-convert`. Setting up the test
  prerequisites is part of the first task.

## Design

### Kernel architecture

A Jupyter kernel is a process that binds five ZeroMQ sockets named in a
connection file, signs every message with HMAC-SHA256 under a shared key, and
exchanges JSON messages on a versioned protocol. Two shapes were considered
for PHP.

A **native PHP kernel** would be one PHP process owning all of that. It needs a
ZeroMQ binding, and `ext-zmq` is not usable. The remaining routes are `ext-ffi`
against `libzmq`, which requires users to install `libzmq` through Homebrew or
apt; a pure-PHP implementation of the ZMTP 3.1 handshake and ROUTER, PUB, and
REP framing over `ext-sockets`, which requires nothing but reimplements a wire
protocol; or a small Rust host binary distributed through pie as phit and
phpolars are. Each route also leaves juphyter owning channel handling,
heartbeat, message signing, protocol version negotiation, and interrupt
delivery.

A **wrapper kernel** puts the protocol in Python's `ipykernel`, which already
implements all of that correctly, and keeps every PHP-specific concern in a PHP
process behind it. Its usual objection is the Python dependency, and that
objection does not apply here: JupyterLab is a Python application, and Quarto's
jupyter engine requires Python 3 with `jupyter` regardless of which kernel it
drives. Python is present in both target environments before juphyter is
installed.

**Milestone 1 builds the wrapper, with the two halves separated so the native
route stays open.** juphyter is a PHP *engine* that executes cells and produces
MIME bundles, plus a *transport* that speaks to the front end. Milestone 1
ships one transport, a thin `ipykernel.kernelbase.Kernel` subclass. A native
PHP transport can be added later against the same engine without touching
execution or display.

```
  JupyterLab / Quarto
        |  ZeroMQ, Jupyter protocol 5.x
        v
  juphyter_kernel.py        ipykernel.kernelbase.Kernel subclass
        |  JSON lines over a unix domain socket
        v
  php bin/juphyter engine   parse, execute, display, errors
```

The engine runs as a child of the transport. The transport creates a unix
domain socket in a private temporary directory, passes its path in
`JUPHYTER_SOCKET`, spawns the engine, and waits for the engine to connect.

The protocol deliberately does not run over the engine's stdin and stdout.
Anything that writes to the real file descriptors rather than through PHP's
output buffering, such as a C-level write from an extension or an explicit
`fwrite(STDOUT, ...)`, would corrupt a stdout-based protocol. With a separate
socket, the engine's stdout and stderr stay free, and the transport pumps
whatever appears on them as ordinary `stream` output. This is the main reason
for the socket and should not be simplified away.

### The engine and transport contract

One JSON object per line, UTF-8, newline-terminated. JSON escapes newlines, so
line framing is sufficient and keeps the channel readable in a log.

Requests, transport to engine:

```json
{"id": "a1", "type": "execute", "code": "$x = 1;", "silent": false, "store_history": true}
{"id": "a2", "type": "complete", "code": "$frame->fil", "cursor_pos": 12}
{"id": "a3", "type": "is_complete", "code": "if (true) {"}
{"id": "a4", "type": "kernel_info"}
{"id": "a5", "type": "shutdown", "restart": false}
```

Events, engine to transport, each carrying the `id` of the request it answers:

```json
{"id": "a1", "type": "stream", "name": "stdout", "text": "hello\n"}
{"id": "a1", "type": "display", "bundle": {"text/plain": "..."}, "metadata": {}}
{"id": "a1", "type": "result", "execution_count": 3, "bundle": {"text/plain": "1"}, "metadata": {}}
{"id": "a1", "type": "error", "ename": "TypeError", "evalue": "...", "traceback": ["..."]}
{"id": "a1", "type": "ok", "execution_count": 3}
{"id": "a2", "type": "complete_reply", "matches": ["filter"], "cursor_start": 10, "cursor_end": 12}
{"id": "a3", "type": "is_complete_reply", "status": "incomplete", "indent": "    "}
{"id": "a4", "type": "kernel_info_reply", "language_info": {"...": "..."}, "banner": "..."}
```

Each request ends with exactly one terminal event: `ok`, `error`, or the
matching `*_reply`. The transport maps these onto Jupyter messages and owns
`status`, `execute_input`, and all signing. The engine never computes a
Jupyter message.

`kernel_info_reply` carries the `language_info` the front ends need:

```json
{
  "name": "php",
  "version": "8.5.0",
  "mimetype": "application/x-php",
  "file_extension": ".php",
  "pygments_lexer": "php",
  "codemirror_mode": "php"
}
```

### Execution semantics

The execution core is built on `nikic/php-parser` 5, with juphyter's own
cleaner. PsySH's `CodeCleaner` solves overlapping problems but is shaped for a
terminal REPL and brings `symfony/console` and `symfony/var-dumper` with it.

Each cell goes through the cleaner, which produces evaluable source plus the
number of prelude lines it prepended:

1. Parse. A `PhpParser\Error` becomes an `error` event with `ename` of
   `ParseError` and the parser's message and line, and nothing executes.
2. Prepend the prelude: `declare(strict_types=1);` followed by every `use`
   statement the session has accumulated. Measurement above confirms the
   `declare` is honored inside `eval()`.
3. Lift the cell's own `use` statements into the session so later cells see
   them, and remove them from the cell body.
4. Reject a `namespace` declaration with a specific message. Changing namespace
   mid-session breaks name resolution for everything already defined, and
   supporting it is not worth the cost in milestone 1.
5. If the cell's last statement is an expression statement, and that expression
   is not an assignment, `echo`, `print`, or a declaration, rewrite it to
   assign into a reserved variable so the executor can publish the value.
6. If the cell declares a class, interface, trait, enum, or function whose name
   the session has already declared, fail before executing with a message that
   says which name collided and that the kernel must be restarted. PHP cannot
   redeclare either, and a clear refusal beats an uncatchable fatal.

The executor runs the cleaned source with `eval()` inside a method that
`extract()`s the session's variables first and collects
`get_defined_vars()` afterward, minus its own locals. Objects, arrays, scalars,
and resources persist. References and `$this` do not, which matches every other
PHP REPL.

Output and errors:

- `display_errors` is off and `error_reporting` is at `E_ALL`. A
  `set_error_handler` turns warnings, notices, and deprecations into `stream`
  events on `stderr`, so they appear in the notebook without being mistaken for
  a cell result.
- `ob_start` with a callback streams buffered output as `stream` events rather
  than withholding it until the cell ends, so a long-running cell prints as it
  goes.
- A caught `Throwable` becomes an `error` event. The traceback is built from
  `getTrace()`, and every frame whose file is the eval'd cell is rewritten to
  `Cell N` with the prelude line count subtracted from the line number. Getting
  this mapping right is what makes PHP errors usable in a notebook, so it has
  its own tests.
- An uncatchable fatal, such as `E_ERROR` or exhausted memory, is reported from
  a `register_shutdown_function` that inspects `error_get_last()` and writes a
  final `error` event before the process dies. The transport reports it and
  then lets the front end offer a restart.
- Interrupt uses `"interrupt_mode": "signal"` in the kernelspec. The front end
  signals the transport, the transport forwards SIGINT to the engine, and the
  engine, running under `pcntl_async_signals(true)`, throws from its SIGINT
  handler. The executor reports it as an interrupt rather than a crash.

Completion in milestone 1 is reflection-based and deliberately small: session
variables after `$`, declared functions and classes by prefix, and member names
after `->` or `::` when the left side is a variable whose current value the
session holds. Anything harder is a later milestone.

### Rich display

A `MimeBundle` is an ordered map of MIME type to data plus a metadata map. A
`Registry` holds formatters, asks each whether it supports a value, and returns
the first bundle produced. Every bundle includes `text/plain`.

Formatters match on class-name strings through `is_a($value, 'Fully\\Qualified',
false)`, never on an imported class, so juphyter compiles and runs with any
subset of the family installed.

- **phibble tables.** Match `Phibble\Contracts\Table`, which covers
  `DataFrame`, `SqlTable`, and `PolarsTable` alike. `text/plain` is
  `$table->collect()->format()`, which is phibble's own tibble-style printing,
  so notebook output matches what the package prints at a terminal. `text/html`
  is a table juphyter renders from `columns()`, `types()`, and `toRows()` on a
  head of the frame, with the row and column counts and the per-column type
  abbreviations that phibble's header line carries, and a footer stating how
  many rows and columns were not shown.
- **phiz plots.** Match `Phiz\Chart`, the abstract base of both `Phiz\Plot` and
  `Phiz\Compose`. See the next section for the representations.
- **Fallbacks.** Scalars, arrays, and objects that are JSON-serializable get
  `text/plain` from a compact dumper and `application/json` where it helps.
  `Throwable` values rendered as a result, rather than thrown, print their
  class, message, and location.
- **User types.** If a value's class declares `toMimeBundle(): array`, the
  registry uses it. If it declares `toHtml(): string`, the registry uses that
  for `text/html`. This is how phibble can grow HTML output in its own
  repository and have juphyter prefer it without a juphyter release, and how a
  user's own class opts into rich display.

An exported `Juphyter\display($value)` function publishes a bundle mid-cell, so
a cell can show several frames or plots.

### Plot representations

A single phiz plot publishes four representations, and each front end takes the
richest one it can render.

| MIME type | Source | Who renders it |
|---|---|---|
| `application/vnd.vegalite.v5+json` | `$chart->toArray()` | JupyterLab's bundled vega extension |
| `text/html` | `$chart->embed()` | Quarto HTML, nbconvert, classic notebook |
| `image/svg+xml` | `$chart->svg()` when vl-convert is on PATH | Quarto PDF and docx, and any front end without JavaScript |
| `text/plain` | a one-line description of the chart | everything else |

Three facts force this shape. JupyterLab has no Vega-Lite 6 MIME type, so the
spec is published under the v5 type, which is the newest its renderer defines.
JupyterLab does not execute scripts in `text/html` output, so phiz's `embed()`
output cannot be JupyterLab's path. Quarto has no Vega-Lite MIME type at all,
so `text/html` is its only interactive path.

Publishing a Vega-Lite 6 spec under the v5 MIME type carries a real risk that
JupyterLab's bundled Vega-Lite rejects a v6-only construct. The static SVG is
the guarantee against that: when vl-convert is present, a plot renders
everywhere regardless of what the front end's JavaScript supports. The first
milestone must check in JupyterLab which of the two paths actually draws, and
record the answer. Two follow-ups are available if the v5 MIME type proves
unreliable: ask phiz for an option to emit a Vega-Lite 5 `$schema`, or ship a
JupyterLab mime-renderer extension for v6. Both are later milestones.

### Quarto integration

A `.qmd` selects the kernel in its front matter, either by name or with an
explicit kernelspec:

```yaml
---
title: "Penguin body mass"
jupyter: php
---
```

```yaml
---
jupyter:
  kernelspec:
    name: php85
    language: php
    display_name: "PHP 8.5"
---
```

Quarto resolves the name against installed kernelspecs, so `juphyter install`
has to write somewhere Jupyter looks: `JUPYTER_DATA_DIR` when set, then the
paths `jupyter --paths` reports, which are `~/Library/Jupyter/kernels` on macOS
and `~/.local/share/jupyter/kernels` on Linux.

Cell options work without any change on either side. Quarto has no `php` entry
in its comment-character table and falls back to `#`, which PHP accepts as a
line comment, so `#| echo: false` and `#| fig-cap: "..."` parse as PHP
comments and as Quarto cell options at once. If Quarto later adds `php: "//"`,
the prefix becomes `//|`, which PHP also accepts. A one-line upstream pull
request adding `php: "#"` would pin the current behavior, and is worth sending
but not worth waiting for.

Two Quarto behaviors need explicit handling. Quarto daemonizes kernels between
renders by default, so the engine has to survive minutes of idleness and
shut down cleanly on `shutdown_request`; the render test therefore runs twice
to exercise the daemon path, and the documentation notes
`execute: daemon: false` as the escape hatch. Separately, syntax highlighting
of PHP cells is uncertain: Pandoc's PHP syntax definition expects a `<?php`
opening tag, and notebook cells have none, so PHP cells may render
unhighlighted. The first milestone checks this against a real render and, if it
is broken, records the fix rather than guessing at it now.

Nothing about the jupyter engine needs a Quarto extension, and `freeze` works
unchanged. The phpstats documentation sites currently pre-generate their
examples through scripts such as phiz's `scripts/docs-examples.php`, because no
PHP engine existed. Once juphyter works, those sites can execute PHP in `.qmd`
directly. That migration belongs to each package and is not part of this
milestone, but it is the reason the Quarto path matters as much as the Jupyter
one.

### Installation and packaging

```bash
composer require --dev phpstats/juphyter
vendor/bin/juphyter install
```

`juphyter install` accepts `--name`, `--display-name`, `--php`, `--python`,
`--prefix`, and `--autoload`, so several PHP versions can coexist as separate
kernels. It writes `<data-dir>/kernels/<name>/kernel.json`:

```json
{
  "argv": ["/opt/homebrew/bin/python3", "-m", "juphyter_kernel", "-f", "{connection_file}"],
  "display_name": "PHP 8.5",
  "language": "php",
  "interrupt_mode": "signal",
  "env": {
    "JUPHYTER_PHP": "/Users/erik/Library/Application Support/Herd/bin/php85",
    "JUPHYTER_ENGINE": "/path/to/vendor/phpstats/juphyter/bin/juphyter",
    "PYTHONPATH": "/path/to/vendor/phpstats/juphyter/kernel"
  }
}
```

The Python wrapper ships inside the Composer package as a module on
`PYTHONPATH` rather than as a PyPI package, so milestone 1 does not take on
Python release management. The install command picks the interpreter that owns
the `jupyter` on PATH, by running `python3 -c "import sys; print(sys.executable)"`
against it, verifies that `ipykernel` imports there, and prints the exact
install command when it does not. `juphyter uninstall` removes a kernelspec it
wrote.

A PHAR built by `scripts/build-phar.php` covers people who want a PHP kernel
without creating a Composer project. In that case `JUPHYTER_ENGINE` points at
the PHAR, and the engine finds the project's dependencies at runtime.
The build is a plain `Phar` script reading its file list from `phar.json`, rather
than humbug/box, because box is not installed here and an unverified build path
is worse than a smaller verified one. Moving to box later would bring its own
`box.json`.

Project context discovery, which applies to both installs: the engine walks up
from its working directory, which Jupyter sets to the notebook's directory,
looking for `vendor/autoload.php`, and requires the first one it finds.
`JUPHYTER_AUTOLOAD` overrides the search, and a `juphyter.json` beside the
notebook can set the autoloader path, the memory limit, and the include path.
A notebook in a Composer project therefore sees that project's phibble and
phiz with no configuration.

### Testing

Four layers, from fastest to slowest.

**Unit, in Pest.** The cleaner's six behaviors, session state round-tripping,
traceback line mapping, the display registry's dispatch order, each formatter's
bundle, the HTML table renderer against snapshots in the style of phiz's spec
snapshots, kernelspec generation, and autoloader discovery. This layer needs no
Python and no Jupyter and carries most of the behavioral coverage.

**Engine, in Pest.** A test harness that opens a socket, spawns the engine, and
asserts the event sequence for a request. This covers state across cells,
streaming order, error events, interrupts, and shutdown without involving the
Jupyter protocol at all.

**Protocol, in Python.** `jupyter_kernel_test` provides a `KernelTests` base
class that exercises a real kernel over the real protocol. Supplying
`code_hello_world`, `code_execute_result`, `code_display_data`,
`code_generate_error`, `complete_code_samples`, and `incomplete_code_samples`
gets conformance coverage of the wrapper and the wire format for very little
code. This runs in a CI job with Python and `ipykernel` installed.

**Document render.** A fixture `.qmd` rendered with `quarto render --to html`
and `--to ipynb`, asserting that the phibble table appears as an HTML table,
that the phiz plot appears as a figure, that `#| echo: false` suppressed the
source, and that state carried from one cell to the next. Rendered twice to
exercise Quarto's kernel daemon.

The matrix is PHP 8.3, 8.4, and 8.5 against ubuntu-latest and macos-latest,
crossed with the dependency sets `none`, `phibble`, `phibble+phplyr`, and
`phibble+phplyr+phiz` (8.5 only), and with vl-convert present and absent. Push
builds run PHP 8.4 on ubuntu with the full dependency set. The rest runs
nightly.

## Risks and open questions

1. **Vega-Lite 6 under a v5 MIME type.** The most likely rich-display failure.
   Mitigated by the static SVG path, resolved by checking a real plot in
   JupyterLab during task 8. Recorded above with two follow-up options.
2. **PHP syntax highlighting in Quarto.** Pandoc's PHP syntax expects `<?php`.
   Cosmetic, but visible on every page of a documentation site. Checked in
   task 8.
3. **Traceback quality under `eval()`.** Frames read as `eval()'d code` and are
   rewritten to `Cell N`. If the rewriting proves too lossy, the fallback is
   writing each cell to a per-cell file and `require`-ing it, which was
   measured to preserve variable scope. The cost of that fallback is that
   `__DIR__` and `__FILE__` would point at the temporary file rather than
   anywhere meaningful, so it is a fallback and not the default.
4. **Class and function redeclaration.** PHP cannot redefine either, so
   re-running a cell that declares a class fails. The cleaner refuses it with a
   clear message. This is a genuine limitation of a PHP notebook and belongs in
   the documentation rather than being papered over.
5. **Memory growth in a daemonized kernel.** Quarto keeps kernels alive between
   renders and the engine holds every variable a session ever bound. The
   `juphyter.json` memory limit and a `shutdown` path are the milestone-1
   answer. Watch it during the render tests.
6. **Open question for a later milestone.** Which native transport to add once
   the engine is proven: FFI against `libzmq`, pure-PHP ZMTP, or a Rust host.
   Nothing in milestone 1 forecloses any of them, and the choice should be made
   with a working kernel in hand.
7. **Open question.** Whether phibble should grow `toHtml()` in its own
   repository. juphyter's registry prefers such a method when it exists, so the
   question can be settled later without blocking anything here.

## Milestone 1 tasks

Each task owns its files outright. Where two tasks touch the same area, the
file list says who owns what, and the ordering below keeps them from running at
the same time.

### Task 1: package skeleton, console dispatcher, and CI

**Deliverable.** A Composer package that installs on PHP 8.3, 8.4, and 8.5,
with an empty green Pest suite, a `bin/juphyter` that dispatches to subcommand
classes by name, and a CI workflow.

**Files owned.** `composer.json`, `.gitignore`, `phpunit.xml`, `tests/Pest.php`,
`tests/TestCase.php`, `bin/juphyter`, `src/Console/Application.php`,
`src/Console/Command.php`, `.github/workflows/tests.yml`.

**Notes.** `bin/juphyter` must stay a dispatcher that later tasks extend by
adding a class under `src/Console/`, never by editing the dispatcher, so tasks
5 and 7 do not collide. Require `nikic/php-parser: ^5.0` and nothing else in
`require`. Put `pestphp/pest: ^4.0` in `require-dev`, with `phpstats/phibble`,
`phpstats/phplyr`, and `erikwestlund/phiz` as optional dev dependencies behind
the same `repositories` entries the siblings use.

**Acceptance.** `composer install` succeeds on all three PHP versions.
`vendor/bin/pest` is green. `bin/juphyter` with no arguments lists subcommands
and exits 0. An unknown subcommand prints usage and exits non-zero. CI runs the
suite on 8.3, 8.4, and 8.5 against ubuntu-latest and macos-latest.

**Focused tests.** `tests/Unit/Console/ApplicationTest.php`: dispatch to a
registered command, unknown command exit code, and usage output.

**Model.** Mid.

### Task 2: cell cleaner and session state

**Deliverable.** Given cell source and a session, produce evaluable source plus
the prelude line count, or a cell error. Implements the six cleaner behaviors
in the execution-semantics section.

**Files owned.** `src/Engine/Cleaner.php`, `src/Engine/Prelude.php`,
`src/Engine/Session.php`, `src/Engine/CellError.php`,
`tests/Unit/Engine/CleanerTest.php`, `tests/Unit/Engine/SessionTest.php`.

**Acceptance.** `use` statements accumulate across cells and are re-emitted
ahead of every later cell. `declare(strict_types=1)` is prepended and enforced.
A syntax error returns a `CellError` with the parser's message and the line
number in the user's cell, not the prelude's. A `namespace` declaration is
refused with a message naming the limitation. A trailing expression statement
is rewritten so its value can be captured, and an assignment, `echo`, `print`,
or declaration as the last statement is not. Redeclaring a name the session
already holds is refused before execution, with the colliding name in the
message.

**Focused tests.** The acceptance list, case by case, plus a round trip of ten
cells asserting the prelude line count stays correct as the `use` set grows.

**Model.** Strong. This task sets the error quality for the whole kernel.

### Task 3: executor, output capture, and traceback mapping

**Deliverable.** Run cleaned source, persist variables, stream output, and turn
PHP errors into reportable events.

**Files owned.** `src/Engine/Executor.php`, `src/Engine/Output.php`,
`src/Engine/Traceback.php`, `src/Engine/Interrupt.php`, `src/Engine/Fatal.php`,
`tests/Unit/Engine/ExecutorTest.php`, `tests/Unit/Engine/TracebackTest.php`.

**Depends on** task 2, for the cleaner's return shape.

**Acceptance.** Variables, objects, and declared functions and classes survive
from one execution to the next. Buffered output is emitted as it is produced
rather than at the end of the cell. Warnings and deprecations arrive as
`stderr` stream events, not as results. A thrown `Throwable` yields `ename`,
`evalue`, and a traceback whose cell frames read `Cell N` with the user's own
line numbers. An uncatchable fatal produces a final error event from the
shutdown handler. SIGINT during a long-running cell produces an interrupt
error, and the session is still usable afterward.

**Focused tests.** The acceptance list, with the traceback test covering a
throw from cell code, a throw from a function declared in an earlier cell, and
a throw from inside vendor code, asserting which frames are rewritten and which
are left alone.

**Model.** Strong.

### Task 4: display registry and formatters

**Deliverable.** A registry that turns a PHP value into a MIME bundle, with
formatters for phibble tables, phiz charts, scalars, arrays, objects, and
throwables, plus the user-type hooks.

**Files owned.** `src/Display/Registry.php`, `src/Display/MimeBundle.php`,
`src/Display/Formatter.php`, `src/Display/Formatters/` (all files),
`src/Display/Html/TableRenderer.php`, `src/functions.php`,
`tests/Unit/Display/` (all files), `tests/snapshots/display/` (all files).

**Runs in parallel with** tasks 2 and 3, against the `MimeBundle` and
representation tables in this plan.

**Acceptance.** Every bundle carries `text/plain`. A phibble `DataFrame`,
`SqlTable`, and `PolarsTable` all match the table formatter, and `text/plain`
is byte-identical to `$table->collect()->format()`. The HTML table carries
column types, the frame's dimensions, and a footer for hidden rows and columns,
and matches its snapshot. A `Phiz\Plot` and a `Phiz\Compose` both produce the
four representations, with `image/svg+xml` present when vl-convert is on PATH
and absent when it is not. No formatter references a sibling class at compile
time, verified by the suite passing with none of phibble, phplyr, or phiz
installed. A class with `toMimeBundle()` takes precedence over the built-in
formatters, and one with only `toHtml()` has it used for `text/html`.
`Juphyter\display()` publishes a bundle.

**Focused tests.** The acceptance list. phiz tests skip below PHP 8.5, and
vl-convert tests skip when the binary is missing; both skips must be explicit
rather than silent.

**Model.** Strong.

### Task 5: engine loop, private protocol, and project discovery

**Deliverable.** `bin/juphyter engine` connects to `JUPHYTER_SOCKET`, serves the
request types in the contract above, and loads the notebook's project.

**Files owned.** `src/Engine/Engine.php`, `src/Protocol/Channel.php`,
`src/Protocol/Request.php`, `src/Protocol/Event.php`,
`src/Console/EngineCommand.php`, `src/Project/Autoload.php`,
`src/Project/Config.php`, `src/Engine/Completer.php`,
`tests/Engine/` (all files), `tests/Unit/Project/` (all files).

**Depends on** tasks 3 and 4.

**Acceptance.** Every request ends with exactly one terminal event.
`execute_request` increments `execution_count` only when `store_history` is
true. `silent` suppresses result and display events but not errors.
`is_complete` reports `incomplete` for an unclosed brace and `complete` for a
finished statement. `kernel_info` reports the running PHP version in
`language_info`. `shutdown` closes the socket and exits 0. The engine finds
`vendor/autoload.php` by walking up from its working directory,
`JUPHYTER_AUTOLOAD` overrides that, and a `juphyter.json` beside the notebook
sets the autoloader, memory limit, and include path. Malformed input on the
socket produces an error event rather than a crash.

**Focused tests.** A PHP harness in `tests/Engine/` that spawns the engine over
a real socket and asserts event sequences for each acceptance item, including a
three-cell session that proves state persists across requests.

**Model.** Strong.

### Task 6: Python wrapper kernel and protocol conformance

**Deliverable.** An `ipykernel` subclass that owns the Jupyter protocol and
forwards to the engine, plus conformance tests.

**Files owned.** `kernel/juphyter_kernel/__init__.py`,
`kernel/juphyter_kernel/__main__.py`, `kernel/juphyter_kernel/kernel.py`,
`tests/Protocol/test_kernel.py`, `tests/Protocol/requirements.txt`,
`.github/workflows/protocol.yml`.

**Depends on** task 5.

**Acceptance.** Spawns the engine, creates the socket, and reaps the child on
shutdown and on its own death, leaving no orphans. Maps every engine event onto
the right Jupyter message. Forwards SIGINT on interrupt. Reports the engine
dying mid-cell as an error and then exits so the front end offers a restart.
Floors are `ipykernel>=7.0` and `jupyter_client>=8`. ipykernel 7 moved
`kernelbase` onto anyio and made several `Kernel` hooks asynchronous, so
`do_execute`, `do_complete`, and `do_is_complete` are `async def`. Task 7's
`PythonProbe::IPYKERNEL_FLOOR` holds the same number and refuses anything
older at install time.
`jupyter_kernel_test`'s `KernelTests` passes with `code_hello_world`,
`code_execute_result`, `code_display_data`, `code_generate_error`,
`complete_code_samples`, and `incomplete_code_samples` supplied. A CI job
installs Python, `ipykernel`, and `jupyter_kernel_test` and runs them.

**Focused tests.** The `jupyter_kernel_test` suite, plus a test that kills the
engine mid-execution and asserts the kernel reports it rather than hanging.

**Model.** Strong.

### Task 7: install command, kernelspec, and PHAR

**Deliverable.** `juphyter install` and `juphyter uninstall`, and a PHAR build.

**Files owned.** `src/Install/KernelSpec.php`, `src/Install/JupyterPaths.php`,
`src/Install/PythonProbe.php`, `src/Console/InstallCommand.php`,
`src/Console/UninstallCommand.php`, `scripts/build-phar.php`, `phar.json`,
`tests/Unit/Install/` (all files).

**Depends on** task 1. Runs in parallel with tasks 2 through 6, against the
kernelspec shape in this plan.

**Acceptance.** Writes a kernelspec at the right path for macOS and Linux,
honoring `JUPYTER_DATA_DIR` and `--prefix`. `--name` and `--display-name`
install side-by-side kernels for different PHP versions without collision.
Selects the interpreter that owns the `jupyter` on PATH, and when `ipykernel`
is missing there, prints the exact command to install it and exits non-zero
without writing anything. `--php` is validated by running it. `uninstall`
removes only a kernelspec juphyter wrote. The PHAR runs
`juphyter engine` and `juphyter install`.

**Focused tests.** The acceptance list against a temporary prefix. The Python
probe is tested through an injected command runner, so the unit suite needs no
Python.

**Model.** Mid.

### Task 8: Quarto integration and end-to-end render

**Deliverable.** A rendering `.qmd` fixture, a render test, and the Quarto
documentation page, with the two open display questions answered.

**Files owned.** `docs/_quarto.yml`, `docs/index.qmd`, `docs/get-started.qmd`,
`docs/quarto.qmd`, `docs/jupyter.qmd`, `tests/Quarto/` (all files),
`scripts/render-docs`, `.github/workflows/quarto.yml`.

**Depends on** tasks 6 and 7.

**Acceptance.** `quarto render` of the fixture to HTML produces a page with the
phibble frame as an HTML table and the phiz plot as a figure, with
`#| echo: false` honored and state carried between cells. Rendering to `ipynb`
produces the same outputs. A second render exercises Quarto's kernel daemon and
produces identical output. Rendering to PDF produces the plot from the static
SVG when vl-convert is present. The task records, in this plan's open-questions
section, whether JupyterLab draws the plot from the v5 MIME type or needs the
SVG, and whether PHP cells are syntax-highlighted in Quarto output.

**Focused tests.** `tests/Quarto/` asserting on rendered output, skipped with an
explicit message when `quarto` is not installed.

**Model.** Strong, because this is where the design's two uncertainties get
settled.

### Task 9: README and installation documentation

**Deliverable.** A README in the siblings' register, and installation and
limitations documentation.

**Files owned.** `README.md`, `docs/install.qmd`, `docs/limitations.qmd`,
`CHANGELOG.md`.

**Depends on** tasks 7 and 8. Takes over `README.md` from task 1.

**Acceptance.** Installation covers the Composer and PHAR paths, the Python
prerequisite, installing several PHP versions side by side, and vl-convert as
optional. Limitations state the class and function redeclaration rule, the
absence of `namespace` support, and the macOS and Linux scope, each with the
reason. The README follows the shape phibble, phplyr, and phiz use: what it is,
install, one example, features, development.

**Model.** Mid.

## Dependencies and parallelism

```
Task 1
  |
  +--> Task 2 --> Task 3 --+
  |                        |
  +--> Task 4 -------------+--> Task 5 --> Task 6 --+
  |                                                 |
  +--> Task 7 --------------------------------------+--> Task 8 --> Task 9
```

Tasks 2, 4, and 7 start together once task 1 lands. Task 3 follows task 2.
Task 5 needs both task 3 and task 4. Tasks 8 and 9 are sequential at the end.
Tasks 2 and 4 are the two worth giving the strongest model, because the cleaner
sets the kernel's error quality and the display layer is what makes the kernel
worth using.

## Preview contract

This group needs the user to look at two things, because neither can be
asserted from a test alone.

**Group preview, after task 6.** JupyterLab in a browser with the PHP kernel
selected. Seed state: a Composer project with phibble, phplyr, and phiz
installed on PHP 8.5, and a notebook holding a cell that builds a phibble frame
from a CSV, a cell that summarizes it with phplyr, and a cell that plots it
with phiz. What to check: the kernel appears in the launcher, cells run in
order with state carried between them, the frame renders as a table, the plot
draws, and an intentional `TypeError` reports with a readable traceback. This
preview is what settles whether JupyterLab draws the plot from the Vega-Lite v5
MIME type or needs the static SVG.

**Final integrated preview, after task 8.** A rendered `.qmd` in a browser, and
the same document rendered to PDF. What to check: figures and tables appear in
both, `#| echo: false` suppressed the source, and PHP cells are
syntax-highlighted or are not.
