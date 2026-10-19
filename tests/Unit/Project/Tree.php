<?php

declare(strict_types=1);

namespace Juphyter\Tests\Unit\Project;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Throwaway directory trees for the project-discovery tests.
 *
 * A class rather than a function in one of the test files, so that either test
 * file runs on its own: a helper function declared in a sibling only exists
 * when that sibling happens to have been loaded first.
 */
final class Tree
{
    /** @var list<string> */
    private static array $roots = [];

    /**
     * Build a tree and return its canonical root.
     *
     * Canonical because the discovery code resolves symlinks, and on macOS the
     * temporary directory lives under `/var`, which is a link to `/private/var`.
     * A test comparing paths has to speak the same dialect.
     *
     * @param  array<string, string>  $files  Path relative to the root => contents.
     */
    public static function make(array $files = []): string
    {
        $root = sys_get_temp_dir().'/juphyter-project-'.bin2hex(random_bytes(6));
        mkdir($root, 0o777, true);
        self::$roots[] = $root;

        foreach ($files as $path => $contents) {
            $file = $root.'/'.$path;
            $directory = dirname($file);

            if (! is_dir($directory)) {
                mkdir($directory, 0o777, true);
            }

            file_put_contents($file, $contents);
        }

        return realpath($root) ?: $root;
    }

    /** Remove every tree made so far. */
    public static function cleanUp(): void
    {
        foreach (array_reverse(self::$roots) as $root) {
            if (! is_dir($root)) {
                continue;
            }

            $entries = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST,
            );

            foreach ($entries as $entry) {
                $entry->isDir() ? @rmdir($entry->getPathname()) : @unlink($entry->getPathname());
            }

            @rmdir($root);
        }

        self::$roots = [];
    }

    /**
     * Whether a vendor directory sits above the temporary directory here, which
     * would make "finds nothing" untestable.
     */
    public static function vendorAbove(): bool
    {
        return \Juphyter\Project\Autoload::search(sys_get_temp_dir()) !== null;
    }
}
