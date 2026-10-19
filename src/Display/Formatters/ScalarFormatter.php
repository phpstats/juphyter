<?php

declare(strict_types=1);

namespace Juphyter\Display\Formatters;

use Juphyter\Display\Formatter;
use Juphyter\Display\MimeBundle;

/** Null, booleans, integers, floats and strings: `text/plain` and nothing else. */
final class ScalarFormatter implements Formatter
{
    public function supports(mixed $value): bool
    {
        return $value === null || is_scalar($value);
    }

    public function format(mixed $value): MimeBundle
    {
        return MimeBundle::plain(Dumper::plain($value));
    }
}
