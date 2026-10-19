<?php

declare(strict_types=1);

namespace Juphyter\Tests\Unit\Display\Fixtures;

use Juphyter\Display\Formatter;
use Juphyter\Display\MimeBundle;

/**
 * A formatter that claims everything and returns a bundle with no `text/plain`,
 * so a test can prove the registry fills that in rather than publishing a
 * bundle a plain-text front end could not read.
 */
final class HtmlOnly implements Formatter
{
    public function supports(mixed $value): bool
    {
        return true;
    }

    public function format(mixed $value): MimeBundle
    {
        return (new MimeBundle)->with(MimeBundle::HTML, '<p>only html</p>');
    }
}
