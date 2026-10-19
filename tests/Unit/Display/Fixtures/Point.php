<?php

declare(strict_types=1);

namespace Juphyter\Tests\Unit\Display\Fixtures;

/** A plain object, for the object fallback and the dumper. */
final class Point
{
    public function __construct(
        public readonly int $x = 1,
        public readonly float $y = 2.5,
        public readonly ?string $label = null,
    ) {}
}
