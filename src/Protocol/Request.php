<?php

declare(strict_types=1);

namespace Juphyter\Protocol;

use JsonException;
use RuntimeException;

/**
 * One message from the transport to the engine.
 *
 * ## The wire format
 *
 * One JSON object per line, UTF-8, newline-terminated, the same framing the
 * engine replies with. Every request carries a string `id`, which every event
 * answering it echoes, and a `type`.
 *
 * ## Every request the engine serves
 *
 * ```json
 * {"id":"a1","type":"execute","code":"$x = 1;","silent":false,"store_history":true}
 * {"id":"a2","type":"complete","code":"$frame->fil","cursor_pos":11}
 * {"id":"a3","type":"is_complete","code":"if (true) {"}
 * {"id":"a4","type":"kernel_info"}
 * {"id":"a5","type":"shutdown","restart":false}
 * ```
 *
 * Defaults, so the transport may leave a field out: `code` is the empty string,
 * `silent` is false, `store_history` is true, `cursor_pos` is the length of
 * `code`, and `restart` is false.
 *
 * `silent` suppresses `display` and `result` but never `error`, and
 * `store_history` false means the execution count does not advance. Both are
 * the engine's to apply; the executor knows about neither.
 *
 * ## What counts as malformed
 *
 * A line that is not a JSON object, an object with no string `id`, or an
 * object with no string `type`. Each is answered with an `error` event and the
 * connection carries on, because a transport that sent one bad line will very
 * likely send a good one next. An unknown `type` is not malformed in the same
 * way — the `id` is there to answer with — and is reported against that id.
 */
final class Request
{
    public const EXECUTE = 'execute';

    public const COMPLETE = 'complete';

    public const IS_COMPLETE = 'is_complete';

    public const KERNEL_INFO = 'kernel_info';

    public const SHUTDOWN = 'shutdown';

    /** @param  array<string, mixed>  $payload */
    public function __construct(
        public readonly string $id,
        public readonly string $type,
        public readonly array $payload = [],
    ) {}

    /**
     * Read one line from the channel.
     *
     * @throws RuntimeException When the line is not a request. The message is
     *                          fit to put in an `error` event's evalue.
     */
    public static function parse(string $line): self
    {
        try {
            $decoded = json_decode($line, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new RuntimeException('The engine could not read a line as JSON: '.$e->getMessage());
        }

        // An empty object decodes to an empty array, which array_is_list()
        // calls a list. It is allowed through here so that `{}` is turned away
        // for the reason that actually applies: it carries no id.
        if (! is_array($decoded) || ($decoded !== [] && array_is_list($decoded))) {
            throw new RuntimeException('A request has to be a JSON object.');
        }

        $id = $decoded['id'] ?? null;

        if (! is_string($id) || $id === '') {
            throw new RuntimeException('A request has to carry a non-empty string "id".');
        }

        $type = $decoded['type'] ?? null;

        if (! is_string($type) || $type === '') {
            throw new RuntimeException(sprintf('Request "%s" has no string "type".', $id));
        }

        unset($decoded['id'], $decoded['type']);

        return new self($id, $type, $decoded);
    }

    public function string(string $key, string $default = ''): string
    {
        $value = $this->payload[$key] ?? null;

        return is_string($value) ? $value : $default;
    }

    public function bool(string $key, bool $default): bool
    {
        $value = $this->payload[$key] ?? null;

        return is_bool($value) ? $value : $default;
    }

    public function int(string $key, int $default): int
    {
        $value = $this->payload[$key] ?? null;

        return is_int($value) ? $value : $default;
    }

    /** The code a request carries, for the three types that carry any. */
    public function code(): string
    {
        return $this->string('code');
    }

    /**
     * Where the cursor is in {@see code()}, clamped to it.
     *
     * A front end counts in characters and the engine in bytes, which agree for
     * ASCII and part ways on anything else; the completer takes this as a byte
     * offset, and clamping keeps a cursor past the end from reaching into
     * nothing.
     */
    public function cursor(): int
    {
        $code = $this->code();

        return max(0, min($this->int('cursor_pos', strlen($code)), strlen($code)));
    }
}
