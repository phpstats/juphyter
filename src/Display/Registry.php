<?php

declare(strict_types=1);

namespace Juphyter\Display;

use Closure;
use Juphyter\Display\Formatters\ArrayFormatter;
use Juphyter\Display\Formatters\ChartFormatter;
use Juphyter\Display\Formatters\Dumper;
use Juphyter\Display\Formatters\HtmlFormatter;
use Juphyter\Display\Formatters\MimeBundleFormatter;
use Juphyter\Display\Formatters\ObjectFormatter;
use Juphyter\Display\Formatters\ScalarFormatter;
use Juphyter\Display\Formatters\TableFormatter;
use Juphyter\Display\Formatters\ThrowableFormatter;

/**
 * The display layer's entry point: a value in, a {@see MimeBundle} out.
 *
 * ## Dispatch
 *
 * Formatters are asked in order, and the first one that supports the value
 * produces the bundle. Nothing is merged: one formatter owns a value, which is
 * what makes the order below the whole specification of what gets displayed how.
 *
 * 1. {@see MimeBundleFormatter} — a class that renders itself, and so outranks
 *    everything juphyter would otherwise do with it.
 * 2. {@see TableFormatter} — a phibble table, by `Phibble\Contracts\Table`.
 * 3. {@see ChartFormatter} — a phiz chart, by `Phiz\Chart`.
 * 4. {@see HtmlFormatter} — a class with `toHtml()` and nothing richer.
 * 5. {@see ThrowableFormatter} — an exception held as a value.
 * 6. {@see ScalarFormatter}, {@see ArrayFormatter}, {@see ObjectFormatter} —
 *    the fallbacks, in the order that leaves objects last.
 *
 * Every bundle carries `text/plain`: {@see format()} fills it from the compact
 * dump when the chosen formatter left it out, and a value no formatter claimed
 * at all — a resource, say — gets a plain bundle rather than an empty one.
 *
 * ## Process state
 *
 * The two statics below are the seam between the display layer and the engine
 * that runs around it. `Juphyter\display()` is a plain exported function with
 * nowhere of its own to keep state, so the registry it formats with, and the
 * sink it hands bundles to, live here; the engine installs both when a session
 * starts and the defaults keep `display()` useful outside a kernel.
 */
final class Registry
{
    private static ?self $current = null;

    /** @var (Closure(MimeBundle): void)|null */
    private static ?Closure $publisher = null;

    /** @param list<Formatter> $formatters Asked in this order. */
    public function __construct(private readonly array $formatters) {}

    /** The formatters juphyter ships, in dispatch order. */
    public static function default(): self
    {
        return new self([
            new MimeBundleFormatter,
            new TableFormatter,
            new ChartFormatter,
            new HtmlFormatter,
            new ThrowableFormatter,
            new ScalarFormatter,
            new ArrayFormatter,
            new ObjectFormatter,
        ]);
    }

    /** @return list<Formatter> */
    public function formatters(): array
    {
        return $this->formatters;
    }

    /** A registry asking these formatters before the ones it already has. */
    public function prepend(Formatter ...$formatters): self
    {
        return new self([...$formatters, ...$this->formatters]);
    }

    /** A registry asking these formatters after the ones it already has. */
    public function append(Formatter ...$formatters): self
    {
        return new self([...$this->formatters, ...$formatters]);
    }

    public function format(mixed $value): MimeBundle
    {
        $bundle = null;

        foreach ($this->formatters as $formatter) {
            if ($formatter->supports($value)) {
                $bundle = $formatter->format($value);
                break;
            }
        }

        $bundle ??= new MimeBundle;

        if (! $bundle->has(MimeBundle::PLAIN)) {
            $bundle = $bundle->with(MimeBundle::PLAIN, Dumper::plain($value));
        }

        return $bundle;
    }

    /** The registry `Juphyter\display()` formats with. */
    public static function current(): self
    {
        return self::$current ??= self::default();
    }

    /** Replace that registry, or restore the default with null. */
    public static function setCurrent(?self $registry): void
    {
        self::$current = $registry;
    }

    /**
     * Where `Juphyter\display()` sends what it formats.
     *
     * The engine installs a publisher that turns each bundle into a `display`
     * event. Null restores the default, which writes `text/plain` to standard
     * output, so `display()` still does something sensible in a plain PHP
     * script or a test.
     *
     * @param  (callable(MimeBundle): void)|null  $publisher
     */
    public static function publishUsing(?callable $publisher): void
    {
        self::$publisher = $publisher === null ? null : $publisher(...);
    }

    public static function publish(MimeBundle $bundle): void
    {
        if (self::$publisher !== null) {
            (self::$publisher)($bundle);

            return;
        }

        echo (string) $bundle->get(MimeBundle::PLAIN), "\n";
    }
}
