<?php

declare(strict_types=1);

namespace Juphyter\Engine;

use Error;

/**
 * Stopping a cell that is taking too long.
 *
 * The kernelspec asks for `"interrupt_mode": "signal"`, so the front end's stop
 * button reaches the transport as a signal and the transport forwards SIGINT to
 * this process. Under `pcntl_async_signals(true)` PHP runs the handler at the
 * next instruction boundary, and the handler throws this, which unwinds out of
 * `eval()` like any other exception. The executor reports it as an interrupt
 * rather than a crash, and because it unwinds rather than aborting, the
 * variables the cell had already bound are still collected and the session
 * stays usable.
 *
 * The handler is installed only around the `eval()` itself and released
 * afterwards, so a signal arriving between cells is ignored rather than
 * throwing with nothing to unwind to.
 *
 * ## Why this extends Error
 *
 * It extends `Error` rather than `Exception` so that a cell's own
 * `catch (Exception)` cannot swallow the stop button. A loop written as
 * `while (true) { try { work(); } catch (Exception $e) {} }` is ordinary
 * defensive code, and under `Exception` it ate the interrupt and ran until the
 * process was killed. `Error` is the branch of the hierarchy a `catch
 * (Exception)` does not reach, which is as close as PHP comes to Python's
 * BaseException, where `KeyboardInterrupt` sits for the same reason. A cell
 * that catches `Throwable` can still swallow it, and that is accepted: at that
 * point the cell has asked for everything.
 *
 * Without the pcntl extension there is nothing to install. {@see listen()}
 * reports that by returning false, and the kernel runs uninterruptibly rather
 * than refusing to run at all.
 */
final class Interrupt extends Error
{
    /** The `ename` an interrupt is reported under, as Jupyter front ends know it. */
    public const ENAME = 'KeyboardInterrupt';

    /** Whether this build can be interrupted at all. */
    public static function available(): bool
    {
        return function_exists('pcntl_async_signals')
            && function_exists('pcntl_signal')
            && defined('SIGINT');
    }

    /**
     * Install the SIGINT handler for the duration of one cell.
     *
     * @return bool False when pcntl is missing, in which case there is nothing
     *              to release either.
     */
    public static function listen(): bool
    {
        if (! self::available()) {
            return false;
        }

        pcntl_async_signals(true);

        return pcntl_signal(SIGINT, static function (): void {
            throw new self('Execution interrupted.');
        });
    }

    /**
     * Stop handling SIGINT.
     *
     * The signal is ignored rather than defaulted, because the default would
     * end the kernel process and a stop button pressed a moment too late should
     * not do that.
     */
    public static function release(): void
    {
        if (self::available()) {
            pcntl_signal(SIGINT, SIG_IGN);
        }
    }
}
