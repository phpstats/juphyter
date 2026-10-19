<?php

declare(strict_types=1);

namespace Juphyter\Display\Formatters;

use ReflectionMethod;

/**
 * The two duck-typing checks the formatters share.
 *
 * Both exist so that nothing in `src/Display/` names a class from another
 * package at compile time. juphyter installs with any subset of the phpstats
 * family present, including none of it, so a formatter for a phibble table or a
 * phiz chart has to ask about a name rather than import one.
 */
final class Reflect
{
    /**
     * Whether a value is an instance of a class or interface named as a string.
     *
     * `is_a()` with `$allow_string: false` is the whole point: with a string
     * subject it would also report true for a *class name* that happens to
     * match, and it would autoload. Here the subject is always an object, and a
     * name no installed package declares simply answers false.
     *
     * @param  class-string|string  $class  A fully-qualified name, unescaped.
     */
    public static function isA(mixed $value, string $class): bool
    {
        return is_object($value) && is_a($value, $class, false);
    }

    /**
     * Whether a value's class offers `$method` as a display hook: public, not
     * static, and callable with no arguments.
     *
     * This is how a user's own class, or a sibling package in a later release,
     * opts into rich display without juphyter knowing anything about it.
     */
    public static function declares(mixed $value, string $method): bool
    {
        if (! is_object($value) || ! method_exists($value, $method)) {
            return false;
        }

        $reflection = new ReflectionMethod($value, $method);

        return $reflection->isPublic()
            && ! $reflection->isStatic()
            && $reflection->getNumberOfRequiredParameters() === 0;
    }
}
