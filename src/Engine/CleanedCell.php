<?php

declare(strict_types=1);

namespace Juphyter\Engine;

/**
 * A cell the cleaner accepted: what the executor runs, and what it needs to
 * know about it.
 *
 * This is the contract between the cleaner and the executor. The executor
 * passes {@see $source} to `eval()` unchanged — it carries no opening tag and
 * needs no further rewriting — publishes {@see $resultVariable} when it is not
 * null, and uses {@see cellLine()} to turn a frame of a traceback back into the
 * line the user wrote.
 *
 * ## The executor's obligation
 *
 * After every `eval()` attempt, thrown or not, the executor must call
 * {@see Session::commitDeclarations()} with this object. The names in
 * {@see $declaresClassLike} and {@see $declaresFunctions} are what the cell
 * *tried* to declare, and only that call records the ones that now really
 * exist. Skipping it means the session forgets a declaration that did happen,
 * and the next cell declaring the same name hits the uncatchable fatal the
 * cleaner exists to prevent. See {@see Cleaner} for why it is the executor's
 * call to make and not the cleaner's.
 *
 * ## The line mapping
 *
 * `$source` is the prelude, then a newline, then the cell exactly as the user
 * wrote it. The cell's own bytes are never moved: a statement the cleaner
 * removes is overwritten with spaces rather than deleted, what the cleaner
 * inserts goes at the start of the last statement, and what it appends goes
 * after everything the user wrote, so no line of the cell shifts. Line `n` of
 * the cell is therefore always line `n + $preludeLines` of `$source`, for every
 * cell and every edit the cleaner makes.
 */
final class CleanedCell
{
    /**
     * @param  string  $source  Evaluable source: the prelude, then the cell.
     * @param  int  $preludeLines  Lines the prelude occupies, and so the offset
     *                             between a cell line and a source line.
     * @param  string|null  $resultVariable  The variable the cell's value was
     *                                       captured into, or null when the
     *                                       cell has no value to publish.
     * @param  list<string>  $declaresClassLike  Class, interface, trait and enum
     *                                           names the cell declares at the
     *                                           top level, in the spelling it
     *                                           used. Not yet in the session.
     * @param  list<string>  $declaresFunctions  Function names, likewise.
     * @param  bool  $semicolonAppended  Whether the cell parsed only once a
     *                                   newline and a closing semicolon were
     *                                   appended. Task 5's `is_complete_request`
     *                                   reads this for source that is complete
     *                                   but for a semicolon; source that is
     *                                   genuinely unfinished never gets here,
     *                                   arriving instead as a CellError with
     *                                   `$incomplete` set.
     */
    public function __construct(
        public readonly string $source,
        public readonly int $preludeLines,
        public readonly ?string $resultVariable = null,
        public readonly array $declaresClassLike = [],
        public readonly array $declaresFunctions = [],
        public readonly bool $semicolonAppended = false,
    ) {}

    /** The cell line that line $sourceLine of {@see $source} came from. */
    public function cellLine(int $sourceLine): int
    {
        return $sourceLine - $this->preludeLines;
    }

    /** The source line a given cell line was written to. */
    public function sourceLine(int $cellLine): int
    {
        return $cellLine + $this->preludeLines;
    }

    /** Whether the cell left a value for the executor to publish. */
    public function hasResult(): bool
    {
        return $this->resultVariable !== null;
    }

    /** Whether the cell declares any name the session has to be told about. */
    public function declaresAnything(): bool
    {
        return $this->declaresClassLike !== [] || $this->declaresFunctions !== [];
    }
}
