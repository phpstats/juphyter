<?php

declare(strict_types=1);

namespace Juphyter\Tests\Unit\Display\Fixtures;

/**
 * Hooks the registry must not use: one that is static, and one that needs an
 * argument. Neither is a display hook, so this class falls through to the
 * object fallback.
 */
final class HidesItsHooks
{
    /** @return array<string, string> */
    public static function toMimeBundle(): array
    {
        return ['text/html' => '<p>static</p>'];
    }

    public function toHtml(string $theme): string
    {
        return "<p>{$theme}</p>";
    }
}
