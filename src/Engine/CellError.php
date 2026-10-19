<?php

declare(strict_types=1);

namespace Juphyter\Engine;

/**
 * A cell that the cleaner refuses, before anything of it executes.
 *
 * The first three fields map onto the `error` event's `ename`, `evalue` and the
 * line the front end highlights. {@see $line} is a line in the user's own cell,
 * counted from 1, never a line of the prelude: the cleaner parses the cell on
 * its own, so the parser's line numbers are the user's to begin with.
 *
 * {@see $incomplete} is the fourth, and it is for `is_complete_reply` rather
 * than for the error event. Jupyter asks a kernel to sort source into complete,
 * incomplete and invalid, and the cleaner is the only thing that knows which it
 * is: source that parses is complete, source that ran out of input is
 * incomplete, and anything else is invalid. Reading it from here is what keeps
 * the transport from having to match on a parser message of its own, which
 * would say the same thing twice and drift the first time php-parser rewords
 * one.
 */
final class CellError
{
    /** Source that does not parse. */
    public const PARSE_ERROR = 'ParseError';

    /** Syntax PHP accepts but a cell cannot have, such as `namespace`. */
    public const UNSUPPORTED = 'UnsupportedSyntax';

    /** A name the session already holds, which PHP cannot redeclare. */
    public const REDECLARATION = 'RedeclarationError';

    /**
     * @param  bool  $incomplete  True only when the cell simply stops, which is
     *                            Jupyter's "incomplete" rather than its
     *                            "invalid". Never true for anything but a parse
     *                            error: refusing a namespace or a redeclaration
     *                            is a verdict on complete source.
     */
    private function __construct(
        public readonly string $name,
        public readonly string $message,
        public readonly ?int $line = null,
        public readonly bool $incomplete = false,
    ) {}

    /** The parser's own message and line, passed through unchanged. */
    public static function parseError(string $message, ?int $line = null, bool $incomplete = false): self
    {
        return new self(self::PARSE_ERROR, $message, $line, $incomplete);
    }

    public static function unsupported(string $message, ?int $line = null): self
    {
        return new self(self::UNSUPPORTED, $message, $line);
    }

    public static function redeclaration(string $message, ?int $line = null): self
    {
        return new self(self::REDECLARATION, $message, $line);
    }

    /** `Name: message on line N`, for a log or a test assertion. */
    public function describe(): string
    {
        return $this->name.': '.$this->message.($this->line === null ? '' : ' on line '.$this->line);
    }
}
