<?php

declare(strict_types=1);

namespace Juphyter\Engine;

/**
 * The lines the cleaner puts ahead of every cell.
 *
 * `declare(strict_types=1);` first, because PHP accepts it nowhere else, then
 * one `use` statement per line for every import the session has accumulated.
 * One statement per line is what makes {@see lineCount()} meaningful: it is the
 * offset between a line of the evaluated source and the line the user wrote,
 * which is how the executor turns a traceback frame back into a cell line.
 *
 * `declare(strict_types=1)` inside `eval()` was measured to be honoured on PHP
 * 8.3, 8.4 and 8.5: a weak argument raises a TypeError. PHP also requires it to
 * be the very first statement, and anything else first is an uncatchable fatal
 * error rather than a reportable one, which is why the cleaner strips a
 * `declare(strict_types=...)` a cell writes for itself.
 */
final class Prelude
{
    /** @param  list<string>  $lines */
    private function __construct(public readonly array $lines) {}

    /** The prelude for a session's current import set. */
    public static function for(Session $session): self
    {
        return new self(['declare(strict_types=1);', ...$session->importStatements()]);
    }

    /** The prelude as source, without a trailing newline. */
    public function source(): string
    {
        return implode("\n", $this->lines);
    }

    /**
     * How many lines the prelude occupies, which is the line offset it adds.
     *
     * Cell line 1 is line `lineCount() + 1` of the evaluated source.
     */
    public function lineCount(): int
    {
        return count($this->lines);
    }
}
