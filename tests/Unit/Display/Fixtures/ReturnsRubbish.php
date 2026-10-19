<?php

declare(strict_types=1);

namespace Juphyter\Tests\Unit\Display\Fixtures;

/** A `toMimeBundle()` whose keys are not MIME types, so its return is unusable. */
final class ReturnsRubbish
{
    /** @return array<string, string> */
    public function toMimeBundle(): array
    {
        return ['html' => '<p>not a mime type</p>'];
    }
}
