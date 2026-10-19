<?php

declare(strict_types=1);

namespace Juphyter;

use Juphyter\Display\Registry;

/*
 * juphyter's exported functions, loaded through composer.json's autoload.files.
 *
 * The guard matters here more than it does in a package that is only ever
 * installed once. The engine loads juphyter's own autoloader and then the
 * notebook project's, and that project may well have its own copy of juphyter:
 * two Composer autoloaders, each with its own include-once bookkeeping, would
 * otherwise require this file twice and die redeclaring the function.
 */
if (! function_exists('Juphyter\display')) {
    /**
     * Publish a value as notebook output from the middle of a cell.
     *
     * A cell's last expression is displayed for you. This is for the cell that
     * wants to show several things, or something other than its result: a frame
     * and then the plot of it, a table per group, progress through a loop.
     *
     *     Juphyter\display($summary, $plot);
     *
     * Each value goes through {@see Registry::current()} and reaches the front
     * end as its own output, in the order given. Outside a kernel there is no
     * front end, and `text/plain` is written to standard output instead.
     */
    function display(mixed $value, mixed ...$more): void
    {
        $registry = Registry::current();

        foreach ([$value, ...$more] as $item) {
            Registry::publish($registry->format($item));
        }
    }
}
