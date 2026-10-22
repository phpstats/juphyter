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

The explicit `kernelspec:` block is not worth using. Quarto also requires a
`path` key there and raises `KeyError: 'path'` without it, so `jupyter: <name>`
is the form to document and the form the tests cover.

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

1. **Vega-Lite 6 under a v5 MIME type. Settled: it works.** This was written as
   the most likely rich-display failure and it is not one. JupyterLab 4.6.4
   declares Vega-Lite MIME types v3, v4 and v5 and mentions v6 nowhere in its
   shipped bundle, so the v5 type is the only one its renderer would pick up,
   and it bundles Vega-Lite 5.6.1 against phiz's v6 `$schema`. Sixteen specs
   generated across phiz's feature surface, covering bars, `lm` and `loess`
   smoothing, facet wrap and grid, histograms, KDE density, boxplots, log
   scales, tooltips with pan and zoom, brush selection, legend highlighting,
   themes, temporal encoding and both `Compose` layouts, all compiled under
   5.6.1 with no warnings and no failures. So neither follow-up is needed: no
   JupyterLab mime extension, and no upstream ask to phiz for a v5 `$schema`.
   The static SVG remains worth having for Quarto's PDF and docx output, where
   it was shown to produce a real vector figure, but it is a convenience rather
   than the thing making the v5 choice safe.
2. **PHP syntax highlighting in Quarto. Settled: it works.** The concern was
   wrong. Quarto tags an executed PHP cell `sourceCode php` and Pandoc emits
   real token spans for keywords, functions, variables, strings and operators,
   with no `<?php` tag present. Nothing to do.
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


## Follow-ups after the milestone

These came out of the review rounds and are written down here so they do not
depend on anyone's memory.

- **Task 10: phiz's Composer name. Landed.** phiz is renamed from
  `erikwestlund/phiz` to `phpstats/phiz`. juphyter names the old package in
  eight places: the `suggest` entry in `composer.json`, the dev install line in
  `.github/workflows/family.yml`, two skip messages in
  `tests/Unit/Display/ChartFormatterTest.php`, the require in
  `tests/Quarto/fixture/composer.json`, the `FAMILY` tuple in
  `tests/Quarto/conftest.py`, the vendor-path loop in
  `tests/Quarto/test_render.py`, the README link, and `docs/install.qmd`. The
  `Phiz\` namespace does not change, so no formatter or test logic moves.

  Nothing in juphyter requires phiz, so no install breaks either way. What
  breaks is the family CI job and the Quarto render fixture, which install it by
  name, so the commit must land close to phiz's own.

  **The gate was a push, not a release.** Composer's VCS driver takes the
  package name from the default branch and applies it to the tags, which was
  measured rather than assumed: against a repository whose `main` carried the
  new name while its `v0.3.0` tag still carried the old one, `composer require
  phpstats/phiz:^0.3` resolved and locked v0.3.0. So pushing phiz's `main` was
  enough for the existing tag to answer `^0.3`, and no new tag or release was
  needed. The only thing that would have invalidated the `^0.3` constraints was
  phiz choosing to publish the rename at 0.4 or later, which it did not.

  **What landed, on 2026-10-19.** `0-reconcile-royal-dingo` pushed `main` for
  phibble, phplyr, phit, phpolars, cmdstanphp, and phiz, so `phpstats/phiz` and
  `phpstats/cmdstanphp` are the names GitHub now answers to. phpstats.org was
  the only `composer.json` in the family still naming old packages; it moved to
  `phpstats/phiz: ^0.3` and `phpstats/cmdstanphp: dev-main` and pushed as
  `8fcac91`, with its lock recording `phpstats/phiz v0.3.0` at source reference
  `5d2f7a65c2`, the same commit the old name resolved to. Task 10's commit then
  merged into juphyter's `main`, and `tests/Quarto` ran green against the
  pushed remotes.

  Two findings came out of it that the entry above did not anticipate. The
  constraint `cmdstan/php: dev-phpstats-family` named a branch that did not
  exist, so `composer update` in phpstats.org would have failed before any
  rename; `composer install` kept working only because the lock pinned a
  reachable commit. cmdstanphp has no tags at all, which is why its constraint
  is `dev-main` rather than a version. A `dev` constraint is acceptable under
  the site's `minimum-stability: stable` because an explicit root requirement
  carries its own stability, which group 1's planner measured before the edit
  was written.
- **A `quarto_cleanup_cell` in the kernelspec.** Quarto does not daemonize PHP
  kernels today, because it has no entry for the language, so the key has
  nothing to act on. Worth adding when Quarto learns about PHP, alongside the
  native transport.
- **Freeze, for the family's other packages.** Measured while answering group 1:
  `quarto render` writes to the output directory and keeps `_freeze/` separate,
  and a frozen page renders without PHP, the engine, Python or any extension,
  provided a kernelspec of that name exists. A stub `kernel.json` naming paths
  that do not exist is enough. A cache miss ends that, so editing a PHP cell
  needs the real kernel. This is what makes executable docs adoptable across
  packages whose contributors lack the full stack.

  **Revised after group 1 raised it.** `docs/_quarto.yml` sets `freeze: false`
  deliberately, because rendering the site executes every cell and so is the
  broadest end-to-end exercise of the kernel there is. That value is real and I
  have relied on it twice. The recommendation is now `freeze: auto` with
  `_freeze/` committed, plus a CI step that removes the directory before
  rendering: a contributor fixing prose then needs only a stub kernelspec, while
  CI still executes every cell on every run. There is no `--no-freeze` flag;
  the only freeze-related flag on Quarto 1.10.18 is `--use-freezer`, which
  forces the opposite, so deleting the directory is the way to force execution.
  This is Erik's to approve, as it is new scope.

## What the first CI run found

The first CI run after the push failed in both workflows. Neither failure was
caught by the local suites, and both are recorded here because the reason each
escaped matters more than the fix.

- **Task 11: the quarto job.** Two render tests failed on Linux only.
  `test_the_daemon_leaves_no_engine_or_watchdog_behind` found the engine and
  reported no watchdog, and the watchdog was there: `ps` fits its command
  column to the screen width, takes that width from `COLUMNS` when nothing is
  attached to its output, and the watchdog's `python -c` script is several
  hundred characters long with the pid it guards at the end. `ps -ww` asks for
  the untruncated form. I verified it in an `ubuntu:24.04` container, where a
  200-character marker is invisible without the flag and found with it, and
  confirmed the flag changes nothing on macOS, which is why its absence was
  invisible locally. The other failure was the PDF's Japanese characters: bare
  `ubuntu:24.04` has no font covering U+65E5, U+672C, or U+8A9E, and
  `fonts-noto-cjk` brings 30 that cover all three, so the workflow installs it
  and the assertion stays.

  A permanent test now holds the listing to the untruncated form. It cannot
  fail on macOS, because macOS does not truncate when the output is piped, and
  that is inherent rather than a gap.

- **Task 12: a real kernel bug the tests had not reached.** Reporting an
  uncatchable fatal allocates: `error_get_last()` builds an array, the final
  flush copies what the cell printed, and the event is encoded into a string. A
  cell that exhausts the memory limit leaves the process sitting at that limit,
  so the first of those steps to ask for memory failed, and a fatal inside a
  shutdown function abandons the rest of the handler. The front end was left
  with the cell's output, no terminal event, and a process that had already
  gone. The shutdown handler now raises the memory limit as its first act,
  which is safe where it would not be elsewhere: the process is exiting, the
  remaining work is bounded, and the limit is only ever raised.

  **Whether the old handler survived depended on the build, not the version.**
  That is the part worth keeping. With `self::reserve()` removed and nothing
  else changed, the cell that prints two megabytes before exhausting the limit
  fails five times out of five on Homebrew's PHP 8.5.10 and passes five out of
  five on Herd's 8.5.10, the same version number built a month apart. The
  worker and I disagreed about the 8.5 leg for exactly this reason and we were
  both measuring correctly. Three guards now cover three mechanisms, and
  between them they fail without the fix on 8.3.35, 8.4.26, and Homebrew's
  8.5.10.

  A floor this exposes and does not fix: at a 2M or 4M `memory_limit` the
  kernel cannot report a fatal at all, because php-parser exhausts the limit
  while parsing the cell and the cell never runs. The `memory_limit` key in
  `juphyter.json` can be set that low, so the limitations page should say that
  very low limits fail before a cell can run.

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
`phpstats/phplyr`, and `phpstats/phiz` as optional dev dependencies behind
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

## Task 13: publish the docs site, and what it settled

Added after the plan's tasks were done, when Erik asked for the install
instructions to be copy-paste and then for the site to be online. It is
recorded here because it changed two things the plan had assumed.

**The install guide could not work as written.** `juphyter install` succeeds
and `quarto render` then fails with `Jupyter kernel 'php' not found`, because
Quarto chooses its own Python independently of the one the kernel was installed
with, and on a stock macOS machine that interpreter has no Jupyter in it.
`scripts/render-docs` had always set `QUARTO_PYTHON` for exactly this reason,
so the project had solved the problem for itself and never written it down. The
README and `docs/install.qmd` now lead with a global `composer global require`
install, because a kernelspec belongs to the machine rather than to a project:
installing from a second project replaces the first kernelspec, and deleting
that project's `vendor` directory breaks every notebook on the machine.

**What the site requires, which the plan never specified.** phpstats.org reads
a package's pages out of its repository rather than rendering them.
`SyncSiteContent` throws without `site/site.json`, and `DocsImporter::discover()`
takes a page only where `docs/<page>.html` and `docs/<page>.qmd` sit together,
skipping any path that starts with `_`. So the rendered HTML is committed beside
its sources, and `docs/_site/` is invisible to the importer. The omission of
`site/site.json` from the plan was mine; group 1 raised it.

**Decisions task 13 made, all of which stand.**

1. `freeze: auto` with `_freeze/` committed. The reason is sharper than
   staleness: Quarto gives each executed cell a random id, so two renders of
   unchanged sources differ. From the freeze the render is byte-reproducible,
   and "is the committed HTML current" becomes a diff.
2. No scheduled trigger. A nightly clear would execute what every push already
   executes.
3. `_site` is copied into `docs/` with pruning, following phibble, rather than
   rendering with `--output-dir .`.
4. `--frozen` needs only Quarto and a python3 that can import `jupyter_client`.
   Quarto resolves a document's kernel by name while building the site
   navigation, before it reads anything frozen, so a kernelspec of that name
   must exist even though nothing starts it.
5. `docs/sitemap.xml` is excluded from the CI comparison, because its `lastmod`
   values come from source mtimes and a fresh checkout resets those.

**A trap worth keeping written down.** `--execute` does not override `freeze:
auto`. It sets `execute: enabled`, which turns execution on for a document that
asked for none, and leaves the freeze to answer whether an unchanged page runs
at all. Quarto has no flag that overrides the freeze for a project render, so
the only way to make every page run is to delete `docs/_freeze` before the
render. Task 13's first round had `--execute` and believed it was executing
everything; it was serving five of six pages back out of the freeze. The task
worker found this, after I had reviewed and accepted the claim without
measuring it. The check that catches it is cheap: a real render logs
`Executing` once per page it executes, so counting those lines against the
pages that hold cells settles it.

One consequence to expect rather than debug. Because the freeze is deleted and
rewritten on every executing render, and Quarto gives each executed cell a new
random id, `scripts/render-docs` changes eleven committed files even when no
source changed: the five frozen results, their five pages, and `search.json`,
plus `sitemap.xml` whose `lastmod` comes from source mtimes. Byte
reproducibility belongs to `--frozen` alone, which is why the CI job that
compares against the committed pages is the frozen one.

**A limitation to carry.** The executing CI job cannot verify the committed
HTML, because the random ids in decision 1 make a byte comparison permanently
red. It catches a kernel that crashes, not output that drifts: if the kernel
starts producing different but valid output, CI stays green until someone runs
`--execute` and commits. The fix is a normalized comparison, stripping cell ids
and execution counts from both sides before diffing. Related: the guard that
refuses to publish an unexecuted render is a floor rather than a tally, so a
page coming out with some of its cells executed still publishes.

**A process note, recorded because it happened four times.** A branch of this
workspace was positioned to revert landed work on four separate occasions: the
idle-socket fix, task 10, task 12, and the phpcs naming sniffs. Each was caught
by `git diff --stat origin/main HEAD` and fixed by rebasing, and each would have
been invisible in a diff against the branch's own base. Run that comparison
before every handoff, not only after a conflict.

## The install guide, round two: whose Python Quarto uses

The guide's first fix put a virtual environment first on `PATH` and left the
reason implicit. That was not enough. Following it still produced
`ERROR: Jupyter kernel 'php' not found. Known kernels: python3` on a machine
where `juphyter install` had just succeeded and `jupyter kernelspec list` showed
the kernel, because the single step the whole install rested on was an
`echo >> ~/.zshrc`, which reaches only shells started afterward, and nothing in
the guide asked the reader to check.

Two facts settled the rewrite, both measured rather than reasoned about.

**Quarto's interpreter is the one that needs Jupyter.** The kernelspec names the
Python that runs the wrapper, and Quarto separately chooses the Python that
starts the kernel and collects its output. They need not be the same
interpreter, and only the second one needs Jupyter. On macOS the `python3` on
`PATH` is usually a Homebrew build with no Jupyter, which is the whole failure.
`quarto check jupyter` names the interpreter it chose and writes its report to
standard error, so a `grep` without `2>&1` silently drops it and exits 1. The
first draft of this fix shipped that bug into the README and a sandbox run
caught it.

**`QUARTO_PYTHON` is not safe to export from a shell startup file.** It takes
precedence over an activated virtual environment, measured by activating one
environment and pointing the variable at another: Quarto reported the variable's
interpreter. Exporting it would therefore change the interpreter for every
Quarto project on the machine, including a project whose own environment is
active. The draft that did this was never committed.

The block now asks Quarto's interpreter for `jupyter_client` and names it in
`--python` when it has it, so the kernelspec and Quarto agree on one Python with
nothing overridden. A machine that already has Jupyter gains no virtual
environment and one `PATH` entry, for Composer's bin directory. A machine
without one gains the environment, first on `PATH`.

**The test that was missing.** The earlier verification ran the block and
asserted it worked in that same shell, which it always did. The failure lives in
the next shell. Verification now runs the block under `zsh -e` in a clean `HOME`
and `COMPOSER_HOME`, then renders a standalone document from a separately
started shell that sources only the new startup file, for both branches, and
re-runs the block to confirm the startup file does not grow. Extract the block
from the published file by regex rather than retyping it, so what runs is what a
reader would paste.

One consequence for task 13: editing `docs/install.qmd` changes its freeze hash,
so the committed `install.html`, its `_freeze` entry, and `search.json` go stale
and the `docs` job fails on the merged result until they are re-rendered.
