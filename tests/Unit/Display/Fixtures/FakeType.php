<?php

declare(strict_types=1);

namespace Juphyter\Tests\Unit\Display\Fixtures;

/**
 * A stand-in for `Phibble\Type`, with the same `abbreviation()` method and the
 * same abbreviations.
 *
 * The renderer reads a column's type through that method rather than through
 * phibble's enum, so this is all it needs to render a table with no phibble
 * installed — which is the point: the test runs in the dependency set the CI
 * matrix calls `none`.
 */
enum FakeType: string
{
    case Float = 'float';
    case Int = 'int';
    case Bool = 'bool';
    case String = 'string';
    case Factor = 'factor';
    case Date = 'date';
    case DateTime = 'datetime';
    case List = 'list';

    public function abbreviation(): string
    {
        return match ($this) {
            self::Float => 'dbl',
            self::Int => 'int',
            self::Bool => 'lgl',
            self::String => 'chr',
            self::Factor => 'fct',
            self::Date => 'date',
            self::DateTime => 'dttm',
            self::List => 'list',
        };
    }
}
