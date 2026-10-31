# juphyter

juphyter is a Jupyter kernel for PHP. It runs PHP cells in JupyterLab
notebooks and in Quarto documents, keeps one PHP session across cells, and
shows [phibble](https://phibble.phpstats.org) data frames as tables and
[phiz](https://phiz.phpstats.org) plots as charts.

Documentation: <https://juphyter.phpstats.org>

## Requirements

- PHP 8.3, 8.4, or 8.5, with the `mbstring` and `tokenizer` extensions.
- Composer.
- Python 3 with the `venv` module.
- macOS or Linux.

The `pcntl` extension is optional and makes the stop button interrupt a
running cell. Quarto is optional and renders `.qmd` documents.

## Install

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
covers keeping the `PATH` entries, the phpstats packages, and per-project
installs.

## Use

In JupyterLab, run `jupyter lab` and choose the PHP kernel. In Quarto, name the
kernel in the front matter:

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

Then run `quarto render hello.qmd`.

## Development

[Contributing](https://juphyter.phpstats.org/docs/development) covers the
tests, the documentation build, and the PHAR.

## License

MIT
