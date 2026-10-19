<?php

declare(strict_types=1);

namespace Juphyter\Tests\Unit\Display\Fixtures;

use Stringable;

/**
 * A class that builds its whole bundle, as the `toMimeBundle()` hook intends.
 *
 * It offers the smaller `toHtml()` hook too, so a test can prove which of the
 * two the registry prefers.
 */
final class RendersItsOwnBundle implements Stringable
{
    /** @return array<string, mixed> */
    public function toMimeBundle(): array
    {
        return [
            'text/html' => '<p>from toMimeBundle</p>',
            'application/json' => ['from' => 'toMimeBundle'],
        ];
    }

    public function toHtml(): string
    {
        return '<p>from toHtml</p>';
    }

    public function __toString(): string
    {
        return 'a bundle of its own';
    }
}
