<?php

declare(strict_types=1);

namespace Juphyter\Project;

use RuntimeException;

/**
 * Finding the project a notebook belongs to.
 *
 * Jupyter runs a kernel with its working directory set to the notebook's own
 * directory, so a notebook inside a Composer project can be given that
 * project's `phibble` and `phiz` with no configuration at all: the engine walks
 * up looking for `vendor/autoload.php` and requires the first one it finds.
 *
 * ## Precedence
 *
 * Most explicit wins:
 *
 * 1. `JUPHYTER_AUTOLOAD`, which the kernelspec sets and a user can override for
 *    one launch.
 * 2. `autoload` in the nearest {@see Config}, which belongs to the project and
 *    is committed with it.
 * 3. The walk up from the working directory, which is the no-configuration
 *    case.
 *
 * The environment beats the file because a kernelspec is chosen per launch
 * while the file is chosen per project, and the file beats the walk because
 * writing one is a deliberate act and finding one is not.
 *
 * ## When it is named but missing
 *
 * A path given in the environment or the config that is not a readable file is
 * an error, reported as an engine-level error event rather than ignored: the
 * user asked for that autoloader and would otherwise get a session that merely
 * cannot see their classes. A walk that finds nothing is not an error, because
 * a notebook outside any project is a perfectly ordinary thing.
 *
 * ## Loading twice
 *
 * juphyter's own autoloader is already in place when this runs, and the project
 * may well carry its own copy of juphyter. Two Composer autoloaders each have
 * their own include-once bookkeeping, so `src/functions.php` would be required
 * twice; it is guarded with `function_exists` for exactly this, and that guard
 * must stay.
 */
final class Autoload
{
    public const ENV = 'JUPHYTER_AUTOLOAD';

    /** How the autoloader was chosen, for the engine to report. */
    public const FROM_ENV = 'environment';

    public const FROM_CONFIG = 'config';

    public const FROM_SEARCH = 'search';

    public const NOT_FOUND = 'none';

    private function __construct(
        public readonly ?string $path,
        public readonly string $source,
    ) {}

    /**
     * Decide which autoloader this session should use.
     *
     * @param  string  $directory  Where to start the walk, normally the engine's
     *                             working directory.
     * @param  (callable(string): string|false)|null  $env  How to read the
     *                             environment, injected so a test need not
     *                             change the process's own.
     *
     * @throws RuntimeException When a named autoloader is missing.
     */
    public static function locate(string $directory, ?Config $config = null, ?callable $env = null): self
    {
        $env ??= static fn (string $name) => getenv($name);
        $fromEnv = $env(self::ENV);

        if (is_string($fromEnv) && $fromEnv !== '') {
            return new self(self::require($fromEnv, self::ENV), self::FROM_ENV);
        }

        $fromConfig = $config?->autoloadPath();

        if ($fromConfig !== null) {
            return new self(self::require($fromConfig, Config::FILE), self::FROM_CONFIG);
        }

        $found = self::search($directory);

        return $found === null
            ? new self(null, self::NOT_FOUND)
            : new self($found, self::FROM_SEARCH);
    }

    /**
     * The nearest `vendor/autoload.php` at or above $directory, or null.
     *
     * The first one found wins, which is the nearest, because a notebook inside
     * a package inside a monorepo means the package.
     */
    public static function search(string $directory): ?string
    {
        $current = $directory === '' ? '.' : $directory;
        $seen = [];

        while (true) {
            $resolved = realpath($current);

            if ($resolved === false || isset($seen[$resolved])) {
                return null;
            }

            $seen[$resolved] = true;
            $candidate = $resolved.DIRECTORY_SEPARATOR.'vendor'.DIRECTORY_SEPARATOR.'autoload.php';

            if (is_file($candidate)) {
                return $candidate;
            }

            $parent = dirname($resolved);

            if ($parent === $resolved) {
                return null;
            }

            $current = $parent;
        }
    }

    /**
     * Load the autoloader, if one was found.
     *
     * Separate from {@see locate()} so that a test can decide what was chosen
     * without pulling another project's code into the test runner.
     */
    public function load(): void
    {
        if ($this->path !== null) {
            require $this->path;
        }
    }

    /** A named path has to exist, unlike one merely found. */
    private static function require(string $path, string $named): string
    {
        if (! is_file($path)) {
            throw new RuntimeException(sprintf(
                'juphyter: %s names an autoloader at %s, and there is no readable file there.',
                $named,
                $path,
            ));
        }

        return $path;
    }
}
