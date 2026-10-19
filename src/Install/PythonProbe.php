<?php

declare(strict_types=1);

namespace Juphyter\Install;

use Closure;
use RuntimeException;

/**
 * Choosing the Python interpreter that will run the wrapper kernel.
 *
 * The kernelspec names an interpreter by absolute path, and it has to be the
 * interpreter that the user's Jupyter runs under. A machine often has several:
 * the system `python3`, one or more Homebrew builds, and a virtual environment
 * per project. Installing against the wrong one produces a kernelspec that
 * JupyterLab lists and cannot start, because `ipykernel` is missing there.
 *
 * The probe therefore starts from `jupyter` on `PATH` and reads its shebang.
 * A console script written by pip names its own interpreter on the first line,
 * so the shebang of `jupyter` identifies the environment that owns Jupyter.
 * Whatever candidate comes out of that is then asked for its own
 * `sys.executable`, which resolves a symlinked or wrapper interpreter to the
 * real one, and is asked whether `ipykernel` imports.
 *
 * ## Injection
 *
 * Every subprocess goes through the runner passed to the constructor, so the
 * unit tests drive the whole decision with canned results and need no Python.
 * The runner takes an argument list and returns the exit code with both
 * streams:
 *
 * ```php
 * $runner = function (array $argv): array {
 *     return ['code' => 0, 'stdout' => "/usr/bin/python3\n", 'stderr' => ''];
 * };
 * ```
 *
 * `PATH` is a constructor argument for the same reason. Locating an executable
 * on it is done by reading the directories rather than by shelling out to
 * `command -v`, which keeps the search cheap and lets a test point `PATH` at a
 * directory of fixture scripts.
 *
 * @phpstan-type ProbeResult array{code: int, stdout: string, stderr: string}
 */
final class PythonProbe
{
    /**
     * The lowest `ipykernel` the wrapper kernel supports.
     *
     * The wrapper subclasses `ipykernel.kernelbase.Kernel` against the 7.x API.
     */
    public const IPYKERNEL_FLOOR = '7.0';

    /** @var Closure(list<string>): ProbeResult */
    private Closure $runner;

    /** The `PATH` to search, as a colon-separated list. */
    private string $path;

    /**
     * @param  (Closure(list<string>): ProbeResult)|null  $runner  How to run a subprocess; defaults to {@see processRunner()}.
     * @param  string|null  $path  The `PATH` to search; defaults to the process's own.
     */
    public function __construct(?Closure $runner = null, ?string $path = null)
    {
        $this->runner = $runner ?? self::processRunner();
        $this->path = $path ?? (string) getenv('PATH');
    }

    /**
     * A runner that actually starts processes.
     *
     * The arguments are passed to {@see proc_open()} as a list, so the shell
     * never sees them and nothing needs quoting.
     *
     * @return Closure(list<string>): ProbeResult
     */
    public static function processRunner(): Closure
    {
        return function (array $argv): array {
            $command = $argv[0] ?? '';

            // A path that is not there is reported rather than attempted,
            // because proc_open() raises a diagnostic of its own for it and the
            // caller wants the answer, not the notice.
            if ($command === '' || (str_contains($command, '/') && ! is_executable($command))) {
                return [
                    'code' => 127,
                    'stdout' => '',
                    'stderr' => $command === ''
                        ? 'no command was given'
                        : sprintf('%s is not an executable file', $command),
                ];
            }

            $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
            $pipes = [];

            $process = @proc_open($argv, $descriptors, $pipes);

            if (! is_resource($process)) {
                return ['code' => 127, 'stdout' => '', 'stderr' => 'could not be started'];
            }

            $stdout = (string) stream_get_contents($pipes[1]);
            $stderr = (string) stream_get_contents($pipes[2]);

            fclose($pipes[1]);
            fclose($pipes[2]);

            return ['code' => proc_close($process), 'stdout' => $stdout, 'stderr' => $stderr];
        };
    }

    /**
     * The interpreter to name in the kernelspec.
     *
     * @param  string|null  $explicit  A `--python` value, which skips the search.
     * @throws RuntimeException When no candidate is found, or the candidate does not run.
     */
    public function interpreter(?string $explicit = null): string
    {
        $candidate = $explicit !== null && $explicit !== ''
            ? $explicit
            : $this->candidate();

        $result = ($this->runner)([$candidate, '-c', 'import sys; print(sys.executable)']);

        if ($result['code'] !== 0) {
            throw new RuntimeException(sprintf(
                "juphyter: %s is not a working Python interpreter.\n%s",
                $candidate,
                trim($result['stderr']) !== '' ? trim($result['stderr']) : 'It exited with code '
                    .$result['code'].'.',
            ));
        }

        $executable = trim($result['stdout']);

        if ($executable === '') {
            throw new RuntimeException(sprintf(
                'juphyter: %s did not report its own path, so juphyter cannot name it in the '
                .'kernelspec. Pass --python with the interpreter that runs your Jupyter.',
                $candidate,
            ));
        }

        return $executable;
    }

    /**
     * The `ipykernel` version in an interpreter, or null when it does not import.
     */
    public function ipykernelVersion(string $interpreter): ?string
    {
        $result = ($this->runner)([
            $interpreter, '-c', 'import ipykernel; print(ipykernel.__version__)',
        ]);

        if ($result['code'] !== 0) {
            return null;
        }

        $version = trim($result['stdout']);

        return $version === '' ? null : $version;
    }

    /**
     * Confirm that an interpreter can host the wrapper kernel.
     *
     * @return string The `ipykernel` version found there.
     * @throws RuntimeException With the exact install command, when it cannot.
     */
    public function requireIpykernel(string $interpreter): string
    {
        $version = $this->ipykernelVersion($interpreter);

        if ($version === null) {
            throw new RuntimeException(sprintf(
                "juphyter: ipykernel is not installed in %s, and the PHP kernel runs inside it.\n"
                ."Install it there, then run this command again:\n\n    %s\n",
                $interpreter,
                self::installCommand($interpreter),
            ));
        }

        if (version_compare($version, self::IPYKERNEL_FLOOR, '<')) {
            throw new RuntimeException(sprintf(
                "juphyter: %s has ipykernel %s, and the PHP kernel needs %s or newer.\n"
                ."Upgrade it there, then run this command again:\n\n    %s\n",
                $interpreter,
                $version,
                self::IPYKERNEL_FLOOR,
                self::installCommand($interpreter),
            ));
        }

        return $version;
    }

    /** The command that installs a usable `ipykernel` into an interpreter. */
    public static function installCommand(string $interpreter): string
    {
        return sprintf('%s -m pip install "ipykernel>=%s"', $interpreter, self::IPYKERNEL_FLOOR);
    }

    /**
     * The first interpreter worth asking about.
     *
     * @throws RuntimeException When `PATH` holds neither `jupyter` nor a Python.
     */
    private function candidate(): string
    {
        $jupyter = $this->locate('jupyter');

        if ($jupyter !== null) {
            $owner = $this->shebangInterpreter($jupyter);

            if ($owner !== null) {
                return $owner;
            }
        }

        foreach (['python3', 'python'] as $name) {
            $python = $this->locate($name);

            if ($python !== null) {
                return $python;
            }
        }

        throw new RuntimeException(
            'juphyter: no jupyter and no python3 on PATH. Jupyter has to be installed before '
            .'the PHP kernel can be registered with it. Pass --python to name an interpreter '
            .'that is not on PATH.'
        );
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

    /**
     * The interpreter named by a script's shebang, when it names a usable one.
     *
     * pip writes `#!/path/to/python` into a console script, quoting the path
     * when it holds a space. A relocatable install can write `#!python`, and a
     * script can go through `#!/usr/bin/env python3`; both are resolved on
     * `PATH`. Anything else, such as a `#!/bin/sh` wrapper, returns null and
     * leaves the caller to fall back.
     */
    private function shebangInterpreter(string $script): ?string
    {
        $handle = @fopen($script, 'rb');

        if ($handle === false) {
            return null;
        }

        $line = (string) fgets($handle, 4096);
        fclose($handle);

        if (! str_starts_with($line, '#!')) {
            return null;
        }

        $line = trim(substr($line, 2));

        if ($line === '') {
            return null;
        }

        if (str_starts_with($line, '"')) {
            // A quoted path holds the space that made pip quote it, so the line
            // is read to the closing quote rather than split on whitespace.
            $end = strpos($line, '"', 1);
            $interpreter = $end === false ? substr($line, 1) : substr($line, 1, $end - 1);
        } else {
            $words = preg_split('/\s+/', $line, -1, PREG_SPLIT_NO_EMPTY) ?: [];

            // `#!/usr/bin/env python3` names the interpreter in the second word.
            if ($words !== [] && basename($words[0]) === 'env') {
                $words = array_slice($words, 1);

                // Skip `env`'s own options and assignments, such as `-S` or `FOO=1`.
                while ($words !== [] && (str_starts_with($words[0], '-') || str_contains($words[0], '='))) {
                    $words = array_slice($words, 1);
                }
            }

            $interpreter = $words[0] ?? '';
        }

        if ($interpreter === '' || ! str_contains(strtolower(basename($interpreter)), 'python')) {
            return null;
        }

        if (! str_contains($interpreter, '/')) {
            return $this->locate($interpreter);
        }

        return is_file($interpreter) && is_executable($interpreter) ? $interpreter : null;
    }
}
