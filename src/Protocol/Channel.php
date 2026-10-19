<?php

declare(strict_types=1);

namespace Juphyter\Protocol;

use Closure;
use RuntimeException;

/**
 * The engine's end of the line to the transport.
 *
 * A unix domain socket, one JSON object per line in each direction, UTF-8,
 * newline-terminated. The transport creates the socket in a private directory,
 * passes its path in `JUPHYTER_SOCKET`, spawns the engine and waits for it to
 * connect.
 *
 * ## Why not stdin and stdout
 *
 * Deliberately not, and this should not be simplified away. Anything that
 * writes to the real file descriptors rather than through PHP's output
 * buffering — a C-level write from an extension, an explicit
 * `fwrite(STDOUT, ...)` in a cell — would corrupt a protocol carried on stdout.
 * With a socket of its own, the engine's stdout and stderr stay free, and the
 * transport pumps whatever appears on them as ordinary `stream` output.
 *
 * ## Writing to a peer that has gone
 *
 * The last thing the engine ever writes is the error event from its shutdown
 * handler, and by then the transport may have stopped reading: it asked for the
 * shutdown, or the process is dying and the transport is already reaping it.
 * {@see send()} therefore never throws, never warns and never spins. It gives
 * up on the first refused write, marks the channel closed and returns, so a
 * dead socket cannot turn into a hang or an unhandled error on the way out.
 *
 * Silencing it takes {@see quietly()} rather than the `@` operator. `@` only
 * narrows `error_reporting`, which an error handler is free to ignore, and both
 * PHPUnit and anything embedding the engine install one that does. A handler of
 * our own, installed for the length of the call, is the only way to be sure a
 * broken pipe stays between us and the socket.
 *
 * ## Reading
 *
 * {@see receive()} returns one line without its newline, or null at end of
 * input. A line longer than {@see MAX_LINE} is not read into memory whole: the
 * rest of it is drained and the caller is told the line was unreadable, so a
 * peer that sends no newline cannot make the engine grow until it dies or wait
 * forever.
 *
 * ## Why the read timeout is off
 *
 * A socket from `stream_socket_client()` carries `default_socket_timeout` as
 * its read timeout, which is 60 seconds unless an ini file says otherwise, and
 * `fgets()` returns false when it expires. That false is indistinguishable from
 * end of input to a caller that does not look at the stream's metadata, so an
 * engine that read it as one exited after a minute of sitting between requests:
 * a notebook left alone for a minute lost its session, and the next cell failed
 * with a broken pipe.
 *
 * There is no timeout worth having here. Waiting between requests is what the
 * engine does, and for however long the front end leaves it. The timeout is
 * disabled on connect, and {@see receive()} tells a timeout from an end of
 * input through `stream_get_meta_data()` as well, so a stream this class did
 * not open is read correctly too and nothing depends on the ini value.
 *
 * A peer that really goes is still noticed. A closed socket reaches end of
 * input, `fgets()` returns false with `eof` set, and the engine's loop returns
 * on that: it is what ends an engine whose transport was killed outright. A
 * cell that never finishes is the watchdog's business rather than this class's.
 */
final class Channel
{
    /** The most one line may carry, beyond which it is treated as unreadable. */
    public const MAX_LINE = 8 * 1024 * 1024;

    /** How many refused-but-not-failed writes to tolerate before giving up. */
    private const MAX_STALLS = 64;

    /** @var resource|null */
    private $stream;

    /** @param  resource  $stream */
    public function __construct($stream)
    {
        $this->stream = $stream;
    }

    /**
     * Connect to the socket the transport is listening on.
     *
     * @throws RuntimeException When the socket cannot be reached, which is an
     *                          engine-level failure rather than a cell's.
     */
    public static function connect(string $path, float $timeout = 10.0): self
    {
        $code = 0;
        $message = '';

        $stream = self::quietly(static function () use ($path, $timeout, &$code, &$message) {
            return stream_socket_client(
                'unix://'.$path,
                $code,
                $message,
                $timeout,
                STREAM_CLIENT_CONNECT,
            );
        });

        if ($stream === false) {
            throw new RuntimeException(sprintf(
                'juphyter: could not connect to the transport socket at %s (%s).',
                $path,
                $message === '' ? 'error '.$code : $message,
            ));
        }

        // Blocking reads, with no timeout: see the class comment. The $timeout
        // above is the connect timeout and is a different thing.
        stream_set_timeout($stream, -1);

        return new self($stream);
    }

    public function isOpen(): bool
    {
        return $this->stream !== null;
    }

    /**
     * One line, without its newline, or null at end of input.
     *
     * An unreadable line comes back as the empty string after the rest of it
     * has been drained, which the caller reports and carries on from.
     *
     * A read that timed out is waited out rather than reported as end of input.
     * Each turn of that loop costs a whole timeout, so it cannot spin.
     */
    public function receive(): ?string
    {
        if ($this->stream === null) {
            return null;
        }

        $line = fgets($this->stream, self::MAX_LINE);

        while ($line === false && $this->timedOut()) {
            $line = fgets($this->stream, self::MAX_LINE);
        }

        if ($line === false) {
            return null;
        }

        if (! str_ends_with($line, "\n")) {
            // Either the peer closed mid-line, in which case there is nothing
            // more, or the line is longer than we will read, in which case the
            // remainder is drained so the next read starts on a boundary.
            if (feof($this->stream)) {
                return rtrim($line, "\r\n") === '' ? null : rtrim($line, "\r\n");
            }

            $this->drainLine();

            return '';
        }

        return rtrim($line, "\r\n");
    }

    /** Write one event, giving up rather than failing if the peer has gone. */
    public function send(Event $event): void
    {
        if ($this->stream === null) {
            return;
        }

        $payload = $event->encode();
        $length = strlen($payload);
        $written = 0;
        $stalls = 0;

        while ($written < $length) {
            $stream = $this->stream;
            $chunk = substr($payload, $written);
            $bytes = self::quietly(static fn () => fwrite($stream, $chunk));

            if ($bytes === false) {
                $this->drop();

                return;
            }

            if ($bytes === 0) {
                if (++$stalls >= self::MAX_STALLS) {
                    $this->drop();

                    return;
                }

                continue;
            }

            $stalls = 0;
            $written += $bytes;
        }
    }

    public function close(): void
    {
        if ($this->stream === null) {
            return;
        }

        $stream = $this->stream;
        self::quietly(static fn () => fclose($stream));
        $this->stream = null;
    }

    /** Read to the end of an over-long line, so the next read starts cleanly. */
    private function drainLine(): void
    {
        if ($this->stream === null) {
            return;
        }

        while (! feof($this->stream)) {
            $chunk = fgets($this->stream, self::MAX_LINE);

            if ($chunk === false) {
                // The same distinction receive() makes: a timeout here would
                // leave the next read part way through a line.
                if ($this->timedOut()) {
                    continue;
                }

                return;
            }

            if (str_ends_with($chunk, "\n")) {
                return;
            }
        }
    }

    /**
     * Whether the last read expired rather than reaching the end of the input.
     *
     * Both have `fgets()` return false, and only the stream's metadata tells
     * them apart.
     */
    private function timedOut(): bool
    {
        if ($this->stream === null) {
            return false;
        }

        $meta = stream_get_meta_data($this->stream);

        return ($meta['timed_out'] ?? false) && ! ($meta['eof'] ?? true);
    }

    /** Forget a socket there is no point writing to again. */
    private function drop(): void
    {
        $this->stream = null;
    }

    /**
     * Run an operation with PHP's diagnostics swallowed, whatever handler is
     * installed.
     *
     * A refused write to a socket the transport has stopped reading is expected
     * here, not exceptional, and it must not surface as a warning in a
     * notebook, a test run or a host application's log.
     */
    private static function quietly(Closure $operation): mixed
    {
        set_error_handler(static fn (): bool => true);

        try {
            return $operation();
        } finally {
            restore_error_handler();
        }
    }
}
