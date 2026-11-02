# juphyter

juphyter is a Jupyter kernel for PHP. It runs PHP cells in JupyterLab
notebooks and in Quarto documents, keeps one PHP session across cells, and
shows [phibble](https://phibble.phpstats.org) data frames as tables and
[phiz](https://phiz.phpstats.org) plots as charts.

Documentation: <https://juphyter.phpstats.org>

## Features

- One PHP session across cells, including `use` imports and declared
  functions and classes.
- HTML tables for phibble data frames and charts for phiz plots, plus
  `toHtml()` and `toMimeBundle()` hooks for any class.
- `Juphyter\display()` to show values part way through a cell.
- Tracebacks that name the cell and the line.
- Completion of variables, functions, classes, and members.
- Interrupting a running cell, when PHP has the `pcntl` extension.
- The nearest `vendor/autoload.php` above a notebook is loaded, so a notebook
  inside a Composer project can use its classes and packages.

## Requirements

- PHP 8.3, 8.4, or 8.5, with the `mbstring` and `tokenizer` extensions.
- Composer.
- Python 3 with the `venv` module.
- macOS or Linux.

The `pcntl` extension is optional and makes the stop button interrupt a
running cell. Quarto is optional and renders `.qmd` documents.

## Installation

```bash
composer global require phpstats/juphyter
export PATH="$(composer global config bin-dir --absolute):$PATH"
juphyter install --create-venv ~/.venvs/juphyter
export PATH="$HOME/.venvs/juphyter/bin:$PATH"
juphyter doctor
```

`juphyter install` registers the kernel as `php`. It uses an existing Python
environment that has ipykernel 7, or builds one with JupyterLab at
`~/.venvs/juphyter`. The `export` lines last for the current shell.
`juphyter doctor` checks the installation and exits 1 when something is
broken. The [installation guide](https://juphyter.phpstats.org/docs/install)
covers keeping the `PATH` entries, the PHPstats packages, and per-project
installs.

## Usage

In JupyterLab, run `jupyter lab` and choose the PHP kernel. In Quarto, name the
kernel in the front matter:

````markdown
---
title: "Fuel economy"
jupyter: php
---

```{php}
$mtcars = \Phibble\DataFrame::fromCsv(
    'https://raw.githubusercontent.com/phpstats/datasets/main/csv/mtcars.csv'
);
$mtcars;
```
````

The cell reads [mtcars](https://github.com/phpstats/datasets#mtcars) from the
PHPstats datasets repository, so the document sits in a Composer project that
requires `phpstats/phibble`. Save it as `mtcars.qmd` and run
`quarto render mtcars.qmd`.

## Contributing

[Contributing](https://juphyter.phpstats.org/docs/development) covers the
tests, the documentation build, and the PHAR.

## License

MIT
