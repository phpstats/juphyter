<?php

declare(strict_types=1);

namespace Juphyter\Display\Formatters;

use Juphyter\Display\Formatter;
use Juphyter\Display\MimeBundle;
use Stringable;

/**
 * A class that renders itself as HTML and nothing else: `toHtml()` becomes the
 * bundle's `text/html`.
 *
 * The smaller of the two user-type hooks, for the common case of a class that
 * already knows how to print itself in a browser. A class that wants to control
 * the whole bundle declares `toMimeBundle()` instead, which runs first.
 *
 * `text/plain` comes from `__toString()` when the class is `Stringable` — a
 * class that can render itself as HTML usually has a text form too — and from
 * the compact dump otherwise.
 */
final class HtmlFormatter implements Formatter
{
    public const string METHOD = 'toHtml';

    public function supports(mixed $value): bool
    {
        return Reflect::declares($value, self::METHOD);
    }

    public function format(mixed $value): MimeBundle
    {
        /** @var object $value */
        $plain = $value instanceof Stringable ? (string) $value : Dumper::plain($value);

        return (new MimeBundle)
            ->with(MimeBundle::HTML, (string) $value->{self::METHOD}())
            ->with(MimeBundle::PLAIN, $plain);
    }
}
