<?php

declare(strict_types=1);

namespace Juphyter\Engine;

use ReflectionObject;
use Throwable;

/**
 * Tab completion, deliberately small.
 *
 * Three things, each reflection on state the session already holds, and nothing
 * that needs to understand the code around the cursor:
 *
 * - after `$`, the session's variables;
 * - a bare prefix, against the functions and classes that are declared;
 * - after `->` or `::` on a variable, that value's public members.
 *
 * Anything harder — completing a chained call, a parameter, a string key — is a
 * later milestone and would need the parser and a type model to do properly.
 * Guessing is worse than offering nothing, because a wrong completion is
 * accepted by a keystroke.
 *
 * ## What the reply means
 *
 * `cursor_start` and `cursor_end` bracket the text the front end replaces, so
 * the matches are bare names and the range covers the partial name only: for
 * `$fra`, the range starts after the `$`, and the matches are `frame` rather
 * than `$frame`. That is what Jupyter front ends expect, and it keeps a
 * completion from doubling the sigil.
 */
final class Completer
{
    /** The most matches to return, so a bare prefix cannot produce a huge reply. */
    public const LIMIT = 200;

    public function __construct(private readonly Executor $executor) {}

    /**
     * Complete the code up to the cursor.
     *
     * @param  int  $cursor  A byte offset into $code.
     * @return array{matches: list<string>, cursor_start: int, cursor_end: int}
     */
    public function complete(string $code, int $cursor): array
    {
        $before = substr($code, 0, $cursor);

        // A member of something the session holds: `$df->col`, `$x::CONST`.
        if (preg_match('/\$([A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff]*)\s*(->|::)\s*([A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff]*)?$/', $before, $match) === 1) {
            $partial = $match[3] ?? '';

            return self::reply(
                $this->members($match[1], $match[2], $partial),
                $cursor - strlen($partial),
                $cursor,
            );
        }

        // A variable: `$fra`, or a bare `$` for everything in scope.
        if (preg_match('/\$([A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff]*)?$/', $before, $match) === 1) {
            $partial = $match[1] ?? '';

            return self::reply(
                self::matching(array_keys($this->executor->variables()), $partial),
                $cursor - strlen($partial),
                $cursor,
            );
        }

        // A name: a function or a class, which may be namespaced.
        if (preg_match('/([A-Za-z_\x80-\xff\\\\][A-Za-z0-9_\x80-\xff\\\\]*)$/', $before, $match) === 1) {
            $partial = $match[1];

            return self::reply(
                self::matching(self::declaredNames(), $partial),
                $cursor - strlen($partial),
                $cursor,
            );
        }

        return self::reply([], $cursor, $cursor);
    }

    /**
     * The public members of what a variable currently holds.
     *
     * Only a variable, because its value is in hand and needs no inference. A
     * bare class name before `::` would need the code around it resolved
     * against the session's imports, which is the next milestone's work.
     *
     * @return list<string>
     */
    private function members(string $variable, string $operator, string $partial): array
    {
        $variables = $this->executor->variables();

        if (! array_key_exists($variable, $variables)) {
            return [];
        }

        $value = $variables[$variable];

        if (! is_object($value)) {
            return [];
        }

        try {
            $reflection = new ReflectionObject($value);
            $names = [];

            foreach ($reflection->getMethods() as $method) {
                if ($method->isPublic() && $method->isStatic() === ($operator === '::')) {
                    $names[] = $method->getName();
                }
            }

            if ($operator === '::') {
                foreach ($reflection->getConstants() as $name => $ignored) {
                    $names[] = $name;
                }

                foreach ($reflection->getProperties() as $property) {
                    if ($property->isPublic() && $property->isStatic()) {
                        $names[] = $property->getName();
                    }
                }
            } else {
                foreach ($reflection->getProperties() as $property) {
                    if ($property->isPublic() && ! $property->isStatic()) {
                        $names[] = $property->getName();
                    }
                }

                // A dynamic property is as real as a declared one, and a value
                // built in a notebook often has them.
                foreach (array_keys(get_object_vars($value)) as $name) {
                    $names[] = (string) $name;
                }
            }

            return self::matching($names, $partial);
        } catch (Throwable) {
            // Reflection on an exotic internal object can fail; offering
            // nothing is the right answer then.
            return [];
        }
    }

    /**
     * Every function and class name this process has.
     *
     * Internal names are included, because `str_` completing to `str_repeat` is
     * most of what completion is for, and the session's own declarations are
     * among the user ones by the time they matter.
     *
     * @return list<string>
     */
    private static function declaredNames(): array
    {
        $functions = get_defined_functions();

        return [
            ...$functions['internal'],
            ...$functions['user'],
            ...get_declared_classes(),
            ...get_declared_interfaces(),
            ...get_declared_traits(),
        ];
    }

    /**
     * The names starting with $partial, matched case-insensitively because PHP
     * resolves functions and classes that way.
     *
     * @param  list<string>  $names
     * @return list<string>
     */
    private static function matching(array $names, string $partial): array
    {
        $prefix = strtolower(ltrim($partial, '\\'));

        $matches = $partial === ''
            ? $names
            : array_filter($names, static fn (string $name) => str_starts_with(strtolower($name), $prefix));

        $matches = array_values(array_unique($matches));
        sort($matches, SORT_NATURAL | SORT_FLAG_CASE);

        return array_slice($matches, 0, self::LIMIT);
    }

    /**
     * @param  list<string>  $matches
     * @return array{matches: list<string>, cursor_start: int, cursor_end: int}
     */
    private static function reply(array $matches, int $start, int $end): array
    {
        return ['matches' => $matches, 'cursor_start' => max(0, $start), 'cursor_end' => $end];
    }
}
