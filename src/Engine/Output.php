<?php

declare(strict_types=1);

namespace Juphyter\Engine;

use Closure;

/**
 * Everything a cell writes, on its way to the notebook.
 *
 * Two routes, both ending as a `stream` event:
 *
 * - **What the cell prints.** `ob_start()` with a chunk size of one calls the
 *   callback after every output statement, so a cell that prints and then works
 *   for a minute shows its output straight away instead of at the end. Holding
 *   it to the end of the cell would be the easier implementation and the wrong
 *   behaviour.
 * - **What PHP complains about.** A `set_error_handler` turns a warning, notice
 *   or deprecation into a `stderr` event, so it appears in the notebook without
 *   being mistaken for the cell's value.
 *
 * Both are installed for the duration of one cell and taken down afterwards,
 * along with two settings they depend on: `error_reporting` goes to `E_ALL`, so
 * that the host's own level cannot decide what a notebook reports, and
 * `display_errors` goes off, so that nothing PHP prints itself reaches the
 * engine's real descriptors. Both are put back as they were.
 *
 * The buffer level is recorded on the way in and unwound to on the way out, so
 * a cell that leaves its own `ob_start()` open does not leak into the next one
 * and a cell that closes too many buffers cannot take this one with it.
 *
 * The emit callback must not print anything itself. Printing from inside an
 * output-buffer callback is how one builds an infinite loop.
 */
final class Output
{
    /** The error levels reported as diagnostics rather than thrown. */
    private const NAMES = [
        E_WARNING => 'Warning',
        E_NOTICE => 'Notice',
        E_DEPRECATED => 'Deprecated',
        E_USER_WARNING => 'Warning',
        E_USER_NOTICE => 'Notice',
        E_USER_DEPRECATED => 'Deprecated',
        E_RECOVERABLE_ERROR => 'Recoverable error',
    ];

    private ?int $level = null;

    private bool $handling = false;

    private ?int $reporting = null;

    private ?string $display = null;

    /**
     * @param  Closure(string $name, string $text): void  $emit  Takes "stdout"
     *                                                           or "stderr" and
     *                                                           the text.
     * @param  Closure(string $file, int $line): ?string|null  $locate  Describes
     *        where a diagnostic came from, as "Cell 3, line 2", or null when the
     *        location is not in a cell.
     */
    public function __construct(
        private readonly Closure $emit,
        private readonly ?Closure $locate = null,
    ) {}

    /** Begin capturing, for one cell. */
    public function start(): void
    {
        // Whatever the host had: a test runner or an application embedding the
        // engine may report far less than E_ALL, and a notebook's diagnostics
        // should not depend on that.
        $this->reporting = error_reporting(E_ALL);
        $this->display = (string) ini_get('display_errors');
        ini_set('display_errors', '0');

        $this->level = ob_get_level();

        ob_start(function (string $buffer): string {
            if ($buffer !== '') {
                ($this->emit)('stdout', $buffer);
            }

            // Nothing reaches the real stdout: the engine's own descriptors stay
            // free for the transport to use.
            return '';
        }, 1);

        set_error_handler($this->handler());
        $this->handling = true;
    }

    /** Stop capturing, flushing whatever is left through the callback. */
    public function stop(): void
    {
        if ($this->handling) {
            restore_error_handler();
            $this->handling = false;
        }

        if ($this->level !== null) {
            while (ob_get_level() > $this->level) {
                ob_end_flush();
            }

            $this->level = null;
        }

        if ($this->reporting !== null) {
            error_reporting($this->reporting);
            $this->reporting = null;
        }

        if ($this->display !== null) {
            ini_set('display_errors', $this->display);
            $this->display = null;
        }
    }

    /**
     * The error handler.
     *
     * Returning true keeps PHP's own handling out of it entirely. A diagnostic
     * the user suppressed with `@` is dropped rather than reported, which is
     * what suppressing it asked for: PHP signals that by narrowing
     * `error_reporting()` for the duration of the call.
     */
    private function handler(): Closure
    {
        return function (int $severity, string $message, string $file = '', int $line = 0): bool {
            if ((error_reporting() & $severity) === 0) {
                return true;
            }

            ($this->emit)('stderr', $this->describe($severity, $message, $file, $line)."\n");

            return true;
        };
    }

    /** `Warning: message in Cell 3, line 2`, with the location when there is one. */
    private function describe(int $severity, string $message, string $file, int $line): string
    {
        $label = self::NAMES[$severity] ?? 'Error';
        $where = null;

        if ($file !== '' && $this->locate !== null) {
            $where = ($this->locate)($file, $line);
        }

        if ($where === null && $file !== '') {
            $where = $line > 0 ? $file.', line '.$line : $file;
        }

        return $where === null
            ? $label.': '.$message
            : $label.': '.$message.' in '.$where;
    }
}
