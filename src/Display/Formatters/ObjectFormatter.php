<?php

declare(strict_types=1);

namespace Juphyter\Display\Formatters;

use JsonException;
use JsonSerializable;
use Juphyter\Display\Formatter;
use Juphyter\Display\MimeBundle;

/**
 * The fallback for any object no earlier formatter claimed: the compact dump,
 * plus `application/json` when the class opted in with `JsonSerializable`.
 *
 * The opt-in is deliberate. Encoding an arbitrary object exposes whichever
 * public properties it happens to have, which is noise more often than it is a
 * representation, whereas `jsonSerialize()` is the class saying what its JSON
 * form is. The encoded result is re-decoded so the bundle carries plain arrays
 * rather than a nest of objects.
 */
final class ObjectFormatter implements Formatter
{
    public function supports(mixed $value): bool
    {
        return is_object($value);
    }

    public function format(mixed $value): MimeBundle
    {
        $bundle = MimeBundle::plain(Dumper::plain($value));

        if (! $value instanceof JsonSerializable) {
            return $bundle;
        }

        try {
            $json = json_decode(json_encode($value, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return $bundle;
        }

        return $bundle->with(MimeBundle::JSON, $json);
    }
}
