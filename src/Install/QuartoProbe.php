<?php

declare(strict_types=1);

namespace Juphyter\Install;

use Closure;

/**
 * Which Python Quarto will run, and whether a kernel can start under it.
 *
 * Quarto chooses its own interpreter, independently of the one the kernelspec
 * names, and that interpreter is the one that has to have Jupyter in it,
 * because Jupyter starts the kernel and collects its output. The two are
 * allowed to differ: the kernelspec's Python needs `ipykernel` and Quarto's
 * needs the client that launches it. A mismatch is therefore not by itself a
 * problem, and this probe never decides which interpreter to record.
 *
 * Without it, `juphyter install` can report a correct install that cannot
 * render. On a typical macOS machine the `python3` on `PATH` is a Homebrew
 * build with no Jupyter in it, and `quarto render` then fails with
 * `Jupyter kernel 'php' not found` while `jupyter kernelspec list` shows the
 * kernel installed and working.
 *
 * ## The resolution order, measured on Quarto 1.10.18
 *
 * `QUARTO_PYTHON` is taken when it is set, and otherwise the first `python3` on
 * `PATH`. `VIRTUAL_ENV` is not honored: an environment that is active only
 * through that variable is ignored, and an activated environment works through
 * `PATH` alone. There is no fallback to `python`. With a working `python` on
 * `PATH` and no `python3` anywhere on it, Quarto reports that it is unable to
 * locate an installed version of Python 3, so this probe looks for `python3`
 * and nothing else.
 *
 * The order belongs to Quarto and a later release could change it. Everything
 * here is advisory and the install succeeds either way, so a wrong guess
 * misleads rather than breaks. The report says that Quarto *appears* to use an
 * interpreter and names `quarto check jupyter` as the authority.
 *
 * ## Injection
 *
 * Every subprocess goes through the runner passed to the constructor, and
 * `PATH` and the environment are arguments for the same reason, so the unit
 * tests drive the whole decision with canned results and need neither Python
 * nor Quarto. {@see PythonProbe} documents the runner's shape, which this
 * shares.
 *
 * @phpstan-type ProbeResult array{code: int, stdout: string, stderr: string}
 * @phpstan-type Resolution array{interpreter: string, source: string, jupyterClient: string|null, problem: string|null}
 */
final class QuartoProbe
{
    /** The variable Quarto reads before it looks at `PATH`. */
    public const OVERRIDE = 'QUARTO_PYTHON';

    /** @var Closure(list<string>): ProbeResult */
    private Closure $runner;

    /** The `PATH` to search, as a colon-separated list. */
    private string $path;

    /** @var array<string, string> */
    private array $environment;

    /**
     * @param  (Closure(list<string>): ProbeResult)|null  $runner  How to run a subprocess; defaults to {@see PythonProbe::processRunner()}.
     * @param  string|null  $path  The `PATH` to search; defaults to the process's own.
     * @param  array<string, string>|null  $environment  Where `QUARTO_PYTHON` is read; defaults to the process's own.
     */
    public function __construct(
        ?Closure $runner = null,
        ?string $path = null,
        ?array $environment = null,
    ) {
        /** @var array<string, string> $ambient */
        $ambient = getenv();

        $this->runner = $runner ?? PythonProbe::processRunner();
        $this->path = $path ?? (string) getenv('PATH');
        $this->environment = $environment ?? $ambient;
    }

    /**
     * Whether Quarto is installed at all.
     *
     * A user with no Quarto has nothing to fix and is told nothing about it, so
     * the caller skips the whole check when this is false.
     */
    public function installed(): bool
    {
        return $this->locate('quarto') !== null;
    }

    /**
     * The interpreter Quarto appears to choose, and what was found in it.
     *
     * The candidate is asked for its own `sys.executable`, which resolves a
     * symlink or a wrapper to the interpreter Quarto itself reports, so that
     * the line in the install report and `quarto check jupyter` name the same
     * path. A candidate that does not run comes back with `problem` set rather
     * than as an exception, because a `QUARTO_PYTHON` naming a path that was
     * moved or is not executable is a thing to report and not a reason to fail
     * an install.
     *
     * @return Resolution|null Null when there is no interpreter for Quarto to use.
     */
    public function resolve(): ?array
    {
        $override = trim($this->environment[self::OVERRIDE] ?? '');
        $named = $override !== '' ? $override : $this->locate('python3');

        if ($named === null) {
            return null;
        }

        $source = $override !== '' ? self::OVERRIDE : 'PATH';
        $result = ($this->runner)([$named, '-c', 'import sys; print(sys.executable)']);
        $executable = trim($result['stdout']);

        if ($result['code'] !== 0 || $executable === '') {
            return [
                'interpreter' => $named,
                'source' => $source,
                'jupyterClient' => null,
                'problem' => self::reason($result, $executable),
            ];
        }

        return [
            'interpreter' => $executable,
            'source' => $source,
            'jupyterClient' => $this->jupyterClientVersion($executable),
            'problem' => null,
        ];
    }

    /**
     * The `jupyter_client` version in an interpreter, or null when it does not import.
     *
     * This is the whole of what Quarto's interpreter has to have. The caller
     * asks it of the recorded interpreter too, to decide whether putting that
     * one on `PATH` is worth suggesting.
     */
    public function jupyterClientVersion(string $interpreter): ?string
    {
        $result = ($this->runner)([
            $interpreter, '-c', 'import jupyter_client; print(jupyter_client.__version__)',
        ]);

        if ($result['code'] !== 0) {
            return null;
        }

        $version = trim($result['stdout']);

        return $version === '' ? null : $version;
    }

    /**
     * Why a candidate could not be asked anything, in a few words.
     *
     * @param  ProbeResult  $result
     */
    private static function reason(array $result, string $executable): string
    {
        if ($result['code'] === 0 && $executable === '') {
            return 'it ran and did not report its own path';
        }

        return trim($result['stderr']) !== ''
            ? trim($result['stderr'])
            : 'it exited with code '.$result['code'];
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
