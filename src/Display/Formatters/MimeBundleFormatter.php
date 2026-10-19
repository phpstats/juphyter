<?php

declare(strict_types=1);

namespace Juphyter\Display\Formatters;

use Juphyter\Display\Formatter;
use Juphyter\Display\MimeBundle;
use Stringable;

/**
 * A class that renders itself: `toMimeBundle()` takes precedence over every
 * built-in formatter.
 *
 * This is the hook a user's own class uses to opt into rich display, and the one
 * a sibling package can grow in its own repository to override what juphyter
 * would otherwise do with it. It comes first in the registry for exactly that
 * reason: a phibble table that one day declares `toMimeBundle()` should be
 * displayed the way phibble says, not the way juphyter guesses.
 *
 * The method returns a {@see MimeBundle}, or a MIME type => data map. A map is
 * recognised by its keys: every MIME type contains a slash, so a return value
 * with any other key is not a bundle, and the value falls through to the compact
 * dump rather than producing an output Jupyter cannot read.
 */
final class MimeBundleFormatter implements Formatter
{
    public const string METHOD = 'toMimeBundle';

    public function supports(mixed $value): bool
    {
        return Reflect::declares($value, self::METHOD);
    }

    public function format(mixed $value): MimeBundle
    {
        /** @var object $value */
        $bundle = $value->{self::METHOD}();

        if ($bundle instanceof MimeBundle) {
            return self::withPlain($bundle, $value);
        }

        if (! is_array($bundle) || ! self::isMimeMap($bundle)) {
            return MimeBundle::plain(Dumper::plain($value));
        }

        return self::withPlain(new MimeBundle($bundle), $value);
    }

    /** @param array<array-key, mixed> $bundle */
    private static function isMimeMap(array $bundle): bool
    {
        if ($bundle === []) {
            return false;
        }

        foreach (array_keys($bundle) as $type) {
            if (! is_string($type) || ! str_contains($type, '/')) {
                return false;
            }
        }

        return true;
    }

    private static function withPlain(MimeBundle $bundle, object $value): MimeBundle
    {
        if ($bundle->has(MimeBundle::PLAIN)) {
            return $bundle;
        }

        return $bundle->with(
            MimeBundle::PLAIN,
            $value instanceof Stringable ? (string) $value : Dumper::plain($value),
        );
    }
}
