<?php

declare(strict_types=1);

namespace Juphyter\Install;

use RuntimeException;

/**
 * Where Jupyter looks for kernelspecs.
 *
 * This reimplements the part of `jupyter_core.paths` that juphyter needs, so
 * that `juphyter install` can place a kernelspec without first starting a
 * Python interpreter. The rules below are the ones in
 * `jupyter_core/paths.py::jupyter_data_dir`, restricted to the platforms this
 * package supports:
 *
 * 1. `JUPYTER_DATA_DIR`, when it is set to a non-empty value, is the data
 *    directory and nothing else is consulted.
 * 2. When `JUPYTER_PLATFORM_DIRS` is set to anything other than a falsey word,
 *    jupyter_core defers to platformdirs, which puts the data directory at
 *    `~/Library/Application Support/jupyter` on macOS and at the XDG location
 *    elsewhere.
 * 3. Otherwise macOS uses `~/Library/Jupyter`, and Linux and the other Unixes
 *    use `$XDG_DATA_HOME/jupyter`, falling back to `~/.local/share/jupyter`.
 *
 * A `--prefix` install ignores all of that and writes under
 * `<prefix>/share/jupyter`, which is the layout `ENV_JUPYTER_PATH` and
 * `SYSTEM_JUPYTER_PATH` describe and the one `jupyter kernelspec install
 * --prefix` produces.
 *
 * The environment and the operating system family are constructor arguments
 * rather than reads of the ambient process, so the tests can ask for a Linux
 * path on macOS and can set `JUPYTER_DATA_DIR` without touching the real
 * environment. {@see fromEnvironment()} is the production wiring.
 */
final class JupyterPaths
{
    /** The words jupyter_core's `envset()` reads as false. */
    private const FALSEY = ['no', 'n', 'false', 'off', '0', '0.0'];

    /**
     * @param  array<string, string>  $environment  Environment variables, as `getenv()` returns them.
     * @param  string  $osFamily  A `PHP_OS_FAMILY` value: `Darwin`, `Linux`, `BSD`, `Solaris`, or `Windows`.
     */
    public function __construct(
        private readonly array $environment,
        private readonly string $osFamily,
    ) {}

    /** The paths of the running process. */
    public static function fromEnvironment(): self
    {
        /** @var array<string, string> $environment */
        $environment = getenv();

        return new self($environment, PHP_OS_FAMILY);
    }

    /**
     * The per-user Jupyter data directory.
     *
     * @throws RuntimeException When the platform is unsupported or `HOME` is unset.
     */
    public function dataDirectory(): string
    {
        $explicit = $this->environment['JUPYTER_DATA_DIR'] ?? '';

        if ($explicit !== '') {
            return $this->clean($explicit);
        }

        if ($this->osFamily === 'Windows') {
            throw new RuntimeException(
                'juphyter runs on macOS and Linux only, because the kernel needs POSIX signals.'
            );
        }

        if ($this->osFamily === 'Darwin') {
            return $this->platformDirs()
                ? $this->clean($this->home().'/Library/Application Support/jupyter')
                : $this->clean($this->home().'/Library/Jupyter');
        }

        return $this->clean($this->xdgDataHome().'/jupyter');
    }

    /**
     * The directory that holds one kernelspec per subdirectory.
     *
     * @param  string|null  $prefix  An install prefix, as `--prefix` gives it.
     */
    public function kernelsDirectory(?string $prefix = null): string
    {
        if ($prefix !== null && $prefix !== '') {
            return $this->clean($prefix.'/share/jupyter/kernels');
        }

        return $this->dataDirectory().'/kernels';
    }

    /** The directory of one kernelspec, which holds its `kernel.json`. */
    public function kernelDirectory(string $name, ?string $prefix = null): string
    {
        return $this->kernelsDirectory($prefix).'/'.$name;
    }

    /** Whether `JUPYTER_PLATFORM_DIRS` asks for the platformdirs locations. */
    private function platformDirs(): bool
    {
        if (! array_key_exists('JUPYTER_PLATFORM_DIRS', $this->environment)) {
            return false;
        }

        return ! in_array(
            strtolower($this->environment['JUPYTER_PLATFORM_DIRS']),
            self::FALSEY,
            true,
        );
    }

    /** `XDG_DATA_HOME`, or its default. */
    private function xdgDataHome(): string
    {
        $xdg = $this->environment['XDG_DATA_HOME'] ?? '';

        return $xdg !== '' ? $xdg : $this->home().'/.local/share';
    }

    /** @throws RuntimeException When `HOME` is unset. */
    private function home(): string
    {
        $home = $this->environment['HOME'] ?? '';

        if ($home === '') {
            throw new RuntimeException(
                'juphyter cannot find the Jupyter data directory because HOME is not set. '
                .'Set JUPYTER_DATA_DIR or pass --prefix.'
            );
        }

        return $home;
    }

    /** Drop a trailing separator and collapse a doubled one, leaving the root alone. */
    private function clean(string $path): string
    {
        $collapsed = (string) preg_replace('#(?<!^)/{2,}#', '/', $path);

        return $collapsed === '/' ? $collapsed : rtrim($collapsed, '/');
    }
}
