<?php

declare(strict_types=1);

namespace Juphyter\Engine;

use Juphyter\Display\MimeBundle;
use Juphyter\Display\Registry;
use Juphyter\Protocol\Channel;
use Juphyter\Protocol\Event;
use Juphyter\Protocol\Request;
use RuntimeException;
use Throwable;

/**
 * The engine's request loop.
 *
 * It reads requests from the {@see Channel}, serves each one, and answers every
 * one with exactly one terminal event. It owns the three things the executor
 * deliberately does not: the execution count, `silent`, and the display
 * registry's wiring.
 *
 * ## One session per process
 *
 * One {@see Session}, one {@see Executor}, for the life of the process. That is
 * not merely convenient: the cleaner's guard against redeclaring a name is
 * per-session, while PHP's function and class tables are per-process, so two
 * sessions sharing a process would meet the uncatchable fatal the guard exists
 * to prevent.
 *
 * ## Who owns the execution count
 *
 * This class, and nothing else. It is the number the front end shows in
 * `In [N]` and the number a traceback frame is labelled with, and it advances
 * only when a request asks for `store_history`. {@see Executor::execute()} is
 * told the number and never derives one, so the label a notebook shows and the
 * label a traceback shows cannot drift apart. A request with
 * `store_history: false` reports the count as it stands, which is the last
 * stored cell's, or 0 before any cell has been stored.
 *
 * ## silent
 *
 * Served by wrapping the sink: `display` and `result` are dropped for that
 * request and everything else goes through, so an error in a silent cell is
 * still reported. The executor knows nothing about it.
 *
 * ## The display registry
 *
 * The engine is where juphyter's display layer is wired to its execution layer,
 * which keeps {@see Executor} free of any reference to it. Two directions:
 * values the executor needs rendered go out through a formatter closure built
 * on {@see Registry::format()}, and values a cell publishes with
 * `Juphyter\display()` come back in through the publisher installed with
 * {@see Registry::publishUsing()}. Both are installed before the first request
 * is served and taken down after the last, because the publisher is static and
 * a stale one would send a later cell's output to a dead executor.
 */
final class Engine
{
    /** What `kernel_info` reports, beyond the running version. */
    private const LANGUAGE = [
        'name' => 'php',
        'mimetype' => 'application/x-php',
        'file_extension' => '.php',
        'pygments_lexer' => 'php',
        'codemirror_mode' => 'php',
    ];

    private int $executionCount = 0;

    private ?string $currentId = null;

    private bool $silent = false;

    private readonly Executor $executor;

    private readonly Completer $completer;

    private readonly Cleaner $cleaner;

    public function __construct(
        private readonly Channel $channel,
        ?Registry $registry = null,
    ) {
        $registry ??= Registry::default();

        $this->cleaner = new Cleaner();
        $this->executor = new Executor(
            fn (array $event) => $this->emit($event),
            new Session(),
            $this->cleaner,
            static fn (mixed $value): array => (
                static fn (MimeBundle $bundle): array => [
                    'bundle' => $bundle->data(),
                    'metadata' => $bundle->metadata(),
                ]
            )($registry->format($value)),
        );
        $this->completer = new Completer($this->executor);
    }

    /** For a test that wants to look at the session the engine is serving. */
    public function executor(): Executor
    {
        return $this->executor;
    }

    /**
     * Serve requests until the transport asks to stop or closes the channel.
     *
     * @return int The exit code for the process.
     */
    public function serve(): int
    {
        if (Interrupt::available()) {
            pcntl_async_signals(true);
        }

        // Before the first request, so a cell cannot publish into a registry
        // that has no publisher, and taken down after the last so a static
        // publisher cannot outlive the executor it points at.
        Registry::publishUsing(
            fn (MimeBundle $bundle) => $this->executor->publishBundle($bundle->data(), $bundle->metadata()),
        );

        try {
            while (true) {
                $line = $this->channel->receive();

                if ($line === null) {
                    // The transport closed the channel. Nothing to answer and
                    // nothing to complain about.
                    return 0;
                }

                if (trim($line) === '') {
                    $this->channel->send(Event::error(
                        null,
                        Event::PROTOCOL_ERROR,
                        'The engine read a line it could not use: it was empty or longer than '
                        .Channel::MAX_LINE.' bytes.',
                    ));

                    continue;
                }

                if ($this->dispatch($line)) {
                    return 0;
                }
            }
        } finally {
            Registry::publishUsing(null);
            Registry::setCurrent(null);
            $this->channel->close();
        }
    }

    /**
     * Serve one line.
     *
     * @return bool True when the engine should stop serving.
     */
    private function dispatch(string $line): bool
    {
        try {
            $request = Request::parse($line);
        } catch (RuntimeException $e) {
            // Malformed: there is no id to answer against, so the complaint
            // goes out unattached and the loop carries on.
            $this->channel->send(Event::error(null, Event::PROTOCOL_ERROR, $e->getMessage()));

            return false;
        }

        $this->currentId = $request->id;

        try {
            return match ($request->type) {
                Request::EXECUTE => $this->execute($request),
                Request::COMPLETE => $this->complete($request),
                Request::IS_COMPLETE => $this->isComplete($request),
                Request::KERNEL_INFO => $this->kernelInfo($request),
                Request::SHUTDOWN => $this->shutdown($request),
                default => $this->unknown($request),
            };
        } catch (Throwable $e) {
            // Anything the engine itself got wrong is still answered, so the
            // request cannot be left without a terminal event.
            $this->channel->send(Event::error(
                $request->id,
                $e::class,
                $e->getMessage(),
                [$e::class.': '.$e->getMessage()],
            ));

            return false;
        } finally {
            $this->currentId = null;
            $this->silent = false;
        }
    }

    private function execute(Request $request): bool
    {
        $this->silent = $request->bool('silent', false);

        if ($request->bool('store_history', true)) {
            $this->executionCount++;
        }

        // The executor emits every event for this cell, terminal one included,
        // through the sink. Nothing is added here.
        $this->executor->execute($request->code(), $this->executionCount);

        return false;
    }

    private function complete(Request $request): bool
    {
        $reply = $this->completer->complete($request->code(), $request->cursor());

        $this->channel->send(Event::completeReply(
            $request->id,
            $reply['matches'],
            $reply['cursor_start'],
            $reply['cursor_end'],
        ));

        return false;
    }

    /**
     * Answer whether the source is finished.
     *
     * The verdict comes from the cleaner and nowhere else: a {@see CleanedCell}
     * is `complete`, including when it only parsed once a semicolon was
     * appended, a {@see CellError} that is `incomplete` is `incomplete`, and
     * any other refusal is `invalid`. Matching on a parser message here would
     * state the same rule twice and drift the first time the parser reworded
     * one.
     *
     * The cleaning is done against a throwaway session. A front end may ask
     * this on every keystroke, and cleaning against the live session would lift
     * the half-typed cell's `use` statements into it as a side effect of
     * asking.
     */
    private function isComplete(Request $request): bool
    {
        $code = $request->code();
        $cleaned = $this->cleaner->clean($code, new Session());

        [$status, $indent] = match (true) {
            $cleaned instanceof CleanedCell => ['complete', ''],
            $cleaned->incomplete => ['incomplete', self::indentFor($code)],
            default => ['invalid', ''],
        };

        $this->channel->send(Event::isCompleteReply($request->id, $status, $indent));

        return false;
    }

    private function kernelInfo(Request $request): bool
    {
        $this->channel->send(Event::kernelInfoReply(
            $request->id,
            ['version' => PHP_VERSION, ...self::LANGUAGE],
            sprintf('juphyter, a PHP kernel. PHP %s on %s.', PHP_VERSION, PHP_OS_FAMILY),
        ));

        return false;
    }

    /**
     * Stop serving.
     *
     * The executor is told the shutdown was asked for, so that its shutdown
     * handler does not report a cell as having ended the process itself. The
     * reply goes out before the channel closes, and `serve()` returns 0 rather
     * than calling exit, so the command and the binary decide the exit the same
     * way they do for every other command.
     */
    private function shutdown(Request $request): bool
    {
        $this->executor->expectShutdown();

        $this->channel->send(Event::shutdownReply($request->id, $request->bool('restart', false)));

        return true;
    }

    private function unknown(Request $request): bool
    {
        $this->channel->send(Event::error(
            $request->id,
            Event::PROTOCOL_ERROR,
            sprintf(
                'The engine has no request type "%s". It serves %s.',
                $request->type,
                implode(', ', [
                    Request::EXECUTE,
                    Request::COMPLETE,
                    Request::IS_COMPLETE,
                    Request::KERNEL_INFO,
                    Request::SHUTDOWN,
                ]),
            ),
        ));

        return false;
    }

    /**
     * Send one of the executor's events, tagged with the request it answers.
     *
     * This is where `silent` is applied, because it is the engine's rule and
     * not the executor's.
     *
     * @param  array<string, mixed>  $event
     */
    private function emit(array $event): void
    {
        if ($this->silent && in_array($event['type'] ?? '', ['display', 'result'], true)) {
            return;
        }

        $this->channel->send(Event::fromExecutor($event, $this->currentId));
    }

    /**
     * What to indent a continuation line by.
     *
     * The last line's own indentation, plus four more when it left a brace or a
     * bracket open, which is what a front end wants in order to keep typing
     * inside the block.
     */
    private static function indentFor(string $code): string
    {
        $lines = preg_split('/\r\n|\n|\r/', $code) ?: [];

        for ($i = count($lines) - 1; $i >= 0; $i--) {
            if (trim($lines[$i]) === '') {
                continue;
            }

            preg_match('/^[ \t]*/', $lines[$i], $match);
            $indent = $match[0] ?? '';

            return str_ends_with(rtrim($lines[$i]), '{') || str_ends_with(rtrim($lines[$i]), '(')
                ? $indent.'    '
                : $indent;
        }

        return '';
    }
}
