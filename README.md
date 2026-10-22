# juphyter

juphyter runs PHP cells in JupyterLab and in Quarto documents. It is built
for statistical work with the [phpstats](https://phpstats.org) packages, so
[phibble](https://github.com/phpstats/phibble) tables and
[phiz](https://github.com/phpstats/phiz) plots arrive as notebook output rather
than as text.

## Requirements

- **PHP 8.3 or later**, from Homebrew, Herd, or a distribution package. The
  kernel uses the `pcntl`, `posix`, and `sockets` extensions, which all three
  builds include. phiz plots need PHP 8.5.
- **Composer.**
- **Python 3.** The kernel's wrapper runs inside `ipykernel`, which the block
  below installs. Quarto's jupyter engine already requires a Python Jupyter for
  every kernel it drives, so this is not particular to PHP.
- **Quarto**, to render Quarto documents. JupyterLab comes with the Python
  install below.
- **macOS or Linux.** The kernel interrupts a running cell with POSIX signals,
  which the Windows PHP build does not provide.

## Install

A kernelspec belongs to the machine rather than to a project, so install
juphyter once for your user. Copy the whole block:

```bash
# Does the Python that Quarto will use already have Jupyter?
py="$(command -v python3)"
"$py" -c 'import jupyter_client' 2>/dev/null || py=''

# juphyter. It is not on Packagist yet, so Composer reads the repository
# directly, which "no-api" does over git and without a GitHub token.
composer global config repositories.juphyter \
  '{"type":"vcs","url":"https://github.com/phpstats/juphyter","no-api":true}'
composer global require phpstats/juphyter:dev-main

# The juphyter command on PATH.
bin="$(composer global config bin-dir --absolute 2>/dev/null)"
export PATH="$bin:$PATH"
grep -qF "$bin" ~/.zshrc 2>/dev/null \
  || echo "export PATH=\"$bin:\$PATH\"" >> ~/.zshrc

# Jupyter, in a virtual environment, only when that Python has none. Quarto
# picks it up as the python3 on PATH.
if [ -z "$py" ]; then
  python3 -m venv ~/.venvs/juphyter
  ~/.venvs/juphyter/bin/pip install jupyterlab
  export PATH="$HOME/.venvs/juphyter/bin:$PATH"
  grep -qF '.venvs/juphyter/bin' ~/.zshrc 2>/dev/null \
    || echo 'export PATH="$HOME/.venvs/juphyter/bin:$PATH"' >> ~/.zshrc
  py="$HOME/.venvs/juphyter/bin/python"
fi

# The kernelspec, naming that Python and the PHP on PATH.
juphyter install --python "$py"

# Check: a Jupyter version, and php among the kernels.
quarto check jupyter
```

On a machine running bash, write `~/.bashrc` in place of `~/.zshrc`.

The block changes as little as it can. When the `python3` that Quarto will use
already has Jupyter, it installs the kernel against that interpreter and adds
nothing to `PATH` beyond Composer's bin directory. It builds a virtual
environment only when that interpreter has no Jupyter, which is the usual case
on macOS, where `python3` is a Homebrew build.

`juphyter install` can build that environment itself. On a machine with no
Python that can host the kernel it offers to create one and installs the kernel
into it, and `--create-venv PATH` answers that offer without being asked. The
block above does the same work a step at a time, which is what makes it safe to
paste.

`quarto check jupyter` is the check that catches a half-working install. It has
to report a Jupyter version and `php` among the kernels:

```
Path: /Users/you/.venvs/juphyter/bin/python
Jupyter: 5.9.1
Kernels: php, python3
```

A terminal that was already open when you ran the block still carries the old
`PATH`, so run the check in the terminal you pasted into, or in a new one.

### If a render says the kernel is not found

```
ERROR: Jupyter kernel 'php' not found. Known kernels: python3
```

Quarto is running a Python that has no Jupyter in it, so it can see no kernel
at all. The kernel itself is fine, as `jupyter kernelspec list` will show.
`quarto check jupyter` prints the interpreter Quarto chose, and a
`Jupyter: (None)` line there is this failure. Put a Python that has Jupyter
first on `PATH`, as the block above does.

`QUARTO_PYTHON` names an interpreter outright, for one shell or one project.
Exporting it from a shell startup file is worth avoiding, because it takes
precedence over an activated virtual environment and would change the
interpreter for every Quarto project on the machine.

## Your first document

Write a document in any directory, with no project around it:

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

`quarto render hello.qmd` runs the cell and puts its output in the HTML. In
JupyterLab, started with `jupyter lab`, the same kernel appears in the launcher
under the PHP version it found.

Add a kernel for another PHP with its own name, which is how two versions sit
side by side:

```bash
juphyter install --name php85 --php /path/to/php85
```

## With the phpstats packages

The install above runs plain PHP. phibble tables and phiz plots come from
separate packages, and a second kernel gives them to a document in any
directory:

```bash
for r in phibble phplyr; do
  composer global config "repositories.$r" \
    "{\"type\":\"vcs\",\"url\":\"https://github.com/phpstats/$r\",\"no-api\":true}"
done
composer global require --prefer-source "phpstats/phibble:^0.3" "phpstats/phplyr:^0.3"

juphyter install --name phpstats --display-name "PHP (phpstats)" \
  --autoload "$(composer global config home 2>/dev/null)/vendor/autoload.php"
```

Name that kernel in the front matter as `jupyter: phpstats`. It is a second
kernel rather than a change to the first because `--autoload` fixes one
autoloader for every document, which suits a document outside any project and
breaks one inside a project. The `php` kernel keeps the default: it walks up
from the document's directory and loads the nearest `vendor/autoload.php`, so a
document inside a Composer project sees that project's own packages.

phiz needs PHP 8.5 and has to be left out below it. On 8.5 or newer, add it
with `composer global require --prefer-source "phpstats/phiz:^0.3"`.

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
