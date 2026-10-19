<?php

declare(strict_types=1);

namespace Juphyter\Tests\Engine;

use RuntimeException;

/**
 * A real transport, for testing the engine the way the Python one will drive it.
 *
 * It listens on a unix socket in a temporary directory, spawns
 * `bin/juphyter engine` with `JUPHYTER_SOCKET` pointing at it, accepts the
 * connection, and then sends requests and collects events. Nothing is mocked:
 * these tests exercise the socket, the framing and the process boundary.
 *
 * ## Why the child's output goes to files
 *
 * The engine's stdout and stderr are redirected to files rather than to pipes.
 * A pipe has a fixed buffer, so a harness that reads one stream to completion
 * before the other deadlocks the moment a cell prints more than fits — and the
 * engine's descriptors are deliberately left free for exactly that kind of
 * output. Files cannot fill, so the order they are read in cannot matter.
 */
final class Harness
{
    /** @var resource|null */
    private $server;

    /** @var resource|null */
    private $connection;

    /** @var resource|null */
    private $process;

    private int $sent = 0;

    private readonly string $directory;

    private readonly string $socket;

    private function __construct(
        /** The directory the engine runs in, which is also where it looks for a project. */
        public readonly string $workingDirectory,
    ) {
        $this->directory = sys_get_temp_dir().'/juphyter-harness-'.bin2hex(random_bytes(6));
        mkdir($this->directory, 0o777, true);
        $this->socket = $this->directory.'/engine.sock';
    }

    /**
     * Start an engine and wait for it to connect.
     *
     * @param  array<string, string>  $environment  Added to the engine's environment.
     * @param  list<string>  $arguments  Extra arguments for `juphyter engine`.
     */
    public static function start(
        ?string $workingDirectory = null,
        array $environment = [],
        array $arguments = [],
        float $timeout = 10.0,
    ): self {
        $harness = new self($workingDirectory ?? dirname(__DIR__, 2));

        $harness->server = @stream_socket_server('unix://'.$harness->socket, $code, $message);

        if ($harness->server === false) {
            throw new RuntimeException('could not listen on '.$harness->socket.': '.$message);
        }

        $harness->process = proc_open(
            [PHP_BINARY, dirname(__DIR__, 2).'/bin/juphyter', 'engine', ...$arguments],
            [
                0 => ['file', '/dev/null', 'r'],
                1 => ['file', $harness->directory.'/stdout', 'w'],
                2 => ['file', $harness->directory.'/stderr', 'w'],
            ],
            $pipes,
            $harness->workingDirectory,
            ['JUPHYTER_SOCKET' => $harness->socket, 'PATH' => getenv('PATH'), ...$environment],
        );

        if (! is_resource($harness->process)) {
            throw new RuntimeException('could not start the engine');
        }

        $connection = @stream_socket_accept($harness->server, $timeout);

        if ($connection === false) {
            $harness->stop();

            throw new RuntimeException('the engine never connected: '.$harness->stderr());
        }

        $harness->connection = $connection;
        stream_set_timeout($connection, (int) $timeout);

        return $harness;
    }

    /** Send a request and return its id. */
    public function send(string $type, array $fields = []): string
    {
        $id = 'r'.(++$this->sent);
        $this->write(json_encode(['id' => $id, 'type' => $type, ...$fields])."\n");

        return $id;
    }

    /**
     * Write a raw line, for testing what the engine does with rubbish.
     *
     * Suppressed, because some tests write deliberately to an engine that has
     * already exited: the refused write is the thing being checked, not a
     * problem with the harness.
     */
    public function write(string $raw): void
    {
        if ($this->connection !== null) {
            @fwrite($this->connection, $raw);
        }
    }

    /**
     * Read events until the one that ends $id, and return them all.
     *
     * A terminal event is `ok`, `error` or anything ending in `_reply`, which
     * is the engine's own rule: exactly one of those per request.
     *
     * @return list<array<string, mixed>>
     */
    public function collect(string $id, float $timeout = 10.0): array
    {
        $events = [];
        $deadline = microtime(true) + $timeout;

        while (microtime(true) < $deadline) {
            $event = $this->next();

            if ($event === null) {
                break;
            }

            $events[] = $event;

            if (($event['id'] ?? null) === $id && self::isTerminal($event['type'])) {
                break;
            }
        }

        return $events;
    }

    /** Read one event, or null at end of input. */
    public function next(): ?array
    {
        if ($this->connection === null) {
            return null;
        }

        $line = fgets($this->connection);

        if ($line === false || trim($line) === '') {
            return null;
        }

        $decoded = json_decode(trim($line), true);

        return is_array($decoded) ? $decoded : null;
    }

    /** Send a request and collect everything it produces, in one call. */
    public function request(string $type, array $fields = [], float $timeout = 10.0): array
    {
        return $this->collect($this->send($type, $fields), $timeout);
    }

    /** Send SIGINT to the engine, which is what the transport does on a stop. */
    public function interrupt(): bool
    {
        $status = $this->status();

        if ($status === null || ! function_exists('posix_kill')) {
            return false;
        }

        return posix_kill($status['pid'], SIGINT);
    }

    /** @return array<string, mixed>|null */
    public function status(): ?array
    {
        return is_resource($this->process) ? proc_get_status($this->process) : null;
    }

    /** Close the connection from this end, leaving the engine to notice. */
    public function disconnect(): void
    {
        if ($this->connection !== null) {
            fclose($this->connection);
            $this->connection = null;
        }
    }

    /** Wait for the engine to exit and return its code, or null on a timeout. */
    public function wait(float $timeout = 10.0): ?int
    {
        $deadline = microtime(true) + $timeout;

        while (microtime(true) < $deadline) {
            $status = $this->status();

            if ($status === null) {
                return null;
            }

            if (! $status['running']) {
                return $status['exitcode'];
            }

            usleep(5_000);
        }

        return null;
    }

    public function stdout(): string
    {
        return (string) @file_get_contents($this->directory.'/stdout');
    }

    public function stderr(): string
    {
        return (string) @file_get_contents($this->directory.'/stderr');
    }

    /** Stop the engine and remove everything the harness made. */
    public function stop(): void
    {
        $this->disconnect();

        if (is_resource($this->process)) {
            $status = proc_get_status($this->process);

            if ($status['running']) {
                proc_terminate($this->process, 9);
            }

            proc_close($this->process);
        }

        $this->process = null;

        if (is_resource($this->server)) {
            fclose($this->server);
        }

        $this->server = null;

        foreach (['stdout', 'stderr', 'engine.sock'] as $file) {
            @unlink($this->directory.'/'.$file);
        }

        @rmdir($this->directory);
    }

    private static function isTerminal(string $type): bool
    {
        return $type === 'ok' || $type === 'error' || str_ends_with($type, '_reply');
    }
}
