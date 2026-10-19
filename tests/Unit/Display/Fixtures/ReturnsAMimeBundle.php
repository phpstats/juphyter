<?php

declare(strict_types=1);

namespace Juphyter\Tests\Unit\Display\Fixtures;

use Juphyter\Display\MimeBundle;

/** The `toMimeBundle()` hook returning a {@see MimeBundle}, which is accepted too. */
final class ReturnsAMimeBundle
{
    public function toMimeBundle(): MimeBundle
    {
        return MimeBundle::plain('built by hand')->with(MimeBundle::MARKDOWN, '# built by hand');
    }
}
