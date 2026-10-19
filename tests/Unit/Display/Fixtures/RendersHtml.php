<?php

declare(strict_types=1);

namespace Juphyter\Tests\Unit\Display\Fixtures;

/** A class with only the HTML hook, and no text form of its own. */
final class RendersHtml
{
    public function toHtml(): string
    {
        return '<b>a & b</b>';
    }
}
