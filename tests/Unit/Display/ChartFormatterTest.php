<?php

declare(strict_types=1);

use Juphyter\Display\Formatters\ChartFormatter;
use Juphyter\Display\MimeBundle;
use Juphyter\Display\Registry;
use Juphyter\Tests\Unit\Display\Fixtures\FakeChart;
use Juphyter\Tests\Unit\Display\Fixtures\Point;

const VEGA_LITE_SCHEMA = 'https://vega.github.io/schema/vega-lite/v6.json';

/** @param array<string, mixed> $spec */
function fakeChart(array $spec = [], bool $svgFails = false): FakeChart
{
    return new FakeChart(['$schema' => VEGA_LITE_SCHEMA, ...$spec], $svgFails);
}

describe('matching', function () {
    it('matches by name, so it claims nothing without phiz installed', function () {
        expect(ChartFormatter::CHART)->toBe('Phiz\Chart')
            ->and((new ChartFormatter)->supports(fakeChart()))->toBeFalse()
            ->and((new ChartFormatter)->supports(new Point))->toBeFalse()
            ->and((new ChartFormatter)->supports('Phiz\Chart'))->toBeFalse();
    });
});

describe('the bundle', function () {
    it('publishes the four representations in order when vl-convert is there', function () {
        $bundle = (new ChartFormatter(svg: true))->format(fakeChart(['mark' => 'point']));

        expect($bundle->types())->toBe([
            MimeBundle::VEGALITE,
            MimeBundle::HTML,
            MimeBundle::SVG,
            MimeBundle::PLAIN,
        ]);
    });

    it('leaves out image/svg+xml when vl-convert is not there', function () {
        $bundle = (new ChartFormatter(svg: false))->format(fakeChart(['mark' => 'point']));

        expect($bundle->types())->toBe([MimeBundle::VEGALITE, MimeBundle::HTML, MimeBundle::PLAIN])
            ->and($bundle->has(MimeBundle::SVG))->toBeFalse();
    });

    it('leaves out image/svg+xml rather than failing when the conversion does', function () {
        $bundle = (new ChartFormatter(svg: true))->format(fakeChart(['mark' => 'point'], svgFails: true));

        expect($bundle->has(MimeBundle::SVG))->toBeFalse()
            ->and($bundle->has(MimeBundle::VEGALITE))->toBeTrue()
            ->and($bundle->has(MimeBundle::HTML))->toBeTrue();
    });

    it('publishes the spec under the v5 MIME type, schema and all', function () {
        $spec = ['$schema' => VEGA_LITE_SCHEMA, 'mark' => 'point'];
        $bundle = (new ChartFormatter(svg: false))->format(new FakeChart($spec));

        expect($bundle->get(MimeBundle::VEGALITE))->toBe($spec)
            ->and(MimeBundle::VEGALITE)->toBe('application/vnd.vegalite.v5+json');
    });

    it('takes text/html from embed()', function () {
        expect((new ChartFormatter(svg: false))->format(fakeChart())->get(MimeBundle::HTML))
            ->toBe('<div data-fake-chart></div>');
    });
});

describe('the one-line summary', function () {
    it('names the class, the mark, the rows and the Vega-Lite version', function () {
        $chart = fakeChart([
            'mark' => ['type' => 'point', 'filled' => true],
            'data' => ['values' => [['x' => 1], ['x' => 2], ['x' => 3]]],
        ]);

        expect((new ChartFormatter(svg: false))->format($chart)->get(MimeBundle::PLAIN))
            ->toBe(FakeChart::class.': point, 3 rows, Vega-Lite v6');
    });

    it('joins the marks of a layered plot', function () {
        $chart = fakeChart([
            'layer' => [
                ['mark' => 'point'],
                ['mark' => ['type' => 'line']],
                ['mark' => 'point'],
            ],
        ]);

        expect((new ChartFormatter(svg: false))->format($chart)->get(MimeBundle::PLAIN))
            ->toBe(FakeChart::class.': point + line, Vega-Lite v6');
    });

    it('says how a composition is arranged', function (string $key, string $arrangement) {
        $chart = fakeChart([$key => [['mark' => 'point'], ['mark' => 'bar']]]);

        expect((new ChartFormatter(svg: false))->format($chart)->get(MimeBundle::PLAIN))
            ->toBe(FakeChart::class.': 2 charts '.$arrangement.', Vega-Lite v6');
    })->with([
        ['hconcat', 'side by side'],
        ['vconcat', 'stacked'],
        ['concat', 'in a grid'],
    ]);

    it('finds the rows a composition carries in its first dataset', function () {
        $chart = fakeChart([
            'vconcat' => [
                ['mark' => 'point', 'data' => ['values' => [['x' => 1], ['x' => 2]]]],
            ],
        ]);

        expect((new ChartFormatter(svg: false))->format($chart)->get(MimeBundle::PLAIN))
            ->toBe(FakeChart::class.': 1 chart stacked, 2 rows, Vega-Lite v6');
    });

    it('reads in the singular for one row', function () {
        $chart = fakeChart(['mark' => 'bar', 'data' => ['values' => [['x' => 1]]]]);

        expect((new ChartFormatter(svg: false))->format($chart)->get(MimeBundle::PLAIN))
            ->toBe(FakeChart::class.': bar, 1 row, Vega-Lite v6');
    });

    it('leaves out the rows when the data is behind a URL', function () {
        $chart = fakeChart(['mark' => 'line', 'data' => ['url' => 'penguins.csv']]);

        expect((new ChartFormatter(svg: false))->format($chart)->get(MimeBundle::PLAIN))
            ->toBe(FakeChart::class.': line, Vega-Lite v6');
    });

    it('names the class alone when the spec says nothing it can use', function () {
        expect((new ChartFormatter(svg: false))->format(new FakeChart([]))->get(MimeBundle::PLAIN))
            ->toBe(FakeChart::class);
    });
});

describe('a real phiz chart', function () {
    beforeEach(function () {
        // phiz is written in PHP 8.5, so below that its classes do not even
        // parse. Checking the version first, rather than only class_exists(),
        // is what turns a parse error on 8.3 and 8.4 into an explicit skip.
        if (PHP_VERSION_ID < 80500) {
            $this->markTestSkipped(
                'phiz needs PHP 8.5 and this is PHP '.PHP_VERSION.', so it cannot be installed here.'
            );
        }

        if (! class_exists(ChartFormatter::CHART)) {
            $this->markTestSkipped(
                'phiz is not installed. Run: composer require --dev erikwestlund/phiz:^0.3'
            );
        }
    });

    it('matches a Plot and a Compose through their shared base', function () {
        $plot = Phiz\Plot::of([['x' => 1, 'y' => 2]])->aes(x: 'x', y: 'y')->point();

        expect((new ChartFormatter)->supports($plot))->toBeTrue()
            ->and((new ChartFormatter)->supports(Phiz\Compose::beside($plot, $plot)))->toBeTrue();
    });

    it('publishes a Plot with the spec and the embed phiz produced', function () {
        $plot = Phiz\Plot::of([['x' => 1, 'y' => 2]])->aes(x: 'x', y: 'y')->point();
        $bundle = (new ChartFormatter(svg: false))->format($plot);

        expect($bundle->get(MimeBundle::VEGALITE))->toBe($plot->toArray())
            ->and($bundle->get(MimeBundle::HTML))->toBe($plot->embed())
            ->and($bundle->get(MimeBundle::PLAIN))->toBe(Phiz\Plot::class.': point, 1 row, Vega-Lite v6');
    });

    it('describes a Compose by how it arranges its plots', function () {
        $plot = Phiz\Plot::of([['x' => 1, 'y' => 2]])->aes(x: 'x', y: 'y')->bar();
        $bundle = (new ChartFormatter(svg: false))->format(Phiz\Compose::stack($plot, $plot));

        expect($bundle->get(MimeBundle::PLAIN))
            ->toBe(Phiz\Compose::class.': 2 charts stacked, 1 row, Vega-Lite v6');
    });

    it('reaches a chart through the default registry', function () {
        $plot = Phiz\Plot::of([['x' => 1, 'y' => 2]])->aes(x: 'x', y: 'y')->point();

        expect(Registry::default()->format($plot)->has(MimeBundle::VEGALITE))->toBeTrue();
    });
});

describe('a real vl-convert binary', function () {
    beforeEach(function () {
        // phiz is written in PHP 8.5, so below that its classes do not even
        // parse. Checking the version first, rather than only class_exists(),
        // is what turns a parse error on 8.3 and 8.4 into an explicit skip.
        if (PHP_VERSION_ID < 80500) {
            $this->markTestSkipped(
                'phiz needs PHP 8.5 and this is PHP '.PHP_VERSION.', so it cannot be installed here.'
            );
        }

        if (! class_exists(ChartFormatter::CHART)) {
            $this->markTestSkipped(
                'phiz is not installed. Run: composer require --dev erikwestlund/phiz:^0.3'
            );
        }

        if (! ChartFormatter::vlConvertAvailable()) {
            $this->markTestSkipped(
                'No vl-convert binary on PHIZ_VL_CONVERT, VL_CONVERT or PATH, so there is no '
                .'static rendering to check. Install it with: pip install vl-convert-python'
            );
        }
    });

    it('publishes an SVG that vl-convert actually rendered', function () {
        $plot = Phiz\Plot::of([['x' => 1, 'y' => 2], ['x' => 2, 'y' => 4]])->aes(x: 'x', y: 'y')->point();
        $bundle = (new ChartFormatter)->format($plot);

        expect($bundle->types())->toBe([
            MimeBundle::VEGALITE,
            MimeBundle::HTML,
            MimeBundle::SVG,
            MimeBundle::PLAIN,
        ])
            ->and($bundle->get(MimeBundle::SVG))->toStartWith('<svg')
            ->and($bundle->get(MimeBundle::SVG))->toContain('http://www.w3.org/2000/svg');
    });

    it('publishes an SVG for a composition too', function () {
        $plot = Phiz\Plot::of([['x' => 1, 'y' => 2]])->aes(x: 'x', y: 'y')->bar();
        $bundle = (new ChartFormatter)->format(Phiz\Compose::beside($plot, $plot));

        expect($bundle->types())->toBe([
            MimeBundle::VEGALITE,
            MimeBundle::HTML,
            MimeBundle::SVG,
            MimeBundle::PLAIN,
        ])
            ->and($bundle->get(MimeBundle::SVG))->toStartWith('<svg');
    });

    it('finds the binary the way phiz does, and misses it when nothing points at one', function () {
        $path = getenv('PATH');
        $phiz = getenv('PHIZ_VL_CONVERT');
        $plain = getenv('VL_CONVERT');

        try {
            putenv('PHIZ_VL_CONVERT');
            putenv('VL_CONVERT');
            putenv('PATH=/nonexistent');

            expect(ChartFormatter::vlConvertAvailable())->toBeFalse();

            $plot = Phiz\Plot::of([['x' => 1, 'y' => 2]])->aes(x: 'x', y: 'y')->point();

            expect((new ChartFormatter)->format($plot)->has(MimeBundle::SVG))->toBeFalse();
        } finally {
            putenv($path === false ? 'PATH' : 'PATH='.$path);
            putenv($phiz === false ? 'PHIZ_VL_CONVERT' : 'PHIZ_VL_CONVERT='.$phiz);
            putenv($plain === false ? 'VL_CONVERT' : 'VL_CONVERT='.$plain);
        }

        expect(ChartFormatter::vlConvertAvailable())->toBeTrue();
    });
});
