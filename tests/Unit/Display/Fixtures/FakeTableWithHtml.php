<?php

declare(strict_types=1);

namespace Juphyter\Tests\Unit\Display\Fixtures;

/**
 * A table that renders its own HTML, which is the shape phibble would have if
 * it grew `toHtml()` in its own repository.
 *
 * It exists to test that branch of {@see \Juphyter\Display\Formatters\TableFormatter}
 * without waiting for phibble to grow the method, and with no phibble installed:
 * `format()` is reached only for a value `supports()` accepted, so a test can
 * call it directly with anything offering the contract.
 */
final readonly class FakeTableWithHtml
{
    public function __construct(private FakeTable $table) {}

    public function collect(): FakeTable
    {
        return $this->table;
    }

    public function toHtml(): string
    {
        return '<table class="from-the-package"></table>';
    }
}
