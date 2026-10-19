<?php

declare(strict_types=1);

namespace Juphyter\Console;

use RuntimeException;

/**
 * The option grammar every juphyter command shares.
 *
 * `--name value` and `--name=value`, in any order, with no positional
 * arguments: a stray one is a mistake rather than something to guess at. An
 * unknown option, a missing value or a stray argument each raise a
 * {@see RuntimeException} whose message is the complaint followed by the
 * command's own usage, which is why {@see parse()} is given that usage rather
 * than printing one of its own.
 *
 * It exists because `install` and `uninstall` had grown the same twenty lines
 * each, with a comment on one of them conceding the duplication, and `engine`
 * would have been the third. Three callers is where a shared parser earns its
 * file.
 */
final class Options
{
    /**
     * Read the options a command accepts.
     *
     * @param  list<string>  $arguments  The arguments after the subcommand name.
     * @param  list<string>  $allowed  Option names, without the leading dashes.
     * @param  string  $usage  The command's help, appended to a complaint.
     * @return array<string, string>
     *
     * @throws RuntimeException On an unknown option, a missing value, or a stray argument.
     */
    public static function parse(array $arguments, array $allowed, string $usage): array
    {
        $options = [];
        $count = count($arguments);

        for ($i = 0; $i < $count; $i++) {
            $argument = $arguments[$i];

            if (! str_starts_with($argument, '--')) {
                throw new RuntimeException(sprintf(
                    "juphyter: unexpected argument \"%s\".\n\n%s",
                    $argument,
                    $usage,
                ));
            }

            $name = substr($argument, 2);
            $value = null;

            if (str_contains($name, '=')) {
                [$name, $value] = explode('=', $name, 2);
            }

            if (! in_array($name, $allowed, true)) {
                throw new RuntimeException(sprintf(
                    "juphyter: unknown option \"--%s\".\n\n%s",
                    $name,
                    $usage,
                ));
            }

            if ($value === null) {
                $next = $arguments[$i + 1] ?? null;

                if ($next === null || str_starts_with($next, '--')) {
                    throw new RuntimeException(sprintf('juphyter: --%s needs a value.', $name));
                }

                $value = $next;
                $i++;
            }

            $options[$name] = $value;
        }

        return $options;
    }

    /**
     * Whether the arguments ask for help.
     *
     * Checked before parsing, so `--help` alongside a malformed option still
     * prints the help rather than the complaint.
     *
     * @param  list<string>  $arguments
     */
    public static function wantsHelp(array $arguments): bool
    {
        return in_array('--help', $arguments, true) || in_array('-h', $arguments, true);
    }
}
