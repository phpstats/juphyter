<?php

declare(strict_types=1);

namespace Juphyter\Console;

use ArgumentCountError;
use ReflectionClass;

/**
 * The `juphyter` dispatcher.
 *
 * ## Discovery rule
 *
 * The application reads its command directory, keeps every entry whose name
 * ends in `Command.php`, maps each to a class by prefixing the command
 * namespace, and keeps the ones the autoloader resolves to a concrete subclass
 * of {@see Command}. The subcommand name is {@see Command::name()}, derived from
 * the class name.
 *
 * Scanning, rather than a static list or a name-to-class lookup alone, is what
 * lets `juphyter` with no arguments list the commands that exist. Listing and
 * dispatch read the same map, so the two can never disagree, and a later task
 * adds a command by writing `src/Console/<Name>Command.php` and never by
 * editing this class or `bin/juphyter`.
 *
 * The directory is read with {@see scandir()} rather than {@see glob()} because
 * `glob()` does not understand stream wrappers: inside the PHAR the directory
 * is a `phar://` path, and only the former sees it.
 *
 * An empty or missing directory is not an error. It is the state of this
 * package before the engine and install commands land, and the listing is
 * simply empty.
 *
 * ## Construction
 *
 * The listing reads {@see Command::name()} and {@see Command::description()},
 * which are both static, so it never constructs a command. That is deliberate:
 * when the listing constructed every command it found, a single command with a
 * constructor argument broke the no-argument listing and the unknown-command
 * path for every invocation. Only dispatch constructs, through
 * {@see Command::make()}, and a command that cannot be built now fails on its
 * own name alone.
 */
final class Application
{
    /** @var array<string, class-string<Command>>|null Memoized by {@see commands()}. */
    private ?array $commands = null;

    /**
     * @param  string  $directory  Where the command classes live.
     * @param  string  $namespace  Their namespace, with a trailing separator.
     */
    public function __construct(
        private readonly string $directory,
        private readonly string $namespace,
    ) {}

    /** The dispatcher over this package's own commands. */
    public static function default(): self
    {
        return new self(__DIR__, __NAMESPACE__.'\\');
    }

    /**
     * The available commands, keyed by subcommand name and sorted by it.
     *
     * Scanned once per application, because the listing and dispatch both ask.
     *
     * @return array<string, class-string<Command>>
     */
    public function commands(): array
    {
        if ($this->commands !== null) {
            return $this->commands;
        }

        $entries = is_dir($this->directory) ? scandir($this->directory) : false;

        if ($entries === false) {
            return $this->commands = [];
        }

        $commands = [];

        foreach ($entries as $entry) {
            if (! str_ends_with($entry, 'Command.php')) {
                continue;
            }

            /** @var class-string $class */
            $class = $this->namespace.substr($entry, 0, -strlen('.php'));

            if (! class_exists($class) || ! is_subclass_of($class, Command::class)) {
                continue;
            }

            if ((new ReflectionClass($class))->isAbstract()) {
                continue;
            }

            /** @var class-string<Command> $class */
            $commands[$class::name()] = $class;
        }

        ksort($commands);

        return $this->commands = $commands;
    }

    /**
     * Dispatch one invocation.
     *
     * @param  list<string>  $argv  The process arguments, script name included.
     * @param  resource  $stdout
     * @param  resource  $stderr
     * @return int The exit code to leave with.
     */
    public function run(array $argv, $stdout, $stderr): int
    {
        $arguments = array_slice($argv, 1);
        $name = $arguments[0] ?? null;

        if ($name === null || $name === '-h' || $name === '--help') {
            fwrite($stdout, $this->usage());

            return 0;
        }

        $commands = $this->commands();

        if (! isset($commands[$name])) {
            fwrite($stderr, sprintf("juphyter: unknown command \"%s\".\n\n", $name));
            fwrite($stderr, $this->usage());

            return 1;
        }

        $class = $commands[$name];

        // Only construction is guarded. Whatever the command's own run() throws
        // is its business and is left alone.
        try {
            $command = $class::make();
        } catch (ArgumentCountError $e) {
            fwrite($stderr, sprintf(
                "juphyter: cannot build %s. A command with constructor dependencies has to "
                ."override make() to supply them. (%s)\n",
                $class,
                $e->getMessage(),
            ));

            return 1;
        }

        return $command->run(array_slice($arguments, 1));
    }

    /** The usage text, with the subcommand listing. */
    public function usage(): string
    {
        $usage = "Usage: juphyter <command> [<args>]\n\n";
        $commands = $this->commands();

        if ($commands === []) {
            return $usage."No commands are available.\n";
        }

        $width = max(array_map('strlen', array_keys($commands)));
        $usage .= "Commands:\n";

        foreach ($commands as $name => $class) {
            $usage .= sprintf("  %-{$width}s  %s\n", $name, $class::description());
        }

        return $usage;
    }
}
