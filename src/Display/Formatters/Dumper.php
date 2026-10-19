<?php

declare(strict_types=1);

namespace Juphyter\Display\Formatters;

use Closure;
use DateTimeInterface;
use UnitEnum;

/**
 * The plain-text rendering every fallback formatter shares, and the `text/plain`
 * of last resort for a value no formatter claimed.
 *
 * It is compact first: a value is rendered on one line, and an array or object
 * that does not fit in {@see WIDTH} columns is expanded one entry per line, each
 * entry itself staying on one line while it fits. A list of scalars wraps into
 * filled lines instead, the way R prints a vector, because fifty numbers down
 * the left margin say nothing fifty numbers across three lines do not. Together
 * that keeps the small array a notebook cell usually holds scannable, without
 * turning a nested structure into an unreadable run of brackets.
 *
 * Strings are quoted, at the top level as well as inside a structure, so a cell
 * ending in `$name` cannot be mistaken for one ending in a number. `echo`
 * remains the way to get a string out raw: it goes to the cell's stream rather
 * than to its result.
 *
 * It is a renderer, not a serializer. Long strings, wide arrays and deep nesting
 * are truncated with an ellipsis, and nothing here is meant to round-trip.
 */
final class Dumper
{
    /** Nesting levels rendered before an array or object collapses to an ellipsis. */
    public const int DEPTH = 3;

    /** Entries rendered per array or object before the rest are counted instead. */
    public const int ITEMS = 50;

    /** Characters kept of a string. */
    public const int STRING = 120;

    /** The column budget a one-line rendering has to fit in. */
    public const int WIDTH = 88;

    private const string INDENT = '    ';

    /** The rendering to publish as `text/plain`: one line wherever that fits. */
    public static function plain(mixed $value): string
    {
        return self::expand($value, 0, 0);
    }

    /** The one-line rendering, however long it comes out. */
    public static function inline(mixed $value, int $depth = 0): string
    {
        return match (true) {
            $value === null => 'null',
            is_bool($value) => $value ? 'true' : 'false',
            is_int($value) => (string) $value,
            is_float($value) => self::float($value),
            is_string($value) => self::quote($value),
            is_array($value) => self::inlineArray($value, $depth),
            is_object($value) => self::object($value, $depth),
            default => self::other($value),
        };
    }

    /**
     * One line when it fits the remaining budget, otherwise one entry per line.
     *
     * @param  int  $level  Indentation levels already spent.
     * @param  int  $depth  Nesting levels already spent, against the depth limit.
     */
    private static function expand(mixed $value, int $level, int $depth): string
    {
        $inline = self::inline($value, $depth);

        if (mb_strlen($inline) <= self::WIDTH - $level * strlen(self::INDENT)) {
            return $inline;
        }

        // A long list of scalars — a column of numbers, a set of labels — reads
        // as a wrapped run the way R prints a vector. One entry per line would
        // turn fifty numbers into fifty lines and say nothing more.
        if (is_array($value) && array_is_list($value) && $depth < self::DEPTH && self::isFlat($value)) {
            return self::wrapped($value, $level, $depth);
        }

        $block = self::block($value, $depth);

        if ($block === null) {
            return $inline;
        }

        [$open, $close, $entries] = $block;
        $pad = str_repeat(self::INDENT, $level + 1);
        $lines = [$open];

        foreach ($entries as [$prefix, $item, $expandable]) {
            $lines[] = $pad.$prefix.($expandable ? self::expand($item, $level + 1, $depth + 1).',' : '');
        }

        $lines[] = str_repeat(self::INDENT, $level).$close;

        return implode("\n", $lines);
    }

    /**
     * Whether every entry of an array renders as a one-line scalar.
     *
     * @param  array<array-key, mixed>  $value
     */
    private static function isFlat(array $value): bool
    {
        foreach ($value as $item) {
            if (is_array($item) || is_object($item)) {
                return false;
            }
        }

        return true;
    }

    /**
     * A list of scalars laid out as lines of entries rather than one per line.
     *
     * @param  array<array-key, mixed>  $value
     */
    private static function wrapped(array $value, int $level, int $depth): string
    {
        $pad = str_repeat(self::INDENT, $level + 1);
        $budget = max(self::WIDTH - mb_strlen($pad), 20);

        $pieces = [];

        foreach (self::limit($value) as $item) {
            $pieces[] = self::inline($item, $depth + 1).',';
        }

        if (count($value) > self::ITEMS) {
            $pieces[] = '… '.(count($value) - self::ITEMS).' more';
        }

        $lines = [];
        $line = '';

        foreach ($pieces as $piece) {
            if ($line === '') {
                $line = $piece;
            } elseif (mb_strlen($line) + 1 + mb_strlen($piece) <= $budget) {
                $line .= ' '.$piece;
            } else {
                $lines[] = $pad.$line;
                $line = $piece;
            }
        }

        if ($line !== '') {
            $lines[] = $pad.$line;
        }

        return "[\n".implode("\n", $lines)."\n".str_repeat(self::INDENT, $level).']';
    }

    /**
     * The brackets and prefixed entries of a value worth expanding, or null for
     * one that only ever has a single-line form.
     *
     * Each entry is a key prefix, the value behind it, and whether that value
     * should be rendered at all: the trailing "… n more" marker is a prefix with
     * nothing behind it.
     *
     * @return array{0: string, 1: string, 2: list<array{0: string, 1: mixed, 2: bool}>}|null
     */
    private static function block(mixed $value, int $depth): ?array
    {
        if ($depth >= self::DEPTH) {
            return null;
        }

        if (is_array($value)) {
            if ($value === []) {
                return null;
            }

            $list = array_is_list($value);
            $entries = [];

            foreach (self::limit($value) as $key => $item) {
                $entries[] = [$list ? '' : self::inline($key, $depth + 1).' => ', $item, true];
            }

            return ['[', ']', self::elided($entries, count($value))];
        }

        if (is_object($value) && self::hasProperties($value)) {
            $properties = get_object_vars($value);

            if ($properties === []) {
                return null;
            }

            $entries = [];

            foreach (self::limit($properties) as $name => $item) {
                $entries[] = [$name.': ', $item, true];
            }

            return [$value::class.' {', '}', self::elided($entries, count($properties))];
        }

        return null;
    }

    /**
     * @param  list<array{0: string, 1: mixed, 2: bool}>  $entries
     * @return list<array{0: string, 1: mixed, 2: bool}>
     */
    private static function elided(array $entries, int $total): array
    {
        if ($total <= self::ITEMS) {
            return $entries;
        }

        $entries[] = ['… '.($total - self::ITEMS).' more', null, false];

        return $entries;
    }

    /**
     * @param  array<array-key, mixed>  $value
     * @return array<array-key, mixed>
     */
    private static function limit(array $value): array
    {
        return count($value) > self::ITEMS ? array_slice($value, 0, self::ITEMS, true) : $value;
    }

    /** @param array<array-key, mixed> $value */
    private static function inlineArray(array $value, int $depth): string
    {
        if ($value === []) {
            return '[]';
        }

        if ($depth >= self::DEPTH) {
            return '[…]';
        }

        $list = array_is_list($value);
        $parts = [];

        foreach (self::limit($value) as $key => $item) {
            $parts[] = ($list ? '' : self::inline($key, $depth + 1).' => ').self::inline($item, $depth + 1);
        }

        if (count($value) > self::ITEMS) {
            $parts[] = '… '.(count($value) - self::ITEMS).' more';
        }

        return '['.implode(', ', $parts).']';
    }

    private static function object(object $value, int $depth): string
    {
        if ($value instanceof UnitEnum) {
            return $value::class.'::'.$value->name;
        }

        if ($value instanceof Closure) {
            return 'Closure';
        }

        if ($value instanceof DateTimeInterface) {
            return $value::class.' '.$value->format('Y-m-d H:i:s');
        }

        if (! self::hasProperties($value)) {
            return $value::class;
        }

        if ($depth >= self::DEPTH) {
            return $value::class.' {…}';
        }

        $properties = get_object_vars($value);

        if ($properties === []) {
            return $value::class.' {}';
        }

        $parts = [];

        foreach (self::limit($properties) as $name => $item) {
            $parts[] = $name.': '.self::inline($item, $depth + 1);
        }

        if (count($properties) > self::ITEMS) {
            $parts[] = '… '.(count($properties) - self::ITEMS).' more';
        }

        return $value::class.' {'.implode(', ', $parts).'}';
    }

    /**
     * Whether listing public properties says anything useful about an object.
     *
     * Enums, closures and dates have their own renderings above; an internal
     * class rarely exposes state as properties and is named instead.
     */
    private static function hasProperties(object $value): bool
    {
        return ! $value instanceof UnitEnum
            && ! $value instanceof Closure
            && ! $value instanceof DateTimeInterface;
    }

    private static function float(float $value): string
    {
        if (is_nan($value)) {
            return 'NAN';
        }

        if (is_infinite($value)) {
            return $value > 0 ? 'INF' : '-INF';
        }

        // var_export() keeps the decimal point, so 1.0 does not read as an int.
        return var_export($value, true);
    }

    private static function quote(string $value): string
    {
        $truncated = mb_strlen($value) > self::STRING
            ? mb_substr($value, 0, self::STRING).'…'
            : $value;

        return "'".addcslashes($truncated, "\\'\n\r\t\v\f\0")."'";
    }

    /** Resources, and anything a future PHP adds that is none of the above. */
    private static function other(mixed $value): string
    {
        if (is_resource($value)) {
            return 'resource('.get_resource_type($value).')';
        }

        // A closed resource is no longer is_resource(), and has no type to name.
        return get_debug_type($value);
    }
}
