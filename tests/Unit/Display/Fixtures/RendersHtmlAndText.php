<?php

declare(strict_types=1);

namespace Juphyter\Tests\Unit\Display\Fixtures;

use Stringable;

/** The HTML hook on a class that is also `Stringable`. */
final class RendersHtmlAndText implements Stringable
{
    public function toHtml(): string
    {
        return '<em>rendered</em>';
    }

    public function __toString(): string
    {
        return 'rendered';
    }
}
