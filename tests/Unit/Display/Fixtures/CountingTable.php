<?php

declare(strict_types=1);

namespace Juphyter\Tests\Unit\Display\Fixtures;

/**
 * A lazy table that counts how often it is collected.
 *
 * `collect()` on a phibble `SqlTable` runs the query, so the formatter has to
 * do it once and build both representations from that one result. This is how
 * the test says so without needing a database.
 */
final class CountingTable
{
    public int $collected = 0;

    public function __construct(private readonly FakeTable $table) {}

    public function collect(): FakeTable
    {
        $this->collected++;

        return $this->table;
    }
}
