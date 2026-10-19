<?php

declare(strict_types=1);

namespace Juphyter\Tests\Unit\Display\Fixtures;

/**
 * File-based snapshots for the HTML the display layer renders, kept in
 * `tests/snapshots/display/`.
 *
 * Pest's own `toMatchSnapshot()` writes to `tests/.pest/snapshots`, which is not
 * where this milestone's snapshots live, and it creates a missing snapshot
 * silently — which in CI makes a lost file look like a pass. So a missing
 * snapshot fails here and says what to do about it.
 *
 * Set `UPDATE_SNAPSHOTS=1` to write the current output over what is on disk.
 * Read the diff before committing it: an HTML snapshot is the whole assertion.
 */
final class Snapshot
{
    public static function path(string $name): string
    {
        return dirname(__DIR__, 3).'/snapshots/display/'.$name;
    }

    public static function assert(string $name, string $actual): void
    {
        $path = self::path($name);
        $update = (string) getenv('UPDATE_SNAPSHOTS');

        if ($update !== '' && $update !== '0') {
            if (! is_dir(dirname($path))) {
                mkdir(dirname($path), 0o755, true);
            }

            file_put_contents($path, $actual);
        }

        $expected = is_file($path) ? (string) file_get_contents($path) : null;

        expect($expected)->not->toBeNull(
            "No snapshot at {$path}. Re-run with UPDATE_SNAPSHOTS=1 to write it."
        );
        expect($actual)->toBe($expected);
    }
}
