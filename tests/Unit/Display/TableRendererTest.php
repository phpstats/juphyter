<?php

declare(strict_types=1);

use Juphyter\Display\Html\TableRenderer;
use Juphyter\Tests\Unit\Display\Fixtures\FakeTable;
use Juphyter\Tests\Unit\Display\Fixtures\FakeType;
use Juphyter\Tests\Unit\Display\Fixtures\Snapshot;

/** Three rows of penguins, with one column per type the renderer formats. */
function penguins(int $rows = 3): FakeTable
{
    $all = [
        ['species' => 'Adelie', 'island' => 'Torgersen', 'bill' => 39.1, 'mass' => 3750, 'adult' => true, 'seen' => new DateTimeImmutable('2007-11-11'), 'notes' => null],
        ['species' => 'Gentoo', 'island' => 'Biscoe', 'bill' => 46.1, 'mass' => 4500, 'adult' => false, 'seen' => new DateTimeImmutable('2008-01-03'), 'notes' => 'tagged <twice>'],
        ['species' => 'Chinstrap', 'island' => 'Dream', 'bill' => 46.5, 'mass' => 3800, 'adult' => null, 'seen' => new DateTimeImmutable('2009-02-17'), 'notes' => "line\nbreak"],
    ];

    return new FakeTable(array_slice($all, 0, $rows), [
        'species' => FakeType::String,
        'island' => FakeType::Factor,
        'bill' => FakeType::Float,
        'mass' => FakeType::Int,
        'adult' => FakeType::Bool,
        'seen' => FakeType::Date,
        'notes' => FakeType::String,
    ]);
}

describe('snapshots', function () {
    it('renders a small frame', function () {
        Snapshot::assert('table-small.html', (new TableRenderer)->render(penguins()));
    });

    it('renders a frame with rows and columns held back', function () {
        $rows = [];

        for ($i = 1; $i <= 2_500; $i++) {
            $rows[] = ['n' => $i, 'label' => 'row '.$i, 'ratio' => $i / 3, 'kept' => $i % 2 === 0];
        }

        $table = new FakeTable($rows, [
            'n' => FakeType::Int,
            'label' => FakeType::String,
            'ratio' => FakeType::Float,
            'kept' => FakeType::Bool,
        ]);

        Snapshot::assert('table-truncated.html', (new TableRenderer(rows: 3, columns: 2))->render($table));
    });

    it('renders a grouped frame', function () {
        $table = new FakeTable(
            [['species' => 'Adelie', 'n' => 152], ['species' => 'Gentoo', 'n' => 124]],
            ['species' => FakeType::Factor, 'n' => FakeType::Int],
            groups: ['species'],
        );

        Snapshot::assert('table-grouped.html', (new TableRenderer)->render($table));
    });

    it('renders a frame with no rows', function () {
        Snapshot::assert('table-empty.html', (new TableRenderer)->render(penguins(0)));
    });
});

describe('the header', function () {
    it('states the dimensions as phibble does', function () {
        expect((new TableRenderer)->render(penguins()))->toContain('A tibble: 3 × 7');
    });

    it('separates the thousands in a big dimension', function () {
        $rows = array_fill(0, 1_500, ['n' => 1]);
        $html = (new TableRenderer)->render(new FakeTable($rows, ['n' => FakeType::Int]));

        expect($html)->toContain('A tibble: 1,500 × 1');
    });

    it('gives every column its type abbreviation', function () {
        $html = (new TableRenderer)->render(penguins());

        expect($html)->toContain('species <span class="juphyter-type">&lt;chr&gt;</span>')
            ->and($html)->toContain('island <span class="juphyter-type">&lt;fct&gt;</span>')
            ->and($html)->toContain('bill <span class="juphyter-type">&lt;dbl&gt;</span>')
            ->and($html)->toContain('mass <span class="juphyter-type">&lt;int&gt;</span>')
            ->and($html)->toContain('adult <span class="juphyter-type">&lt;lgl&gt;</span>')
            ->and($html)->toContain('seen <span class="juphyter-type">&lt;date&gt;</span>');
    });

    it('names the group columns, and says rowwise when it is', function () {
        $types = ['species' => FakeType::String];
        $grouped = new FakeTable([['species' => 'Adelie']], $types, groups: ['species']);
        $rowwise = new FakeTable([['species' => 'Adelie']], $types, groups: ['species'], rowwise: true);

        expect((new TableRenderer)->render($grouped))->toContain('Groups: species')
            ->and((new TableRenderer)->render($rowwise))->toContain('Rowwise: species');
    });

    it('leaves the grouping line out when the frame is ungrouped', function () {
        expect((new TableRenderer)->render(penguins()))->not->toContain('Groups:');
    });
});

describe('the footer', function () {
    it('counts the rows it held back', function () {
        $rows = array_fill(0, 12, ['n' => 1]);
        $html = (new TableRenderer(rows: 10))->render(new FakeTable($rows, ['n' => FakeType::Int]));

        expect($html)->toContain('… with 2 more rows');
    });

    it('names the columns it held back, with their types', function () {
        $html = (new TableRenderer(columns: 2))->render(penguins());

        expect($html)->toContain('5 more variables: bill &lt;dbl&gt;, mass &lt;int&gt;')
            ->and($html)->toContain('notes &lt;chr&gt;');
    });

    it('reads in the singular for one row and one column', function () {
        $rows = array_fill(0, 2, ['a' => 1, 'b' => 2]);
        $html = (new TableRenderer(rows: 1, columns: 1))->render(
            new FakeTable($rows, ['a' => FakeType::Int, 'b' => FakeType::Int])
        );

        expect($html)->toContain('… with 1 more row, and 1 more variable: b &lt;int&gt;');
    });

    it('is left out when the whole frame is shown', function () {
        expect((new TableRenderer)->render(penguins()))->not->toContain('… with');
    });
});

describe('cells', function () {
    it('marks a null as NA', function () {
        expect((new TableRenderer)->render(penguins()))
            ->toContain('<span class="juphyter-na">NA</span>');
    });

    it('prints booleans as phibble prints them', function () {
        $html = (new TableRenderer)->render(penguins());

        expect($html)->toContain('>TRUE<')->and($html)->toContain('>FALSE<');
    });

    it('prints a date without the time', function () {
        expect((new TableRenderer)->render(penguins()))->toContain('>2007-11-11<');
    });

    it('prints a datetime with the time', function () {
        $table = new FakeTable(
            [['at' => new DateTimeImmutable('2024-03-04 05:06:07')]],
            ['at' => FakeType::DateTime],
        );

        expect((new TableRenderer)->render($table))->toContain('>2024-03-04 05:06:07<');
    });

    it('escapes markup in a string', function () {
        expect((new TableRenderer)->render(penguins()))->toContain('tagged &lt;twice&gt;');
    });

    it('escapes a newline rather than breaking the row', function () {
        expect((new TableRenderer)->render(penguins()))->toContain('<td>line\\nbreak</td>');
    });

    it('gives a list column its length', function () {
        $table = new FakeTable(
            [['items' => [1, 2, 3]], ['items' => []]],
            ['items' => FakeType::List],
        );
        $html = (new TableRenderer)->render($table);

        expect($html)->toContain('[3]')->and($html)->toContain('[0]');
    });

    it('clips a long string', function () {
        $table = new FakeTable(
            [['text' => str_repeat('x', TableRenderer::CELL + 20)]],
            ['text' => FakeType::String],
        );

        expect((new TableRenderer)->render($table))
            ->toContain(str_repeat('x', TableRenderer::CELL).'…');
    });

    it('names the floats that have no digits', function () {
        $table = new FakeTable(
            [['x' => NAN], ['x' => INF], ['x' => -INF]],
            ['x' => FakeType::Float],
        );
        $html = (new TableRenderer)->render($table);

        expect($html)->toContain('>NaN<')->and($html)->toContain('>Inf<')->and($html)->toContain('>-Inf<');
    });

    it('right-aligns the numeric columns and leaves the rest alone', function () {
        $html = (new TableRenderer)->render(penguins());

        expect($html)->toContain('<td class="juphyter-num">3750</td>')
            ->and($html)->toContain('<td class="juphyter-num">39.1</td>')
            ->and($html)->toContain('<td>Adelie</td>')
            ->and($html)->toContain('<td>TRUE</td>');
    });

    it('fills in a cell the row is missing', function () {
        $table = new FakeTable([['a' => 1]], ['a' => FakeType::Int, 'b' => FakeType::Int]);

        expect((new TableRenderer)->render($table))->toContain('<span class="juphyter-na">NA</span>');
    });
});

describe('a table from somewhere else', function () {
    it('renders a type it cannot abbreviate', function () {
        $table = new class
        {
            public function columns(): array
            {
                return ['a'];
            }

            public function types(): array
            {
                return ['a' => 'whatever'];
            }

            public function nrow(): int
            {
                return 1;
            }

            public function ncol(): int
            {
                return 1;
            }

            public function groups(): array
            {
                return [];
            }

            public function toRows(): array
            {
                return [['a' => 1]];
            }
        };

        expect((new TableRenderer)->render($table))
            ->toContain('a <span class="juphyter-type">&lt;whatever&gt;</span>');
    });

    it('holds rows back itself when the table offers no head()', function () {
        $table = new class
        {
            public function columns(): array
            {
                return ['n'];
            }

            public function types(): array
            {
                return ['n' => 'int'];
            }

            public function nrow(): int
            {
                return 12;
            }

            public function ncol(): int
            {
                return 1;
            }

            public function groups(): array
            {
                return [];
            }

            public function toRows(): array
            {
                return array_map(fn (int $i): array => ['n' => $i], range(1, 12));
            }
        };

        $html = (new TableRenderer(rows: 3))->render($table);

        expect($html)->toContain('… with 9 more rows')
            ->and(substr_count($html, '<tr>'))->toBe(4);
    });
});
