<?php

declare(strict_types=1);

namespace Juphyter\Engine;

/**
 * The errors that end the process instead of being thrown.
 *
 * An exhausted memory limit, an exceeded time limit and a compile error inside
 * `eval()` are not `Throwable`: nothing catches them, and the only place they
 * can still be seen is a shutdown function reading {@see error_get_last()}.
 * {@see Executor} registers one and reports through it, so the notebook shows
 * the error and the front end can offer a restart, rather than the kernel
 * simply vanishing.
 *
 * The cleaner closes off the compile errors it can — a duplicate `use`, a
 * misplaced `declare`, a redeclaration — but nothing can close off running out
 * of memory, so this path stays.
 */
final class Fatal
{
    /** The `ename` a fatal is reported under. */
    public const ENAME = 'FatalError';

    /**
     * The error types that end the process.
     *
     * `E_USER_ERROR` is here because `trigger_error(..., E_USER_ERROR)` is also
     * fatal, and `E_PARSE` and `E_COMPILE_ERROR` because `eval()` of source the
     * cleaner let through can still produce them on an older runtime than the
     * one the parser was configured for.
     */
    private const TYPES = E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR | E_USER_ERROR;

    public static function isFatal(int $type): bool
    {
        return ($type & self::TYPES) !== 0;
    }

    /**
     * The fatal error that is ending this process, or null.
     *
     * @return array{type: int, message: string, file: string, line: int}|null
     */
    public static function pending(): ?array
    {
        $error = error_get_last();

        if ($error === null || ! self::isFatal($error['type'])) {
            return null;
        }

        return $error;
    }

    /**
     * The `evalue` for a fatal, with the location said in the cell's terms when
     * it can be.
     *
     * @param  array{type: int, message: string, file: string, line: int}  $error
     * @param  string|null  $where  A description such as "Cell 3, line 2", from
     *                              {@see Traceback::describe()}.
     */
    public static function describe(array $error, ?string $where = null): string
    {
        $message = trim($error['message']);

        if ($where !== null) {
            return $message.' in '.$where;
        }

        return $message;
    }
}
