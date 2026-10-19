<?php

declare(strict_types=1);

namespace Juphyter\Tests\Unit\Display\Fixtures;

use Juphyter\Display\Formatter;
use Juphyter\Display\MimeBundle;

/**
 * A formatter that claims one value and records every time it is asked, so a
 * test can assert the registry's dispatch order and that it stops at the first
 * match rather than asking everyone.
 */
final class Spy implements Formatter
{
    /** @var list<string> The labels of the spies asked, in order. */
    public static array $asked = [];

    public function __construct(
        private readonly mixed $claims,
        private readonly string $label,
    ) {}

    public static function reset(): void
    {
        self::$asked = [];
    }

    public function supports(mixed $value): bool
    {
        self::$asked[] = $this->label;

        return $value === $this->claims;
    }

    public function format(mixed $value): MimeBundle
    {
        return MimeBundle::plain($this->label);
    }
}
