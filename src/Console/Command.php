<?php

declare(strict_types=1);

namespace Juphyter\Console;

/**
 * A juphyter subcommand.
 *
 * A subclass is discovered and dispatched by {@see Application} purely from its
 * file name, so adding a command means adding a file and nothing else. The
 * subcommand name comes from the class name with the `Command` suffix removed
 * and the remainder kebab-cased: `EngineCommand` is `engine`,
 * `UninstallCommand` is `uninstall`, `KernelSpecCommand` is `kernel-spec`.
 *
 * ## Construction
 *
 * {@see name()} and {@see description()} are static, so the dispatcher builds
 * its listing without constructing anything. One command that could not be
 * constructed would otherwise break the listing for all of them.
 *
 * Only dispatch constructs a command, through {@see make()}. The default
 * `make()` calls `new static()`, so the ordinary case is a command with no
 * constructor at all, reading its configuration from the environment and from
 * its arguments. A command that wants constructor dependencies declares them
 * and overrides `make()` to supply them:
 *
 * ```php
 * public static function make(): static
 * {
 *     return new self(getenv('JUPHYTER_SOCKET') ?: '');
 * }
 * ```
 *
 * A command with a required constructor argument that does not override
 * `make()` is reported by the dispatcher, which names the class and says to
 * override `make()`, and only on its own name: the listing and every other
 * command keep working.
 *
 * ## Streams
 *
 * A command owns its own standard output and standard error. The dispatcher
 * writes only its own listing and usage, and never wraps or buffers a command's
 * streams, because the engine command talks to a socket and must keep its real
 * file descriptors.
 */
abstract class Command
{
    /**
     * The subcommand name, derived from the class name.
     *
     * An acronym run stays together, so `PharBuildCommand` is `phar-build` and
     * `PHARCommand` is `phar`.
     */
    final public static function name(): string
    {
        $class = static::class;
        $short = substr($class, (int) strrpos($class, '\\') + 1);

        if (str_ends_with($short, 'Command')) {
            $short = substr($short, 0, -strlen('Command'));
        }

        $hyphenated = preg_replace(
            '/(?<=[a-z0-9])(?=[A-Z])|(?<=[A-Z])(?=[A-Z][a-z])/',
            '-',
            $short
        );

        return strtolower($hyphenated ?? $short);
    }

    /**
     * One line for the subcommand listing.
     *
     * Static, so the listing never constructs a command to describe it.
     */
    abstract public static function description(): string;

    /**
     * Build the command for dispatch.
     *
     * Override this to supply constructor dependencies. See the class docblock.
     */
    public static function make(): static
    {
        return new static();
    }

    /**
     * Run the command.
     *
     * @param  list<string>  $arguments  The arguments after the subcommand name.
     * @return int The process exit code: 0 on success.
     */
    abstract public function run(array $arguments): int;
}
