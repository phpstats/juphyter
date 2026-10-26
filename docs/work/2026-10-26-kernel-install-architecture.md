# How juphyter should install on macOS

## Constraints

From the owner, 2026-10-26: installing the kernel and using it from Quarto in
Positron, VS Code, and RStudio should be easy; no new Python version may be
installed; the system Python must not be modified; and other notebooks must not
be hijacked.

## What the machine actually requires

Quarto's jupyter engine is a Python program at
`/Applications/quarto/share/jupyter/jupyter.py`. It imports `yaml`, `nbformat`,
`nbclient`, and `jupyter_client`, and it launches every kernel through the
interpreter Quarto resolved. A kernel cannot supply those imports, because the
engine loads them before any kernel starts.

The kernel itself runs in the interpreter named by `argv[0]` of its
`kernel.json`, and needs `ipykernel` plus the `juphyter_kernel` module supplied
through `PYTHONPATH`.

These two interpreters are unrelated. Measured on 2026-10-26: an environment
holding only `jupyter_client`, `nbformat`, `nbclient`, and `pyyaml`, with no
`ipykernel` and no juphyter, rendered a `jupyter: php` document to completion
and produced correct cell output. The kernelspec is found because
`~/Library/Jupyter/kernels` is the user-level Jupyter data directory, which
every `jupyter_client` on the machine searches regardless of which environment
it lives in.

## How Quarto resolves its interpreter

`QUARTO_PYTHON` when set, otherwise the first `python3` on `PATH`. There is no
fallback to `python`, and `VIRTUAL_ENV` is not consulted. `RETICULATE_PYTHON`
does not appear anywhere in `quarto.js`, so RStudio's Python preference has no
effect on it.

The Quarto extension for VS Code, Positron, and Cursor sets `QUARTO_PYTHON`
itself. In version 1.139.0 the extension activates `ms-python.python`, calls
`settings.getExecutionDetails(uri)` for the workspace folder, and assigns the
resulting interpreter to `QUARTO_PYTHON` for the render. The interpreter chosen
in the editor's Python selector is therefore the interpreter Quarto uses, scoped
to that workspace. This was read from the extension source, not observed at
runtime.

## The resulting design

Install the kernelspec once, into the user-level Jupyter directory. Every
Jupyter-capable interpreter on the machine can then see it. Change nothing else.

A user who already runs Python notebooks needs no further step. Their existing
interpreter already has `jupyter_client`, their editor already points Quarto at
it, and the PHP kernel becomes visible to it the moment the kernelspec exists.
Nothing global changes and no other notebook is affected.

A user with no Jupyter-capable interpreter needs one. juphyter builds a
dedicated virtual environment for this, from the Python already on the machine.
A virtual environment installs no new Python version and writes nothing into the
interpreter it derives from. The user selects it the same way they would select
any other environment.

The environment is therefore wired per workspace in the editors, per shell at a
terminal through ordinary activation, and per project through `_environment` for
a Quarto project. None of these is global, which is what keeps other notebooks
untouched.

## What this replaces

The current README builds a virtual environment and prepends it to `PATH` in a
shell startup file. That makes `python3`, `pip`, and `jupyter` resolve to
juphyter's environment in every shell on the machine, so an unrelated
`jupyter: python3` document would execute in an environment holding neither
pandas nor matplotlib. It is the failure the owner anticipated, and it has to
come out.

The install guide also previously assumed the kernel's interpreter and Quarto's
interpreter were the same. They are not, and the guide cannot be corrected
without separating them.

## Open questions

RStudio sets no `QUARTO_PYTHON` and ignores `RETICULATE_PYTHON` for this
purpose, so it resolves through `PATH`. Whether a project-level `.Renviron`
reaches the Quarto process RStudio spawns has not been tested, and it needs a
GUI session to check. Until it is confirmed, RStudio has no project-scoped
wiring and only shell activation is known to work.

Whether the built environment should use `--system-site-packages` is undecided.
It would let the environment see packages already in the base interpreter, which
helps a user whose `python3` carries real libraries and does nothing on a
Homebrew Python that PEP 668 keeps empty.

## Changes made

`--path-file` is removed. The option appended the kernel interpreter's `PATH`
entry to a named shell startup file, which was the one remedy the command could
carry out itself and is the remedy this design rejects. An entry in a startup
file changes `python3` for every Quarto project on the machine. Naming the file
was consent to that, but the thing consented to was the wrong thing.

`juphyter install` now builds the environment when there is no terminal to ask,
rather than declining and printing a command that installs `ipykernel` into an
interpreter the reader did not dedicate to juphyter.

`juphyter doctor` treated a default `python3` that cannot host a kernel as a
failure. On a machine whose kernels are complete and whose front ends are ready
it exited 1, and the install block in the README ends with `juphyter doctor`, so
a correct install ended in a failure. The section is advisory once a kernel
exists, because a kernel names its own interpreter and runs in it.

The Quarto warning in `install` and the remedy in `doctor` both led with a
`PATH` entry, and `doctor` told the reader to persist it. Both now lead with
selecting the interpreter in the editor and offer the export for one shell.

## Precedence, measured after the editor finding

An environment that already carries `QUARTO_PYTHON` overrides a project
`_environment` file. Measured on 2026-10-26: a project holding both
`_quarto.yml` and an `_environment` naming an interpreter with Jupyter rendered
correctly from a bare shell, and failed with `Jupyter kernel 'php' not found`
when the same render ran with `QUARTO_PYTHON` set to an interpreter without
Jupyter.

The order is therefore `QUARTO_PYTHON` from the environment, then
`QUARTO_PYTHON` from `_environment`, then the first `python3` on `PATH`.

This changes what the editor integration means. The Quarto extension sets
`QUARTO_PYTHON` from the selected interpreter on every render, which places it
first in that order, so a project `_environment` file is inert under VS Code and
Positron. The interpreter picker is the only correction there, and the guides
have to say so rather than offering `_environment` as a general alternative.

`python.defaultInterpreterPath` in `.vscode/settings.json` applies only when no
interpreter has been selected for the workspace. A workspace carrying a stored
selection keeps it, so the file is not a way to correct a wrong one.
