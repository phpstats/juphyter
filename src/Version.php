<?php

declare(strict_types=1);

namespace Juphyter;

use Composer\InstalledVersions;
use OutOfBoundsException;
use Phar;

/**
 * What version of juphyter is running.
 *
 * Composer records the installed version of every package, the root package
 * included, in `vendor/composer/installed.php`, and exposes it through
 * {@see InstalledVersions}. That is the only source here: a constant in the
 * source tree would have to be bumped by hand at every release and would be
 * wrong between releases, whereas Composer's record is written by whatever
 * installed the package and is right by construction.
 *
 * Three installations have to answer, and all three go through the same call.
 * A `composer global require` records the resolved version, a tagged install
 * giving `v0.1.0` and a branch install `dev-main`. A checkout of this
 * repository for development records the root package the same way. The PHAR
 * runs a staging `composer install` at build time, and `scripts/build-phar.php`
 * passes the repository's version to it as `COMPOSER_ROOT_VERSION`, because a
 * staging directory holds no `.git` for Composer to read a version from.
 *
 * A reference is appended when the version alone does not identify the code,
 * which is any branch install: `dev-main` names a moving target and
 * `dev-main@594f74b` names a commit. A tagged version identifies itself and is
 * returned bare.
 */
final class Version
{
    /** The package whose version is juphyter's own. */
    private const PACKAGE = 'phpstats/juphyter';

    /** What to report when Composer has no record to read. */
    private const UNKNOWN = 'unknown';

    /**
     * The running version, as `v0.1.0`, `dev-main@594f74b`, or `unknown`.
     *
     * Never throws. A version string is diagnostic output, printed by
     * `--version` and wanted most when something is already wrong, so a
     * missing or malformed Composer record reports itself rather than
     * stopping the command.
     */
    public static function current(): string
    {
        if (! class_exists(InstalledVersions::class)) {
            return self::UNKNOWN;
        }

        try {
            $version = InstalledVersions::getPrettyVersion(self::PACKAGE);
            $reference = InstalledVersions::getReference(self::PACKAGE);
        } catch (OutOfBoundsException) {
            // The package is not in this installation's record, which happens
            // when juphyter's source is loaded by an autoloader of its own.
            return self::UNKNOWN;
        }

        if ($version === null || $version === '') {
            return self::UNKNOWN;
        }

        if (! str_starts_with($version, 'dev-') || $reference === null) {
            return $version;
        }

        return $version.'@'.substr($reference, 0, 7);
    }

    /**
     * The line `--version` prints, newline included.
     *
     * The PHP version and the archive path travel with it because the two
     * questions that follow a version number in a bug report are which PHP ran
     * it and whether it came from a PHAR.
     */
    public static function line(): string
    {
        $line = sprintf('juphyter %s (PHP %s)', self::current(), PHP_VERSION);
        $phar = Phar::running(false);

        if ($phar !== '') {
            $line .= sprintf(' from %s', $phar);
        }

        return $line."\n";
    }
}
