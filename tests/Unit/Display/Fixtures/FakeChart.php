<?php

declare(strict_types=1);

namespace Juphyter\Tests\Unit\Display\Fixtures;

use RuntimeException;

/**
 * The part of `Phiz\Chart` that {@see \Juphyter\Display\Formatters\ChartFormatter}
 * uses: `toArray()`, `embed()` and `svg()`.
 *
 * phiz needs PHP 8.5, so this is how the bundle's shape, the one-line summary
 * and the vl-convert branches are tested on every supported version with no
 * phiz installed. `ChartFormatterTest` then checks a real `Plot` and `Compose`
 * where phiz is there.
 */
final class FakeChart
{
    /** @param array<string, mixed> $spec */
    public function __construct(
        private readonly array $spec,
        private readonly bool $svgFails = false,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return $this->spec;
    }

    public function embed(): string
    {
        return '<div data-fake-chart></div>';
    }

    public function svg(): string
    {
        if ($this->svgFails) {
            throw new RuntimeException('vl-convert said no');
        }

        return '<svg xmlns="http://www.w3.org/2000/svg"></svg>';
    }
}
