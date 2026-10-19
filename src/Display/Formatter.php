<?php

declare(strict_types=1);

namespace Juphyter\Display;

/**
 * One rule for turning a PHP value into its representations.
 *
 * {@see Registry} asks each formatter in turn whether it supports a value and
 * takes the first bundle produced, so the two methods are a pair: `format()` is
 * only ever called for a value `supports()` accepted.
 *
 * A formatter for a class from another package matches it by its
 * fully-qualified name as a string, through {@see Formatters\Reflect::isA()},
 * and never by an imported class or an `instanceof` against one. juphyter has
 * no hard dependency on phibble, phplyr or phiz, so naming one of their classes
 * at compile time would break loading for everyone who has not installed it.
 */
interface Formatter
{
    public function supports(mixed $value): bool;

    public function format(mixed $value): MimeBundle;
}
