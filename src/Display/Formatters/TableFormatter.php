<?php

declare(strict_types=1);

namespace Juphyter\Display\Formatters;

use Juphyter\Display\Formatter;
use Juphyter\Display\Html\TableRenderer;
use Juphyter\Display\MimeBundle;

/**
 * A phibble table: phibble's own printing as `text/plain`, and an HTML table.
 *
 * It matches `Phibble\Contracts\Table`, which every backend implements, so a
 * `DataFrame`, a `SqlTable` and a `PolarsTable` are all displayed the same way
 * with one rule and no backend-specific code.
 *
 * `text/plain` is `$table->collect()->format()` exactly, so notebook output
 * matches what the package prints at a terminal. That is the point of routing
 * through phibble rather than formatting the text here: one printer, one
 * appearance, and phibble's improvements arrive without a juphyter release.
 *
 * `text/html` prefers the table's own `toHtml()` when it has one. phibble has
 * none today and juphyter must not change a sibling package, so
 * {@see TableRenderer} builds the table from the public contract; if phibble
 * grows HTML output in its own repository, it wins here with no change to this
 * class.
 *
 * A lazy table is collected once. `collect()` on a `SqlTable` runs the query,
 * and both representations come from that one result rather than running it
 * twice.
 */
final class TableFormatter implements Formatter
{
    public const string TABLE = 'Phibble\Contracts\Table';

    public function __construct(
        private readonly TableRenderer $renderer = new TableRenderer,
    ) {}

    public function supports(mixed $value): bool
    {
        return Reflect::isA($value, self::TABLE);
    }

    public function format(mixed $value): MimeBundle
    {
        /** @var object $value */
        $frame = $value->collect();

        $html = Reflect::declares($value, HtmlFormatter::METHOD)
            ? (string) $value->{HtmlFormatter::METHOD}()
            : $this->renderer->render($frame);

        return (new MimeBundle)
            ->with(MimeBundle::HTML, $html)
            ->with(MimeBundle::PLAIN, (string) $frame->format());
    }
}
