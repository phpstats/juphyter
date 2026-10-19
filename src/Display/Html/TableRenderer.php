<?php

declare(strict_types=1);

namespace Juphyter\Display\Html;

use BackedEnum;
use DateTimeInterface;
use Stringable;
use UnitEnum;

/**
 * A phibble table as an HTML table, carrying what phibble's own header and
 * footer lines carry: the dimensions, the grouping, a type abbreviation under
 * every column name, and a footer for the rows and columns not shown.
 *
 * ## Why juphyter renders this
 *
 * phibble prints a tibble-style block of text and has no HTML output of its
 * own, and juphyter must not change a sibling package. So `text/plain` stays
 * phibble's own `format()`, byte for byte, and the HTML is built here from the
 * public contract: `columns()`, `types()`, `nrow()`, `ncol()`, `groups()`,
 * `head()` and `toRows()`. If phibble ever grows `toHtml()`, the table
 * formatter prefers it and this renderer is not called.
 *
 * ## Duck-typed on purpose
 *
 * `render()` takes an `object`, not `Phibble\Contracts\Table`: juphyter has no
 * hard dependency on phibble, so nothing here may name one of its classes at
 * compile time. Anything offering those methods renders, which is also what
 * makes this testable without phibble installed.
 *
 * ## Self-contained output
 *
 * Each table carries its own small `<style>` block, scoped to
 * `.juphyter-table`. A notebook output cannot rely on a stylesheet juphyter
 * never got to install, and an `.ipynb` is read by JupyterLab, nbconvert and
 * Quarto alike. The cost is a few hundred repeated bytes per table, which is
 * cheaper than per-cell inline styles and far cheaper than an unstyled table.
 * Colours are JupyterLab's CSS variables with plain fallbacks, so the table
 * follows the notebook's light or dark theme and still reads outside one.
 */
final class TableRenderer
{
    /** Rows shown before the footer counts the rest. phibble's printer shows 10. */
    public const int ROWS = 10;

    /** Columns shown before the footer names the rest. */
    public const int COLUMNS = 20;

    /** Characters kept of a string cell. */
    public const int CELL = 60;

    /**
     * Type abbreviations whose columns are right-aligned, as pillar aligns them.
     * `lgl` is not among them: phibble prints TRUE and FALSE flush left.
     */
    private const array NUMERIC = ['dbl', 'int'];

    public function __construct(
        private readonly int $rows = self::ROWS,
        private readonly int $columns = self::COLUMNS,
    ) {}

    /**
     * @param  object  $table  Anything offering phibble's table contract.
     */
    public function render(object $table): string
    {
        $columns = $table->columns();
        $types = $table->types();
        $rowCount = $table->nrow();
        $columnCount = $table->ncol();

        $shown = array_slice($columns, 0, $this->columns);
        $hiddenColumns = array_slice($columns, $this->columns);

        // head() keeps a lazy backend from materialising the whole frame, and
        // the slice behind it covers a table that offers no head() at all.
        $head = $rowCount > $this->rows && method_exists($table, 'head')
            ? $table->head($this->rows)
            : $table;
        $rows = array_slice($head->toRows(), 0, $this->rows);
        $hiddenRows = max($rowCount - count($rows), 0);

        $parts = [
            '<div class="juphyter-table">',
            self::style(),
            ...self::notes($table, $rowCount, $columnCount),
        ];

        if ($shown !== []) {
            $parts[] = '<table>';
            $parts[] = self::head($shown, $types);
            $parts[] = self::body($rows, $shown, $types);
            $parts[] = '</table>';
        }

        $footer = self::footer($hiddenRows, $hiddenColumns, $types);

        if ($footer !== null) {
            $parts[] = $footer;
        }

        $parts[] = '</div>';

        return implode("\n", $parts)."\n";
    }

    /**
     * The dimension line, and the grouping line when there is one, as phibble's
     * header prints them.
     *
     * phibble also counts the groups; that needs a pass over every group, which
     * is not worth doing twice for a display, so the key columns are named and
     * the count is left to `format()` in `text/plain`.
     *
     * @return list<string>
     */
    private static function notes(object $table, int $rows, int $columns): array
    {
        $notes = ['A tibble: '.self::count($rows).' × '.self::count($columns)];
        $groups = method_exists($table, 'groups') ? $table->groups() : [];

        if ($groups !== []) {
            $rowwise = method_exists($table, 'isRowwise') && $table->isRowwise();
            $notes[] = ($rowwise ? 'Rowwise: ' : 'Groups: ').implode(', ', array_map(self::escape(...), $groups));
        }

        return array_map(
            static fn (string $note): string => '<div class="juphyter-note">'.$note.'</div>',
            $notes,
        );
    }

    /**
     * @param  list<string>  $columns
     * @param  array<string, mixed>  $types
     */
    private static function head(array $columns, array $types): string
    {
        $cells = ['<th class="juphyter-rowid"></th>'];

        foreach ($columns as $name) {
            $abbreviation = self::abbreviation($types[$name] ?? null);
            $cells[] = sprintf(
                '<th%s>%s <span class="juphyter-type">&lt;%s&gt;</span></th>',
                self::alignment($abbreviation),
                self::escape($name),
                self::escape($abbreviation),
            );
        }

        return "<thead>\n<tr>".implode('', $cells)."</tr>\n</thead>";
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @param  list<string>  $columns
     * @param  array<string, mixed>  $types
     */
    private static function body(array $rows, array $columns, array $types): string
    {
        $lines = ['<tbody>'];

        foreach ($rows as $index => $row) {
            $cells = ['<th class="juphyter-rowid">'.($index + 1).'</th>'];

            foreach ($columns as $name) {
                $abbreviation = self::abbreviation($types[$name] ?? null);
                $cells[] = '<td'.self::alignment($abbreviation).'>'
                    .self::cell($row[$name] ?? null, $abbreviation)
                    .'</td>';
            }

            $lines[] = '<tr>'.implode('', $cells).'</tr>';
        }

        $lines[] = '</tbody>';

        return implode("\n", $lines);
    }

    /**
     * @param  list<string>  $hiddenColumns
     * @param  array<string, mixed>  $types
     */
    private static function footer(int $hiddenRows, array $hiddenColumns, array $types): ?string
    {
        $parts = [];

        if ($hiddenRows > 0) {
            $parts[] = self::count($hiddenRows).' more '.($hiddenRows === 1 ? 'row' : 'rows');
        }

        if ($hiddenColumns !== []) {
            $named = array_map(
                static fn (string $name): string => self::escape($name)
                    .' &lt;'.self::escape(self::abbreviation($types[$name] ?? null)).'&gt;',
                $hiddenColumns,
            );
            $parts[] = count($hiddenColumns).' more '
                .(count($hiddenColumns) === 1 ? 'variable' : 'variables')
                .': '.implode(', ', $named);
        }

        if ($parts === []) {
            return null;
        }

        return '<div class="juphyter-note">… with '.implode(', and ', $parts).'</div>';
    }

    /** One cell's text, escaped, formatted the way its column type reads best. */
    private static function cell(mixed $value, string $type): string
    {
        if ($value === null) {
            return '<span class="juphyter-na">NA</span>';
        }

        if (is_bool($value)) {
            return $value ? 'TRUE' : 'FALSE';
        }

        if (is_float($value)) {
            if (is_nan($value)) {
                return 'NaN';
            }

            return is_infinite($value) ? ($value > 0 ? 'Inf' : '-Inf') : self::escape((string) $value);
        }

        if (is_int($value)) {
            return (string) $value;
        }

        if (is_string($value)) {
            return self::escape(self::clip($value));
        }

        if ($value instanceof DateTimeInterface) {
            return self::escape($value->format($type === 'date' ? 'Y-m-d' : 'Y-m-d H:i:s'));
        }

        // A list column: its length, as pillar prints one.
        if (is_array($value)) {
            return '<span class="juphyter-na">['.count($value).']</span>';
        }

        if ($value instanceof UnitEnum) {
            return self::escape($value instanceof BackedEnum ? (string) $value->value : $value->name);
        }

        if ($value instanceof Stringable) {
            return self::escape(self::clip((string) $value));
        }

        return is_object($value) ? self::escape($value::class) : self::escape(get_debug_type($value));
    }

    /**
     * A column's type abbreviation: phibble's `Type::abbreviation()` when the
     * type offers it, and the plainest reading of the value otherwise, so a
     * table from somewhere else still renders.
     */
    private static function abbreviation(mixed $type): string
    {
        if (is_object($type) && method_exists($type, 'abbreviation')) {
            return (string) $type->abbreviation();
        }

        if ($type instanceof BackedEnum) {
            return (string) $type->value;
        }

        if ($type instanceof UnitEnum) {
            return $type->name;
        }

        return is_string($type) ? $type : '?';
    }

    private static function alignment(string $abbreviation): string
    {
        return in_array($abbreviation, self::NUMERIC, true) ? ' class="juphyter-num"' : '';
    }

    /**
     * A string cell: clipped to one cell's worth, with the control characters
     * escaped rather than emitted.
     *
     * A raw newline in a cell would either break the row's layout or, under the
     * `nowrap` above, silently collapse to a space. `\n` says what is there.
     */
    private static function clip(string $value): string
    {
        $escaped = addcslashes($value, "\n\r\t\v\f\0");

        return mb_strlen($escaped) > self::CELL ? mb_substr($escaped, 0, self::CELL).'…' : $escaped;
    }

    /** Thousands separated, as phibble's printer marks a big number. */
    private static function count(int $n): string
    {
        return number_format($n, 0, '.', ',');
    }

    private static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private static function style(): string
    {
        return '<style>'
            .'.juphyter-table{font-family:var(--jp-code-font-family,ui-monospace,SFMono-Regular,Menlo,monospace);'
            .'font-size:var(--jp-code-font-size,0.85em);color:var(--jp-content-font-color1,#111827)}'
            .'.juphyter-table .juphyter-note{color:var(--jp-content-font-color2,#6b7280);margin:0.15em 0}'
            .'.juphyter-table table{border-collapse:collapse;margin:0.3em 0}'
            .'.juphyter-table th,.juphyter-table td{padding:0.1em 0.75em 0.1em 0;text-align:left;white-space:nowrap}'
            .'.juphyter-table thead th{border-bottom:1px solid var(--jp-border-color2,#d1d5db)}'
            .'.juphyter-table .juphyter-num{text-align:right}'
            .'.juphyter-table .juphyter-rowid{color:var(--jp-content-font-color3,#9ca3af);font-weight:normal;text-align:right}'
            .'.juphyter-table .juphyter-type{color:var(--jp-content-font-color2,#6b7280);font-weight:normal}'
            .'.juphyter-table .juphyter-na{color:var(--jp-content-font-color3,#9ca3af)}'
            .'</style>';
    }
}
