<?php

declare(strict_types=1);

namespace Juphyter\Install;

use Closure;
use RuntimeException;

/**
 * Building a Python that can host the kernel, on a machine that has none.
 *
 * The kernelspec names an interpreter with `ipykernel` 7 or newer, and a
 * machine whose only Python is the system or Homebrew build often has none and
 * no supported way to add one: those builds are marked externally managed, so
 * `pip install` into them refuses. A virtual environment is the way out, and
 * `juphyter install --create-venv` builds one here rather than describing it.
 *
 * What goes into it is `jupyterlab`, which is what the documentation tells a
 * reader to install by hand. It brings `ipykernel`, which the kernelspec needs,
 * and `jupyter_client`, which Quarto needs to start a kernel at all, so one
 * environment answers both. {@see QuartoProbe} explains the second.
 *
 * Putting the environment on `PATH` is left to the reader. The warning that
 * says so is {@see \Juphyter\Console\InstallCommand}'s, which already reports
 * what Quarto will use and is the place that knows whether `PATH` is the
 * problem.
 *
 * ## Injection
 *
 * Every subprocess goes through the runner passed to the constructor, and
 * `PATH` and the environment are arguments for the same reason, so the unit
 * tests drive the decisions with canned results and build no environment.
 * {@see PythonProbe} documents the runner's shape, which this shares.
 *
 * @phpstan-type ProbeResult array{code: int, stdout: string, stderr: string}
 */
final class VenvBuilder
{
    /**
     * What is installed into a new environment.
     *
     * JupyterLab rather than the two libraries the kernel strictly needs,
     * because the documented install is JupyterLab and a reader who asked for
     * an environment to be built expects `jupyter lab` to work in it.
     */
    public const PACKAGES = ['jupyterlab'];

    /** Where an environment goes when the reader names no directory. */
    public const DEFAULT_LEAF = '/.venvs/juphyter';

    /** @var Closure(list<string>): ProbeResult */
    private Closure $runner;

    /** The `PATH` to search for a base interpreter. */
    private string $path;

    /** @var array<string, string> */
    private array $environment;

    /**
     * @param  (Closure(list<string>): ProbeResult)|null  $runner  How to run a subprocess; defaults to {@see PythonProbe::processRunner()}.
     * @param  string|null  $path  The `PATH` to search; defaults to the process's own.
     * @param  array<string, string>|null  $environment  Where `HOME` is read; defaults to the process's own.
     */
    public function __construct(
        ?Closure $runner = null,
        ?string $path = null,
        ?array $environment = null,
    ) {
        $this->runner = $runner ?? PythonProbe::processRunner();
        $this->path = $path ?? (string) getenv('PATH');

        if ($environment !== null) {
            $this->environment = $environment;
        } else {
            /** @var array<string, string> $ambient */
            $ambient = getenv();
            $this->environment = $ambient;
        }
    }

    /**
     * The directory an environment goes in when none is named.
     *
     * Null when `HOME` is unset, in which case there is nowhere to default to
     * and the caller asks the reader for a directory instead.
     */
    public function defaultDirectory(): ?string
    {
        $home = rtrim($this->environment['HOME'] ?? '', '/');

        return $home === '' ? null : $home.self::DEFAULT_LEAF;
    }

    /**
     * Build an environment, or adopt one already at that path, and return its
     * interpreter.
     *
     * An existing environment is reused rather than rebuilt, and the packages
     * are installed into it either way, which is what makes a second run after
     * an interrupted `pip` finish the job.
     *
     * @throws RuntimeException When the directory is unusable, no base
     *                          interpreter exists, or a step fails.
     */
    public function build(string $directory): string
    {
        $directory = rtrim($directory, '/');

        if ($directory === '') {
            throw new RuntimeException('juphyter: --create-venv needs a directory.');
        }

        $interpreter = $directory.'/bin/python';

        if (! $this->runs($interpreter)) {
            $this->create($directory, $interpreter);
        }

        $this->install($interpreter);

        return $interpreter;
    }

    /** Whether an interpreter is there and runs. */
    private function runs(string $interpreter): bool
    {
        $result = ($this->runner)([$interpreter, '-c', 'import sys']);

        return $result['code'] === 0;
    }

    /**
     * @throws RuntimeException When the directory is occupied, there is no base
     *                          interpreter, or `venv` fails.
     */
    private function create(string $directory, string $interpreter): void
    {
        if (is_dir($directory) && ! self::isEmpty($directory)) {
            throw new RuntimeException(sprintf(
                "juphyter: %s already exists and is not a virtual environment, so juphyter\n"
                .'will not write into it. Name an empty or absent directory instead.',
                $directory,
            ));
        }

        $base = $this->basePython();
        $result = ($this->runner)([$base, '-m', 'venv', $directory]);

        if ($result['code'] !== 0) {
            throw new RuntimeException(sprintf(
                "juphyter: %s could not create a virtual environment at %s.\n%s",
                $base,
                $directory,
                self::detail($result),
            ));
        }

        if (! $this->runs($interpreter)) {
            throw new RuntimeException(sprintf(
                'juphyter: %s reported success and %s does not run, so juphyter cannot use it.',
                $base,
                $interpreter,
            ));
        }
    }

    /**
     * @throws RuntimeException When `pip` fails.
     */
    private function install(string $interpreter): void
    {
        $result = ($this->runner)(
            [$interpreter, '-m', 'pip', 'install', ...self::PACKAGES]
        );

        if ($result['code'] !== 0) {
            throw new RuntimeException(sprintf(
                "juphyter: installing %s into %s failed.\n%s",
                implode(' ', self::PACKAGES),
                $interpreter,
                self::detail($result),
            ));
        }
    }

    /**
     * The interpreter to build the environment with.
     *
     * @throws RuntimeException When `PATH` holds no Python.
     */
    private function basePython(): string
    {
        foreach (['python3', 'python'] as $name) {
            $python = $this->locate($name);

            if ($python !== null) {
                return $python;
            }
        }

        throw new RuntimeException(
            'juphyter: there is no python3 on PATH to build a virtual environment with. '
            .'Install Python 3, then run this command again.'
        );
    }

    /** The last few lines of a failure, for a message that stays readable. */
    private static function detail(array $result): string
    {
        $text = trim($result['stderr']) !== '' ? trim($result['stderr']) : trim($result['stdout']);

        if ($text === '') {
            return 'It exited with code '.$result['code'].'.';
        }

        $lines = explode("\n", $text);

        return implode("\n", array_slice($lines, -5));
    }

    /** Whether a directory holds nothing but `.` and `..`. */
    private static function isEmpty(string $directory): bool
    {
        $entries = @scandir($directory);

        if ($entries === false) {
            return false;
        }

        return array_values(array_diff($entries, ['.', '..'])) === [];
    }

    /** An executable of that name on `PATH`, or null. */
    private function locate(string $name): ?string
    {
        foreach (explode(PATH_SEPARATOR, $this->path) as $directory) {
            if ($directory === '') {
                continue;
            }

            $candidate = rtrim($directory, '/').'/'.$name;

            if (is_file($candidate) && is_executable($candidate)) {
                return $candidate;
            }
        }

        return null;
    }
}
