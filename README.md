# juphyter

juphyter runs PHP cells in JupyterLab and in Quarto documents. It is built
for statistical work with the [phpstats](https://phpstats.org) packages, so
[phibble](https://github.com/phpstats/phibble) tables and
[phiz](https://github.com/phpstats/phiz) plots arrive as notebook output rather
than as text.

## Requirements

- **PHP 8.3 or later** with the `pcntl`, `posix`, and `sockets` extensions.
  Homebrew, Herd, and distribution builds all include them.
- **Composer.**
- **Python 3.** The install uses the one already on the machine and adds none.
- **Quarto**, to render `.qmd` documents.
- **macOS or Linux.** The kernel interrupts a running cell with POSIX signals,
  which the Windows PHP build does not provide.

## Install

A kernelspec belongs to the machine rather than to a project, so install
juphyter once for your user. Copy the whole block:

```bash
# juphyter. It is not on Packagist yet, so Composer reads the repository
# directly, which "no-api" does over git and without a GitHub token.
composer global config repositories.juphyter \
  '{"type":"vcs","url":"https://github.com/phpstats/juphyter","no-api":true}'
composer global require phpstats/juphyter:dev-main

# Composer's bin directory, so that the juphyter command is on PATH.
bin="$(composer global config bin-dir --absolute 2>/dev/null)"
export PATH="$bin:$PATH"
grep -qF "$bin" ~/.zshrc 2>/dev/null \
  || echo "export PATH=\"$bin:\$PATH\"" >> ~/.zshrc

# The kernelspec, and a Python to host the kernel if none is available.
juphyter install

# Check. It reports what it found and fails if anything is broken.
juphyter doctor
```

On bash, write `~/.bashrc` in place of `~/.zshrc`. The only `PATH` entry this
adds is Composer's bin directory, and no Python on the machine is modified.

## Use it

Put `jupyter: php` in a document's YAML header:

````markdown
---
title: "PHP in Quarto"
jupyter: php
---

```{php}
echo "sum: ", array_sum(range(1, 10)), "\n";
PHP_VERSION;
```
````

Then render it:

- **VS Code, Positron, or Cursor** — open the *folder*, run **Python: Select
  Interpreter** from the command palette, pick an environment that has Jupyter,
  and render.
- **A terminal** — `source ~/.venvs/juphyter/bin/activate`, then
  `quarto render hello.qmd`.
- **JupyterLab** — run `jupyter lab`. The PHP kernel is in the launcher.

For a second PHP version, install another kernel under its own name:

```bash
juphyter install --name php85 --php /path/to/php85
```

If a render says the kernel is not found, see
[Troubleshooting](#troubleshooting). It is almost always the interpreter Quarto
picked, not the kernel.

## With the phpstats packages

The install above runs plain PHP. phibble tables and phiz plots come from
separate packages, and a second kernel gives them to a document in any
directory:

```bash
for r in phibble phplyr phiz; do
  composer global config "repositories.$r" \
    "{\"type\":\"vcs\",\"url\":\"https://github.com/phpstats/$r\",\"no-api\":true}"
done
composer global require --prefer-source \
  "phpstats/phibble:^0.3" "phpstats/phplyr:^0.3" "phpstats/phiz:^0.3"

juphyter install --name phpstats --display-name "PHP (phpstats)" \
  --autoload "$(composer global config home 2>/dev/null)/vendor/autoload.php"
```

Name that kernel in the front matter as `jupyter: phpstats`. It is a second
kernel rather than a change to the first because `--autoload` fixes one
autoloader for every document, which suits a document outside any project and
breaks one inside a project. The `php` kernel keeps the default: it walks up
from the document's directory and loads the nearest `vendor/autoload.php`, so a
document inside a Composer project sees that project's own packages.

The [installation guide](https://juphyter.phpstats.org/install.html) covers
installing into a single project, the standalone PHAR, where a kernelspec goes,
and static image export for PDF and Word.

## Example

A cell's last expression is its output, as in any notebook:

```php
$penguins = Phibble\DataFrame::fromCsv('penguins.csv');

$penguins
    ->groupBy(['species'])
    ->summarise(['n' => Phibble\Expr\n(), 'mass' => Phibble\Expr\mean(Phibble\Expr\col('body_mass'))])
```

```
# A tibble: 3 × 3
  species       n  mass
  <chr>     <int> <dbl>
1 Adelie      151 3701.
2 Chinstrap    68 3733.
3 Gentoo      123 5076.
```

In a notebook, that table arrives as HTML with its column types, and a
`Phiz\Plot` arrives as a chart. To show several things from one cell, call
`Juphyter\display()`.

## In Quarto

Name the kernel in the front matter and write `php` cells:

````markdown
---
title: "Penguin body mass"
jupyter: php
---

```{php}
#| echo: false
$penguins = Phibble\DataFrame::fromCsv('penguins.csv');
```

```{php}
Phiz\Plot::of($penguins)->aes(x: 'species', y: 'body_mass')->boxplot()
```
````

`quarto render` executes the cells and puts the figures and tables in the
output. Cell options work as they do for any other engine, because PHP accepts
`#` comments.

## Features

- **Session state:** variables, objects, and declared functions and classes
  persist from one cell to the next.
- **Rich display:** phibble tables as HTML, phiz plots as Vega-Lite and as
  static SVG, and anything with a `toMimeBundle()` or `toHtml()` method.
- **Readable errors:** a traceback names the cell and the line the user wrote,
  across cells, rather than `eval()`'d code.
- **Interrupts:** the stop button ends a running cell and leaves the session
  usable.
- **Your project's dependencies:** the kernel finds the nearest
  `vendor/autoload.php` by walking up from the notebook, so a notebook in a
  Composer project sees that project.
- **Completion:** variables, functions, classes, and members, by reflection.

## Limitations

PHP cannot redeclare a class or a function, so re-running a cell that declares
one needs a kernel restart, and a cell cannot declare a `namespace`. The kernel
runs on macOS and Linux. The
[limitations page](https://juphyter.phpstats.org/limitations.html) explains each
limitation and the reason for it.

## Troubleshooting

### How Quarto finds a kernel

`jupyter: php` in a document's header names a kernelspec by its directory name.
`juphyter install` writes that kernelspec to your user Jupyter directory, which
is `~/Library/Jupyter/kernels` on macOS and `~/.local/share/jupyter/kernels` on
Linux. Every Jupyter installation on the machine searches that directory,
whichever environment it lives in, so one install makes the kernel visible to
all of them.

Two interpreters are involved, and they are unrelated. The kernel runs PHP
inside the interpreter its kernelspec names, which needs `ipykernel`. Quarto
runs an interpreter of its own choosing, which needs `jupyter_client`,
`nbformat`, `nbclient`, and `pyyaml`, because Quarto's jupyter engine imports
those before any kernel starts and then launches the kernel the kernelspec
names. An interpreter holding Jupyter and nothing else can drive the PHP kernel.

Only Quarto's own interpreter is ever the problem. When it has no Jupyter in it,
Quarto can enumerate no kernels at all:

```
ERROR: Jupyter kernel 'php' not found. Known kernels: python3
```

The kernel is fine. A `jupyter: python3` document fails the same way on that
interpreter.

### Which interpreter Quarto uses

Measured on Quarto 1.10.18, in precedence order:

1. `QUARTO_PYTHON`, when the environment already sets it.
2. `QUARTO_PYTHON` from a project `_environment` file, which Quarto reads only
   when `_quarto.yml` sits beside it, and which does not override an
   environment that already sets the variable.
3. The first `python3` on `PATH`.

`VIRTUAL_ENV` is not consulted, so an environment active only through that
variable is ignored. There is no fallback to `python`, and `RETICULATE_PYTHON`
has no effect.

Every editor reduces to the same question: which interpreter reaches Quarto, and
does it have Jupyter in it. An editor that integrates with Quarto answers it by
setting `QUARTO_PYTHON` from the interpreter you select for the workspace. That
takes first place in the list above, so it overrides a project `_environment`
file. An editor without that integration leaves `PATH` to answer it.

### At a terminal

Activate an environment that has Jupyter, then render:

```bash
source ~/.venvs/juphyter/bin/activate
quarto render notebook.qmd
```

Activation lasts for that shell alone. For a single command:

```bash
QUARTO_PYTHON=~/.venvs/juphyter/bin/python quarto render notebook.qmd
```

For a Quarto project, an `_environment` file beside `_quarto.yml` names the
interpreter for every render in that project:

```
QUARTO_PYTHON=/Users/you/.venvs/juphyter/bin/python
```

Avoid exporting `QUARTO_PYTHON` from a shell startup file. It takes precedence
over an activated environment and would change the interpreter for every Quarto
project on the machine.

### In VS Code

Open the folder as a workspace. The Quarto extension asks the Python extension
for the interpreter of the workspace folder holding the document, and a loose
`.qmd` leaves nothing to resolve against.

Select the interpreter from the command palette with **Python: Select
Interpreter**, choosing an environment that has Jupyter. The Quarto extension
passes that choice to Quarto as `QUARTO_PYTHON` on every render in the
workspace, and nothing outside the workspace changes.

A project `_environment` file cannot correct a wrong choice here. The variable
the extension sets takes precedence over the file, so the render keeps using the
selected interpreter however the file is written.

### In Positron

Positron bundles the same Python extension under the same identifier, and the
Quarto extension behaves the same way, so the steps are the same: open the
folder, then **Python: Select Interpreter**.

Two things differ in practice. A workspace that already has an interpreter
stored keeps it, and `python.defaultInterpreterPath` in `.vscode/settings.json`
applies only when no interpreter has been selected, so a stored choice has to be
replaced through the picker rather than through the file. A change to that
setting takes effect after **Developer: Reload Window**.

Positron opens a new terminal for each render, so the interpreter that arrived
can be read there:

```bash
echo "QUARTO_PYTHON=[$QUARTO_PYTHON]"
```

An empty value means no interpreter is selected for the workspace. A value
naming an interpreter without Jupyter is the wrong selection rather than a
missing one.

### In other editors

An editor with no Quarto integration, such as PhpStorm, leaves `PATH` to decide.
Give its terminal or its run configuration an environment whose `python3` has
Jupyter, either by activating that environment before launching the editor or by
setting `QUARTO_PYTHON` in the run configuration.

RStudio belongs to this group. It sets no `QUARTO_PYTHON` of its own, and Quarto
does not read the Python preference RStudio stores, so `PATH` decides there too.

### If no Python on the machine has Jupyter

`juphyter install` offers to build a virtual environment when it cannot find a
Python that can host the kernel, and `--create-venv PATH` answers that offer in
advance. The environment is built from the Python already on the machine, so no
new version of Python is installed, and nothing is written into the interpreter
it derives from.

Select that environment the way you would select any other: as the workspace
interpreter in VS Code or Positron, or with `source` at a terminal.

### If a render says the kernel is not found

Work down the precedence list. `juphyter doctor` reports the interpreter Quarto
resolved and whether the kernel itself is complete, and `quarto check jupyter`
reports the same interpreter from Quarto's side, naming the Jupyter it found and
the kernels it can see.

In an editor, check the selected interpreter first, because what it sets
overrides everything else. At a terminal, check that the environment is
activated in the shell that runs `quarto`.

## Development

```bash
composer install
vendor/bin/pest
```

The Python tests need a Jupyter installation:

```bash
python -m pip install -r tests/Protocol/requirements.txt
python -m pytest tests/Protocol
```

The display formatters are tested against the family packages, which are not
dependencies and are not on Packagist. `.github/workflows/family.yml` records
how CI installs them.
