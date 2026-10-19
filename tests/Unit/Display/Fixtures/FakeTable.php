<?php

declare(strict_types=1);

namespace Juphyter\Tests\Unit\Display\Fixtures;

/**
 * The part of phibble's table contract {@see \Juphyter\Display\Html\TableRenderer}
 * actually uses: `columns()`, `types()`, `nrow()`, `ncol()`, `groups()`,
 * `isRowwise()`, `head()` and `toRows()`.
 *
 * The renderer is duck-typed because juphyter has no hard dependency on
 * phibble, and this fixture is what that buys: the HTML and its snapshots are
 * tested with none of the family installed, on every PHP version, and against
 * data a test controls to the cell. `TableFormatterTest` then checks the same
 * renderer against a real phibble frame when one is there.
 */
final readonly class FakeTable
{
    /**
     * @param  list<array<string, mixed>>  $rows
     * @param  array<string, FakeType>  $types
     * @param  list<string>  $groups
     */
    public function __construct(
        private array $rows,
        private array $types,
        private array $groups = [],
        private bool $rowwise = false,
    ) {}

    /** @return list<string> */
    public function columns(): array
    {
        return array_keys($this->types);
    }

    /** @return array<string, FakeType> */
    public function types(): array
    {
        return $this->types;
    }

    public function nrow(): int
    {
        return count($this->rows);
    }

    public function ncol(): int
    {
        return count($this->types);
    }

    /** @return list<string> */
    public function groups(): array
    {
        return $this->groups;
    }

    public function isRowwise(): bool
    {
        return $this->rowwise;
    }

    public function head(int $n = 6): self
    {
        return new self(array_slice($this->rows, 0, max($n, 0)), $this->types, $this->groups, $this->rowwise);
    }

    /** @return list<array<string, mixed>> */
    public function toRows(): array
    {
        return $this->rows;
    }

    public function collect(): self
    {
        return $this;
    }

    public function format(): string
    {
        return 'a fake tibble: '.$this->nrow().' × '.$this->ncol();
    }
}
