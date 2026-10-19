<?php

declare(strict_types=1);

namespace Juphyter\Console;

use Juphyter\Engine\Engine;
use Juphyter\Project\Autoload;
use Juphyter\Project\Config;
use Juphyter\Protocol\Channel;
use Juphyter\Protocol\Event;
use RuntimeException;
use Throwable;

/**
 * `juphyter engine`, the process the transport spawns.
 *
 * It connects to the socket named in `JUPHYTER_SOCKET`, loads the notebook's
 * project, and serves requests until the transport asks it to stop. It is not
 * meant to be run by hand: without a transport listening there is nothing to
 * connect to, and the error says so.
 *
 * The socket path and the working directory are read in {@see make()}, which is
 * the only place the environment is touched, so the constructor stays the seam
 * a test builds through.
 *
 * ## Where an error before the loop goes
 *
 * Once the channel is open, a failure to load the project is reported as an
 * engine-level `error` event with a null id and the process exits non-zero,
 * because the transport is listening by then and a notebook showing "the
 * autoloader you named is not there" is worth far more than a silent exit. A
 * failure to open the channel at all has nowhere to go but stderr, which the
 * transport pumps as stream output.
 */
final class EngineCommand extends Command
{
    /** @var list<string> */
    private const OPTIONS = ['socket', 'autoload', 'directory'];

    public function __construct(
        private readonly ?string $socket,
        private readonly string $directory,
    ) {}

    public static function description(): string
    {
        return 'Run the execution engine the transport talks to';
    }

    public static function make(): static
    {
        $socket = getenv('JUPHYTER_SOCKET');
        $directory = getcwd();

        return new self(
            is_string($socket) && $socket !== '' ? $socket : null,
            $directory === false ? '.' : $directory,
        );
    }

    public function run(array $arguments): int
    {
        if (Options::wantsHelp($arguments)) {
            fwrite(STDOUT, self::usage());

            return 0;
        }

        try {
            $options = Options::parse($arguments, self::OPTIONS, self::usage());
        } catch (RuntimeException $e) {
            fwrite(STDERR, $e->getMessage()."\n");

            return 1;
        }

        $socket = $options['socket'] ?? $this->socket;

        if ($socket === null) {
            fwrite(STDERR, self::missingSocket());

            return 1;
        }

        try {
            $channel = Channel::connect($socket);
        } catch (RuntimeException $e) {
            fwrite(STDERR, $e->getMessage()."\n");

            return 1;
        }

        // From here the transport is listening, so anything that goes wrong is
        // reported on the channel rather than to a stderr nobody is reading as
        // an error.
        try {
            $this->loadProject($options);
        } catch (Throwable $e) {
            $channel->send(Event::error(null, $e::class, $e->getMessage()));
            $channel->close();

            return 1;
        }

        return (new Engine($channel))->serve();
    }

    /**
     * Put the notebook's project in scope.
     *
     * The config is read first, because it may name the autoloader, and its
     * memory limit and include path are applied whether or not it does.
     *
     * @param  array<string, string>  $options
     */
    private function loadProject(array $options): void
    {
        $directory = $options['directory'] ?? $this->directory;
        $config = Config::find($directory);
        $config?->apply();

        $autoload = isset($options['autoload'])
            ? Autoload::locate($directory, $config, static fn (string $name) => $options['autoload'])
            : Autoload::locate($directory, $config);

        $autoload->load();
    }

    private static function missingSocket(): string
    {
        return "juphyter: no socket to connect to. The engine is spawned by the kernel's "
            ."transport, which sets JUPHYTER_SOCKET to the socket it is listening on.\n"
            ."To run it by hand, pass --socket PATH.\n";
    }

    public static function usage(): string
    {
        return <<<'TEXT'
            Usage: juphyter engine [options]

            Runs the execution engine. The transport spawns this and talks to it over
            the unix socket named in JUPHYTER_SOCKET; it is not useful on its own.

            Options:
              --socket PATH     Connect here instead of to $JUPHYTER_SOCKET.
              --autoload PATH   Use this autoloader instead of searching for one.
              --directory PATH  Treat PATH as the notebook's directory.
              -h, --help        Print this text.

            TEXT;
    }
}
