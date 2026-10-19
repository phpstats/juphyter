<?php

declare(strict_types=1);

namespace Juphyter\Tests\Unit\Display\Fixtures;

use JsonSerializable;

/** An object that says what its JSON is. */
final class Measurement implements JsonSerializable
{
    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return ['value' => 3.5, 'unit' => 'mm'];
    }
}
