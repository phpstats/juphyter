<?php

declare(strict_types=1);

use Juphyter\Display\Formatters\TableFormatter;
use Juphyter\Display\Html\TableRenderer;
use Juphyter\Display\MimeBundle;
use Juphyter\Display\Registry;
use Juphyter\Tests\Unit\Display\Fixtures\CountingTable;
use Juphyter\Tests\Unit\Display\Fixtures\FakeTable;
use Juphyter\Tests\Unit\Display\Fixtures\FakeTableWithHtml;
use Juphyter\Tests\Unit\Display\Fixtures\FakeType;
use Juphyter\Tests\Unit\Display\Fixtures\Point;
use Juphyter\Tests\Unit\Display\Fixtures\Snapshot;

/** Three penguins, the same rows whichever backend holds them. */
function penguinRows(): array
{
    return [
        ['species' => 'Adelie', 'island' => 'Torgersen', 'bill' => 39.1, 'mass' => 3750],
        ['species' => 'Gentoo', 'island' => 'Biscoe', 'bill' => 46.1, 'mass' => 4500],
        ['species' => 'Chinstrap', 'island' => 'Dream', 'bill' => 46.5, 'mass' => 3800],
    ];
}

function fakeFrame(): FakeTable
{
    return new FakeTable(penguinRows(), [
        'species' => FakeType::String,
        'island' => FakeType::Factor,
        'bill' => FakeType::Float,
        'mass' => FakeType::Int,
    ]);
}

describe('matching', function () {
    it('matches by name, so it claims nothing without phibble installed', function () {
        expect(TableFormatter::TABLE)->toBe('Phibble\Contracts\Table')
            ->and((new TableFormatter)->supports(fakeFrame()))->toBeFalse()
            ->and((new TableFormatter)->supports(new Point))->toBeFalse()
            ->and((new TableFormatter)->supports(null))->toBeFalse();
    });
});

describe('the bundle', function () {
    it('carries text/html first, then text/plain', function () {
        expect((new TableFormatter)->format(fakeFrame())->types())
            ->toBe([MimeBundle::HTML, MimeBundle::PLAIN]);
    });

    it('takes text/plain from the collected frame itself', function () {
        expect((new TableFormatter)->format(fakeFrame())->get(MimeBundle::PLAIN))
            ->toBe(fakeFrame()->collect()->format());
    });

    it('renders the HTML when the table has none of its own', function () {
        expect((new TableFormatter)->format(fakeFrame())->get(MimeBundle::HTML))
            ->toBe((new TableRenderer)->render(fakeFrame()));
    });

    it('prefers a toHtml() the table brought itself', function () {
        expect((new TableFormatter)->format(new FakeTableWithHtml(fakeFrame()))->get(MimeBundle::HTML))
            ->toBe('<table class="from-the-package"></table>');
    });

    it('collects a lazy table once, for both representations', function () {
        $table = new CountingTable(fakeFrame());
        (new TableFormatter)->format($table);

        expect($table->collected)->toBe(1);
    });

    it('uses the renderer it was given', function () {
        $formatter = new TableFormatter(new TableRenderer(rows: 1, columns: 1));

        expect($formatter->format(fakeFrame())->get(MimeBundle::HTML))
            ->toContain('… with 2 more rows, and 3 more variables');
    });
});

describe('a real phibble table', function () {
    beforeEach(function () {
        if (! interface_exists(TableFormatter::TABLE)) {
            $this->markTestSkipped(
                'phibble is not installed. Run: composer require --dev phpstats/phibble:^0.3'
            );
        }
    });

    it('matches a DataFrame', function () {
        $df = Phibble\DataFrame::fromRows(penguinRows());

        expect((new TableFormatter)->supports($df))->toBeTrue();
    });

    it('prints a DataFrame exactly as phibble prints it', function () {
        $df = Phibble\DataFrame::fromRows(penguinRows());
        $bundle = Registry::default()->format($df);

        expect($bundle->get(MimeBundle::PLAIN))->toBe($df->collect()->format());
    });

    it('renders a DataFrame as HTML, to its snapshot', function () {
        $df = Phibble\DataFrame::fromRows(penguinRows());

        Snapshot::assert(
            'phibble-dataframe.html',
            (string) Registry::default()->format($df)->get(MimeBundle::HTML),
        );
    });

    it('matches a SqlTable, and prints it as phibble does', function () {
        if (! extension_loaded('pdo_sqlite')) {
            $this->markTestSkipped('pdo_sqlite is not loaded, so there is no SQL table to open.');
        }

        $pdo = new PDO('sqlite::memory:', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $pdo->exec('CREATE TABLE penguins (species TEXT, mass INTEGER)');
        $pdo->exec("INSERT INTO penguins VALUES ('Adelie', 3750), ('Gentoo', 4500)");

        $table = Phibble\Sql\SqlTable::table($pdo, 'penguins');
        $bundle = Registry::default()->format($table);

        expect((new TableFormatter)->supports($table))->toBeTrue()
            ->and($bundle->get(MimeBundle::PLAIN))->toBe($table->collect()->format())
            ->and($bundle->get(MimeBundle::HTML))->toContain('species <span class="juphyter-type">&lt;chr&gt;</span>');
    });

    it('would match a PolarsTable, which implements the same contract', function () {
        if (! class_exists('Phibble\Polars\PolarsTable')) {
            $this->markTestSkipped('This phibble has no Polars backend to check.');
        }

        expect(is_subclass_of('Phibble\Polars\PolarsTable', TableFormatter::TABLE))->toBeTrue();
    });

    it('matches a PolarsTable instance when php_polars is loaded', function () {
        if (! extension_loaded('php_polars')) {
            $this->markTestSkipped(
                'The php_polars extension is not loaded, so no PolarsTable can be built. '
                .'See phpstats/phpolars for the prebuilt extension.'
            );
        }

        $table = Phibble\Polars\PolarsTable::fromRows(penguinRows());

        expect((new TableFormatter)->supports($table))->toBeTrue()
            ->and(Registry::default()->format($table)->get(MimeBundle::PLAIN))
            ->toBe($table->collect()->format());
    });
});
