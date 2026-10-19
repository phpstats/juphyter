<?php

declare(strict_types=1);

namespace Juphyter\Display\Formatters;

use Juphyter\Display\Formatter;
use Juphyter\Display\MimeBundle;
use Throwable;

/**
 * A `Throwable` held as a value rather than thrown: its class, its message and
 * where it was created.
 *
 * A thrown exception is not this: it ends the cell and the executor reports it
 * as an `error` event with a traceback. This is the cell that caught one, or
 * built one, and ends with it as its result.
 */
final class ThrowableFormatter implements Formatter
{
    public function supports(mixed $value): bool
    {
        return $value instanceof Throwable;
    }

    public function format(mixed $value): MimeBundle
    {
        /** @var Throwable $value */
        $message = $value->getMessage();

        return MimeBundle::plain(
            $value::class.($message === '' ? '' : ': '.$message)
            ."\n  in ".$value->getFile().':'.$value->getLine()
        );
    }
}
