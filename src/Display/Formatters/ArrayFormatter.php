<?php

declare(strict_types=1);

namespace Juphyter\Display\Formatters;

use JsonException;
use Juphyter\Display\Formatter;
use Juphyter\Display\MimeBundle;

/**
 * Arrays: the compact dump, and `application/json` where that helps.
 *
 * "Where that helps" is a nested array, which a front end's JSON viewer renders
 * better than one line of brackets can. A flat list of scalars reads perfectly
 * well as `[1, 2, 3]`, and offering JSON for it only replaces that with a
 * collapsible widget, so it is left as plain text alone.
 *
 * `application/json` carries the decoded structure rather than a JSON string,
 * as Jupyter's output spec requires; the transport encodes the whole message.
 */
final class ArrayFormatter implements Formatter
{
    public function supports(mixed $value): bool
    {
        return is_array($value);
    }

    public function format(mixed $value): MimeBundle
    {
        $bundle = MimeBundle::plain(Dumper::plain($value));

        if (is_array($value) && self::isNested($value) && self::encodable($value)) {
            $bundle = $bundle->with(MimeBundle::JSON, $value);
        }

        return $bundle;
    }

    /** @param array<array-key, mixed> $value */
    private static function isNested(array $value): bool
    {
        foreach ($value as $item) {
            if (is_array($item) || is_object($item)) {
                return true;
            }
        }

        return false;
    }

    private static function encodable(mixed $value): bool
    {
        try {
            json_encode($value, JSON_THROW_ON_ERROR);

            return true;
        } catch (JsonException) {
            return false;
        }
    }
}
