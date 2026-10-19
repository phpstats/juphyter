# juphyter

A Jupyter kernel for PHP, for statistical work with the
[phpstats](https://phpstats.org) packages. It runs PHP cells in JupyterLab and
in Quarto documents, and renders [phibble](https://github.com/phpstats/phibble)
tables and [phiz](https://github.com/phpstats/phiz) plots as notebook
output rather than as text.

## Install

```bash
composer require --dev phpstats/juphyter
vendor/bin/juphyter install
```

juphyter needs PHP 8.3 or later and a Python with Jupyter in it, because the
kernel's wrapper runs inside `ipykernel`. Both JupyterLab and Quarto's jupyter
engine already require that Python, so there is nothing extra to install for
either. See the [installation guide](https://phpstats.github.io/juphyter/install.html)
for several PHP versions side by side, the standalone PHAR, and static image
export.

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

In a notebook that table arrives as HTML with its column types, and a
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
runs on macOS and Linux. The [limitations page](https://phpstats.github.io/juphyter/limitations.html)
explains each one and why.

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
