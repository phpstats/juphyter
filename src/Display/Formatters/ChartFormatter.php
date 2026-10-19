<?php

declare(strict_types=1);

namespace Juphyter\Display\Formatters;

use Juphyter\Display\Formatter;
use Juphyter\Display\MimeBundle;
use Throwable;

/**
 * A phiz chart, published in every representation a front end might want.
 *
 * It matches `Phiz\Chart`, the base of both `Phiz\Plot` and `Phiz\Compose`, so
 * a single plot and a composition are handled by one rule.
 *
 * | MIME type                           | Source               | Who renders it                         |
 * |-------------------------------------|----------------------|----------------------------------------|
 * | `application/vnd.vegalite.v5+json`  | `$chart->toArray()`  | JupyterLab's bundled vega extension    |
 * | `text/html`                         | `$chart->embed()`    | Quarto HTML, nbconvert, classic notebook |
 * | `image/svg+xml`                     | `$chart->svg()`      | Quarto PDF and docx, anything without JS |
 * | `text/plain`                        | a one-line summary   | everything else                        |
 *
 * Three facts force all four. JupyterLab's renderer defines Vega-Lite v3, v4
 * and v5 and has no v6 constant, so phiz's v6 spec goes out under the newest
 * type that renderer knows. JupyterLab does not execute scripts in `text/html`
 * output, so `embed()` cannot be its path. Quarto has no Vega-Lite MIME type at
 * all, so `text/html` is the only interactive path there.
 *
 * The SVG is the guarantee behind publishing a v6 spec as v5: where vl-convert
 * is installed, the plot draws regardless of what the front end's JavaScript
 * supports. It is omitted rather than fatal when the binary is missing, and a
 * conversion that fails is treated the same way — a cell that renders three
 * representations beats a cell that reports an error about the fourth.
 */
final class ChartFormatter implements Formatter
{
    public const string CHART = 'Phiz\Chart';

    /** phiz's own vl-convert lookup: PHIZ_VL_CONVERT, then VL_CONVERT, then PATH. */
    public const string EXPORT = 'Phiz\Export';

    /**
     * @param  bool|null  $svg  Whether to publish `image/svg+xml`. The default
     *   asks phiz whether vl-convert is installed, which is what a notebook
     *   wants; a test pins it to get the same bundle either way.
     */
    public function __construct(private readonly ?bool $svg = null) {}

    public function supports(mixed $value): bool
    {
        return Reflect::isA($value, self::CHART);
    }

    public function format(mixed $value): MimeBundle
    {
        /** @var object $value */
        $spec = (array) $value->toArray();

        $bundle = (new MimeBundle)
            ->with(MimeBundle::VEGALITE, $spec)
            ->with(MimeBundle::HTML, (string) $value->embed());

        $svg = $this->svg ?? self::vlConvertAvailable();

        if ($svg) {
            $image = self::svg($value);

            if ($image !== null) {
                $bundle = $bundle->with(MimeBundle::SVG, $image);
            }
        }

        return $bundle->with(MimeBundle::PLAIN, self::describe($value, $spec));
    }

    /** Whether phiz can find a vl-convert binary right now. */
    public static function vlConvertAvailable(): bool
    {
        $available = [self::EXPORT, 'available'];

        return is_callable($available) && (bool) $available();
    }

    private static function svg(object $chart): ?string
    {
        try {
            return (string) $chart->svg();
        } catch (Throwable) {
            // No binary, a spec vl-convert rejects, a timeout: the other three
            // representations are still worth publishing.
            return null;
        }
    }

    /**
     * One line naming the chart, for a front end with nothing richer: its class,
     * what it draws, how much data it carries, and the Vega-Lite version.
     *
     * Everything comes out of the compiled spec rather than off the chart
     * object, so a `Plot` and a `Compose` are described by the same code and
     * neither phiz class is named here.
     *
     * @param  array<string, mixed>  $spec
     */
    private static function describe(object $chart, array $spec): string
    {
        $parts = [];
        $composition = self::composition($spec);

        if ($composition !== null) {
            $parts[] = $composition;
        } else {
            $marks = self::marks($spec);

            if ($marks !== []) {
                $parts[] = implode(' + ', $marks);
            }
        }

        $rows = self::rows($spec);

        if ($rows !== null) {
            $parts[] = $rows.' '.($rows === 1 ? 'row' : 'rows');
        }

        $schema = is_string($spec['$schema'] ?? null) ? $spec['$schema'] : '';

        // The schema URL ends in a version and a suffix: .../vega-lite/v6.json.
        // Each dot has to be followed by a digit, or the .json would come along.
        if (preg_match('#/vega-lite/v(\\d+(?:\\.\\d+)*)#', $schema, $match) === 1) {
            $parts[] = 'Vega-Lite v'.$match[1];
        }

        return $chart::class.($parts === [] ? '' : ': '.implode(', ', $parts));
    }

    /**
     * How a composition arranges its charts, or null for a single plot.
     *
     * @param  array<string, mixed>  $spec
     */
    private static function composition(array $spec): ?string
    {
        $arrangements = [
            'hconcat' => 'side by side',
            'vconcat' => 'stacked',
            'concat' => 'in a grid',
        ];

        foreach ($arrangements as $key => $arrangement) {
            if (is_array($spec[$key] ?? null)) {
                $count = count($spec[$key]);

                return $count.' '.($count === 1 ? 'chart' : 'charts').' '.$arrangement;
            }
        }

        return null;
    }

    /**
     * The mark types the spec draws, in order and without repeats.
     *
     * @param  array<string, mixed>  $spec
     * @return list<string>
     */
    private static function marks(array $spec): array
    {
        $marks = [];

        foreach ([$spec, ...array_values(is_array($spec['layer'] ?? null) ? $spec['layer'] : [])] as $node) {
            if (! is_array($node)) {
                continue;
            }

            $mark = $node['mark'] ?? null;
            $type = is_array($mark) ? ($mark['type'] ?? null) : $mark;

            if (is_string($type) && ! in_array($type, $marks, true)) {
                $marks[] = $type;
            }
        }

        return $marks;
    }

    /**
     * How many rows of inline data the spec carries, from the first dataset it
     * holds, or null when the data lives behind a URL or a named source.
     *
     * @param  array<array-key, mixed>  $node
     */
    private static function rows(array $node): ?int
    {
        $values = $node['data']['values'] ?? null;

        if (is_array($values)) {
            return count($values);
        }

        foreach ($node as $child) {
            if (is_array($child)) {
                $rows = self::rows($child);

                if ($rows !== null) {
                    return $rows;
                }
            }
        }

        return null;
    }
}
