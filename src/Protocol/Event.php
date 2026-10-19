<?php

declare(strict_types=1);

namespace Juphyter\Protocol;

/**
 * One message from the engine to the transport.
 *
 * ## The wire format
 *
 * One JSON object per line, UTF-8, newline-terminated. JSON escapes newlines,
 * so line framing is enough and the channel stays readable in a log. Every
 * event carries the `id` of the request it answers, so the transport can route
 * it, and a `type`. Nothing here computes a Jupyter message: the transport owns
 * that, along with `status`, `execute_input` and all signing.
 *
 * ## Every event the engine sends
 *
 * Replies to `execute`, in the order they can appear. Any number of `stream`
 * and `display`, then at most one `result`, then exactly one of `ok` or
 * `error`:
 *
 * ```json
 * {"id":"a1","type":"stream","name":"stdout","text":"hello\n"}
 * {"id":"a1","type":"display","bundle":{"text/plain":"..."},"metadata":{}}
 * {"id":"a1","type":"result","execution_count":3,"bundle":{"text/plain":"1"},"metadata":{}}
 * {"id":"a1","type":"error","ename":"TypeError","evalue":"...","traceback":["..."]}
 * {"id":"a1","type":"ok","execution_count":3}
 * ```
 *
 * `name` is `stdout` or `stderr`. A warning, notice or deprecation arrives on
 * `stderr` rather than as an `error`, because it did not end the cell.
 * `traceback` is a list of plain strings, the first `ename: evalue` and the
 * rest locations, already mapped to the cell and line the user wrote.
 *
 * The matching reply to each other request type, exactly one each:
 *
 * ```json
 * {"id":"a2","type":"complete_reply","matches":["filter"],"cursor_start":10,"cursor_end":12}
 * {"id":"a3","type":"is_complete_reply","status":"incomplete","indent":"    "}
 * {"id":"a4","type":"kernel_info_reply","language_info":{"...":"..."},"banner":"..."}
 * {"id":"a5","type":"shutdown_reply","restart":false}
 * ```
 *
 * `status` is `complete`, `incomplete` or `invalid`. `indent` is present on
 * every `is_complete_reply` and is the empty string unless the status is
 * `incomplete`.
 *
 * One event has no request to answer, and its `id` is therefore null:
 *
 * ```json
 * {"id":null,"type":"error","ename":"ProtocolError","evalue":"...","traceback":["..."]}
 * ```
 *
 * The transport should treat a null `id` as a complaint about the channel
 * rather than an answer to anything, and may log it. A line whose JSON would
 * not parse has no id to echo, which is the only way this arises.
 */
final class Event
{
    public const PROTOCOL_ERROR = 'ProtocolError';

    /**
     * @param  string|null  $id  The request this answers, or null for a
     *                           complaint about the channel itself.
     * @param  array<string, mixed>  $fields  Everything but `id` and `type`.
     */
    private function __construct(
        public readonly ?string $id,
        public readonly string $type,
        public readonly array $fields = [],
    ) {}

    /**
     * Wrap an event the executor emitted, which already has its `type` and
     * fields, with the id of the request being served.
     *
     * @param  array<string, mixed>  $event
     */
    public static function fromExecutor(array $event, ?string $id): self
    {
        $type = $event['type'];
        unset($event['type']);

        return new self($id, (string) $type, $event);
    }

    /** @param  list<string>  $matches */
    public static function completeReply(?string $id, array $matches, int $cursorStart, int $cursorEnd): self
    {
        return new self($id, 'complete_reply', [
            'matches' => $matches,
            'cursor_start' => $cursorStart,
            'cursor_end' => $cursorEnd,
        ]);
    }

    public static function isCompleteReply(?string $id, string $status, string $indent = ''): self
    {
        return new self($id, 'is_complete_reply', ['status' => $status, 'indent' => $indent]);
    }

    /** @param  array<string, string>  $languageInfo */
    public static function kernelInfoReply(?string $id, array $languageInfo, string $banner): self
    {
        return new self($id, 'kernel_info_reply', [
            'language_info' => $languageInfo,
            'banner' => $banner,
        ]);
    }

    public static function shutdownReply(?string $id, bool $restart): self
    {
        return new self($id, 'shutdown_reply', ['restart' => $restart]);
    }

    /** @param  list<string>  $traceback */
    public static function error(?string $id, string $ename, string $evalue, array $traceback = []): self
    {
        return new self($id, 'error', [
            'ename' => $ename,
            'evalue' => $evalue,
            'traceback' => $traceback === [] ? [$ename.': '.$evalue] : $traceback,
        ]);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['id' => $this->id, 'type' => $this->type, ...$this->fields];
    }

    /**
     * The line to write, newline included.
     *
     * Slashes and unicode are left unescaped, which keeps HTML bundles and
     * non-ASCII output readable on the wire, and invalid UTF-8 is substituted
     * rather than failing the encode: a cell that printed raw bytes should not
     * be able to break the channel.
     */
    public function encode(): string
    {
        $json = json_encode(
            $this->toArray(),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR,
        );

        if ($json === false) {
            $json = json_encode([
                'id' => $this->id,
                'type' => 'error',
                'ename' => self::PROTOCOL_ERROR,
                'evalue' => 'The engine could not encode this event: '.json_last_error_msg(),
                'traceback' => [self::PROTOCOL_ERROR.': '.json_last_error_msg()],
            ]);
        }

        return $json."\n";
    }
}
