<?php

declare(strict_types=1);

use Juphyter\Version;

describe('the reported version', function () {
    // The realistic failure is a wrong package name in the constant, which
    // Composer answers for with an exception rather than a wrong string, and
    // which this checkout is in a position to catch.
    it('reads this installation out of the Composer record', function () {
        expect(Version::current())->not->toBe('unknown');
    });

    it('identifies a branch install by its commit', function () {
        $version = Version::current();

        if (! str_starts_with($version, 'dev-')) {
            expect($version)->not->toContain('@');

            return;
        }

        expect($version)->toMatch('/^dev-.+@[0-9a-f]{7}$/');
    });

    it('names the tool and the PHP running it, on one line', function () {
        $line = Version::line();

        expect($line)->toEndWith("\n")
            ->and(substr_count($line, "\n"))->toBe(1)
            ->and($line)->toStartWith('juphyter '.Version::current())
            ->and($line)->toContain('PHP '.PHP_VERSION);
    });

    // Phar::running() is empty outside an archive, and the suffix is what tells
    // a bug report that the PHAR rather than a Composer install is running.
    it('leaves off the archive path when no archive is running', function () {
        expect(Version::line())->not->toContain(' from ');
    });
});
