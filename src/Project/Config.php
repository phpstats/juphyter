<?php

declare(strict_types=1);

namespace Juphyter\Project;

use JsonException;
use RuntimeException;

/**
 * A `juphyter.json` beside the notebook.
 *
 * Three settings, all optional:
 *
 * ```json
 * {
 *   "autoload": "vendor/autoload.php",
 *   "memory_limit": "512M",
 *   "include_path": "lib"
 * }
 * ```
 *
 * A relative path is relative to the file the setting was read from, not to the
 * engine's working directory, so a config two directories up still means what
 * it looks like it means.
 *
 * The file is looked for by walking up from the engine's working directory,
 * which Jupyter sets to the notebook's own directory. Walking, rather than
 * looking only beside the notebook, is what lets one file at the top of a
 * project serve notebooks in subdirectories of it.
 */
final class Config
{
    public const FILE = 'juphyter.json';

    private function __construct(
        /** The directory the file was read from, which relative paths are resolved against. */
        public readonly string $directory,
        public readonly ?string $autoload = null,
        public readonly ?string $memoryLimit = null,
        public readonly ?string $includePath = null,
    ) {}

    /**
     * Find and read the nearest config at or above $directory, or null.
     *
     * @throws RuntimeException When a file is found but cannot be read, which is
     *                          worth reporting rather than ignoring: the user
     *                          wrote it on purpose.
     */
    public static function find(string $directory): ?self
    {
        $current = $directory === '' ? '.' : $directory;
        $seen = [];

        while (true) {
            $resolved = realpath($current);

            if ($resolved === false || isset($seen[$resolved])) {
                return null;
            }

            $seen[$resolved] = true;
            $file = $resolved.DIRECTORY_SEPARATOR.self::FILE;

            if (is_file($file)) {
                return self::read($file);
            }

            $parent = dirname($resolved);

            if ($parent === $resolved) {
                return null;
            }

            $current = $parent;
        }
    }

    /**
     * Read one config file.
     *
     * @throws RuntimeException On unreadable or invalid JSON.
     */
    public static function read(string $file): self
    {
        $contents = @file_get_contents($file);

        if ($contents === false) {
            throw new RuntimeException(sprintf('juphyter: could not read %s.', $file));
        }

        try {
            $decoded = json_decode($contents, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new RuntimeException(sprintf('juphyter: %s is not valid JSON (%s).', $file, $e->getMessage()));
        }

        // An empty object decodes to an empty array, which array_is_list()
        // calls a list, so it has to be allowed through explicitly: a
        // juphyter.json of `{}` is valid and says nothing.
        if (! is_array($decoded) || ($decoded !== [] && array_is_list($decoded))) {
            throw new RuntimeException(sprintf('juphyter: %s has to hold a JSON object.', $file));
        }

        return new self(
            dirname($file),
            self::text($decoded, 'autoload'),
            self::text($decoded, 'memory_limit'),
            self::text($decoded, 'include_path'),
        );
    }

    /** The autoloader this config names, as an absolute path, or null. */
    public function autoloadPath(): ?string
    {
        return $this->autoload === null ? null : $this->resolve($this->autoload);
    }

    /**
     * Apply the settings that are PHP's rather than juphyter's.
     *
     * The include path is prepended rather than replacing what is there, so a
     * project adding a directory does not lose the rest of the path.
     */
    public function apply(): void
    {
        if ($this->memoryLimit !== null) {
            ini_set('memory_limit', $this->memoryLimit);
        }

        if ($this->includePath !== null) {
            ini_set('include_path', $this->resolve($this->includePath).PATH_SEPARATOR.ini_get('include_path'));
        }
    }

    /** A path from the file, made absolute against the file's own directory. */
    private function resolve(string $path): string
    {
        if ($path === '' || $path[0] === DIRECTORY_SEPARATOR) {
            return $path;
        }

        return $this->directory.DIRECTORY_SEPARATOR.$path;
    }

    /** @param  array<string, mixed>  $decoded */
    private static function text(array $decoded, string $key): ?string
    {
        $value = $decoded[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }
}
