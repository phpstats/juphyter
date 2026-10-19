<?php

declare(strict_types=1);

namespace Juphyter\Console;

use Juphyter\Install\JupyterPaths;
use Juphyter\Install\KernelSpec;
use RuntimeException;

/**
 * `juphyter uninstall`: remove a kernelspec juphyter installed.
 *
 * The command resolves the kernelspec directory the same way `install` does,
 * from `JUPYTER_DATA_DIR` or `--prefix`, reads the `kernel.json` it finds there,
 * and removes the directory only when {@see KernelSpec::isJuphyter()} recognizes
 * the file. A kernelspec written by something else, or a directory holding no
 * `kernel.json` at all, is reported and left alone. Nothing here searches the
 * other entries on the Jupyter path, so `uninstall` can only remove what the
 * matching `install` would have written.
 *
 * Removing the directory also removes the copy of the Python wrapper that a
 * PHAR install puts inside it.
 */
final class UninstallCommand extends Command
{
    /** @var list<string> */
    private const OPTIONS = ['name', 'prefix'];

    /** @var resource */
    private $stdout;

    /** @var resource */
    private $stderr;

    /**
     * @param  JupyterPaths  $paths  Where kernelspecs live.
     * @param  resource|null  $stdout
     * @param  resource|null  $stderr
     */
    public function __construct(
        private readonly JupyterPaths $paths,
        $stdout = null,
        $stderr = null,
    ) {
        $this->stdout = $stdout ?? STDOUT;
        $this->stderr = $stderr ?? STDERR;
    }

    public static function description(): string
    {
        return 'Remove a kernelspec juphyter installed';
    }

    public static function make(): static
    {
        return new self(JupyterPaths::fromEnvironment());
    }

    public function run(array $arguments): int
    {
        if (in_array('--help', $arguments, true) || in_array('-h', $arguments, true)) {
            fwrite($this->stdout, self::usage());

            return 0;
        }

        try {
            return $this->uninstall(self::parseOptions($arguments));
        } catch (RuntimeException $e) {
            fwrite($this->stderr, rtrim($e->getMessage(), "\n")."\n");

            return 1;
        }
    }

    /**
     * @param  array<string, string>  $options
     * @throws RuntimeException When there is nothing to remove, or it is not juphyter's.
     */
    private function uninstall(array $options): int
    {
        $name = KernelSpec::normalizeName($options['name'] ?? InstallCommand::DEFAULT_NAME);
        $directory = $this->paths->kernelDirectory($name, $options['prefix'] ?? null);
        $file = $directory.'/kernel.json';

        if (! is_file($file)) {
            throw new RuntimeException(sprintf(
                'juphyter: no kernel named "%s" at %s.',
                $name,
                $directory,
            ));
        }

        $decoded = json_decode((string) @file_get_contents($file), true);

        if (! KernelSpec::isJuphyter($decoded)) {
            throw new RuntimeException(sprintf(
                'juphyter: the kernelspec at %s was not written by juphyter, so it was left '
                .'alone. Remove it yourself if that is what you meant.',
                $file,
            ));
        }

        self::removeDirectory($directory);

        fwrite($this->stdout, sprintf(
            "Removed the juphyter kernel \"%s\" from %s.\n",
            $name,
            $directory,
        ));

        return 0;
    }

    /**
     * Delete a directory and its contents.
     *
     * A symlink inside it is unlinked rather than followed, so the deletion
     * stays inside the kernelspec.
     *
     * @throws RuntimeException When something there cannot be removed.
     */
    private static function removeDirectory(string $directory): void
    {
        foreach (scandir($directory) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $directory.'/'.$entry;

            if (is_dir($path) && ! is_link($path)) {
                self::removeDirectory($path);

                continue;
            }

            if (! @unlink($path)) {
                throw new RuntimeException(sprintf('juphyter: could not remove %s.', $path));
            }
        }

        if (! @rmdir($directory)) {
            throw new RuntimeException(sprintf('juphyter: could not remove %s.', $directory));
        }
    }

    /**
     * Read `--option value` and `--option=value` pairs.
     *
     * `install` has its own copy of this, shaped to its own option list, because
     * the two commands are the only callers and a shared helper would be a third
     * class for twenty lines.
     *
     * @param  list<string>  $arguments
     * @return array<string, string>
     * @throws RuntimeException On an unknown option, a missing value, or a stray argument.
     */
    private static function parseOptions(array $arguments): array
    {
        $options = [];
        $count = count($arguments);

        for ($i = 0; $i < $count; $i++) {
            $argument = $arguments[$i];

            if (! str_starts_with($argument, '--')) {
                throw new RuntimeException(sprintf(
                    "juphyter: unexpected argument \"%s\".\n\n%s",
                    $argument,
                    self::usage(),
                ));
            }

            $name = substr($argument, 2);
            $value = null;

            if (str_contains($name, '=')) {
                [$name, $value] = explode('=', $name, 2);
            }

            if (! in_array($name, self::OPTIONS, true)) {
                throw new RuntimeException(sprintf(
                    "juphyter: unknown option \"--%s\".\n\n%s",
                    $name,
                    self::usage(),
                ));
            }

            if ($value === null) {
                $next = $arguments[$i + 1] ?? null;

                if ($next === null || str_starts_with($next, '--')) {
                    throw new RuntimeException(sprintf('juphyter: --%s needs a value.', $name));
                }

                $value = $next;
                $i++;
            }

            $options[$name] = $value;
        }

        return $options;
    }

    /** The command's own help. */
    public static function usage(): string
    {
        return <<<'TEXT'
            Usage: juphyter uninstall [options]

            Removes a kernelspec that juphyter installed.

            Options:
              --name NAME    The kernel to remove. Default: php.
              --prefix PATH  Look under PATH/share/jupyter instead of the per-user
                             Jupyter data directory.
              -h, --help     Print this text.

            TEXT;
    }
}
