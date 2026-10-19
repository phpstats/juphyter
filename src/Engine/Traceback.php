<?php

declare(strict_types=1);

namespace Juphyter\Engine;

use Throwable;

/**
 * Turns a PHP traceback into one a notebook reader can act on.
 *
 * A frame from `eval()`'d code reports its file as `/path/Executor.php(123) :
 * eval()'d code` and its line counted from the start of the evaluated string,
 * which includes the prelude. Neither is any use to someone looking at a
 * notebook. This rewrites such a frame to the cell and the line the user
 * actually wrote, and leaves every other frame alone, so a throw from inside a
 * vendor package still reads as that package's file and line.
 *
 * ## Which cell a frame belongs to
 *
 * The file string cannot say. It is built from the file and line of the `eval()`
 * call, and every cell goes through the same one, so all cells produce the same
 * string. What distinguishes them is the function the frame is *inside*:
 *
 * - inside nothing, so top-level cell code, means the cell now running;
 * - inside a function or a method whose name a previous cell declared means
 *   that cell, which is what {@see remember()} records.
 *
 * PHP's trace is shaped for this. Entry `i` holds the file and line the call
 * was made *from*, and entry `i + 1` names the function containing that call
 * site, so walking the two together gives each location its container. The
 * throw site itself is `$e->getFile()`/`getLine()`, contained by entry `0`'s
 * function.
 *
 * ## The frames it cannot place
 *
 * A closure cannot be placed. Its frame says `{closure}` on PHP 8.3 and
 * `{closure:<file>(<n>) : eval()'d code:<line>}` from 8.4, and because every
 * cell's `eval()` shares one file string, neither form says which cell declared
 * it. A function a cell declared conditionally is in the same position, since
 * the cleaner only records top-level declarations.
 *
 * Such a frame gets no cell and no line. It reads `in a closure`, and that is
 * the whole point: the alternative was to fall back to the running cell, which
 * printed a confident `Cell 4, line 2` for a closure declared in cell 3 — a
 * location that is simply wrong, and worse than none. The raw closure name is
 * not shown either, because it carries juphyter's own absolute path, the
 * `eval()'d code` marker and the `Juphyter\Engine\Executor` scope the closure
 * happens to be bound to, none of which means anything to a notebook reader.
 *
 * The gap is deliberate. The plan's recorded fallback — writing each cell to a
 * file and requiring it, giving every cell a file of its own — would close it,
 * at the cost of a meaningless `__FILE__`, and is not taken.
 *
 * ## Which frames are dropped
 *
 * Frames below the executor's own `eval()` go, being the same three every time.
 * So does any frame from a real file inside `src/Engine`, because those are the
 * kernel's own machinery rather than the user's code: without that, an
 * interrupt opened with juphyter's absolute path to the signal handler that
 * threw it. A frame from a cell is never dropped, even though its file name is
 * built from a path inside `src/Engine`, which is what the evaluated-code check
 * is there to tell apart.
 */
final class Traceback
{
    /** The marker PHP puts in the file name of evaluated code. */
    private const EVALUATED = "eval()'d code";

    /**
     * Which cell declared which name, and what that cell's prelude cost.
     *
     * Keyed by the lowercased function or class name, because PHP matches both
     * case-insensitively.
     *
     * @var array<string, array{label: int, preludeLines: int}>
     */
    private array $origins = [];

    /**
     * Record the names a cell declared, so a later throw inside one of them
     * maps back to this cell.
     *
     * Called for every cell the executor runs, before it runs.
     */
    public function remember(CleanedCell $cell, int $label): void
    {
        $origin = ['label' => $label, 'preludeLines' => $cell->preludeLines];

        foreach ([...$cell->declaresClassLike, ...$cell->declaresFunctions] as $name) {
            $this->origins[strtolower($name)] = $origin;
        }
    }

    /** Whether a file name is evaluated code rather than a real file. */
    public static function isEvaluated(string $file): bool
    {
        return str_contains($file, self::EVALUATED);
    }

    /**
     * Describe a location in the running cell's terms, or null when it is not
     * evaluated code at all.
     *
     * This is for a diagnostic or a fatal, which arrive with a file and a line
     * and no stack to read a container from, so the running cell is the only
     * answer available. A traceback frame goes through {@see resolve()}
     * instead, which can place a frame in an earlier cell and declines to place
     * one it cannot.
     */
    public function describe(string $file, int $line, CleanedCell $cell, int $label): ?string
    {
        if (! self::isEvaluated($file)) {
            return null;
        }

        return sprintf('Cell %d, line %d', $label, $line - $cell->preludeLines);
    }

    /**
     * The traceback for a thrown value, innermost location first.
     *
     * The first line is `ename: evalue`, as a notebook front end expects, and
     * each location follows indented.
     *
     * @return list<string>
     */
    public function format(Throwable $thrown, CleanedCell $cell, int $label): array
    {
        $lines = [self::header($thrown)];
        $previous = null;

        foreach ($this->locations($thrown) as $location) {
            [$where, $in] = $this->resolve($location, $cell, $label);

            // A frame no cell claims: say what it is in and nothing about
            // where, rather than naming a cell and a line that are wrong.
            if ($where === null) {
                if ($in !== null) {
                    $lines[] = '  in '.$in;
                }

                $previous = null;

                continue;
            }

            // An internal function reports its caller's file and line as the
            // throw site, so the same place arrives twice: once naming the
            // function and once as the bare call site. The bare one adds
            // nothing. Only a frame with no function of its own is ever
            // dropped, so a recursive call at one line still shows every level.
            if ($in === null && $where === $previous) {
                continue;
            }

            $lines[] = '  at '.$where.($in === null ? '' : ', in '.$in);
            $previous = $where;
        }

        return $lines;
    }

    /** A traceback for a cell that was refused before it ran. */
    public function forRefusal(CellError $error, int $label): array
    {
        $lines = [$error->name.': '.$error->message];

        if ($error->line !== null) {
            $lines[] = sprintf('  at Cell %d, line %d', $label, $error->line);
        }

        return $lines;
    }

    /** `ename: evalue`, with the class name as the ename. */
    private static function header(Throwable $thrown): string
    {
        $ename = $thrown instanceof Interrupt ? Interrupt::ENAME : $thrown::class;

        return $ename.': '.$thrown->getMessage();
    }

    /**
     * Every location the throw passed through, paired with its container.
     *
     * @return list<array{file: ?string, line: ?int, container: ?string, in: ?string, topLevel: bool}>
     */
    private function locations(Throwable $thrown): array
    {
        $trace = $thrown->getTrace();

        $locations = [[
            'file' => $thrown->getFile(),
            'line' => $thrown->getLine(),
            'container' => self::container($trace[0] ?? null),
            'in' => self::signature($trace[0] ?? null),
            'topLevel' => self::isTopLevel($trace[0] ?? null),
        ]];

        foreach ($trace as $index => $frame) {
            $file = $frame['file'] ?? null;

            // The executor's own frames: the eval() pseudo-frame and everything
            // that called it. The user's stack ends here.
            if ($file !== null && $file === self::executorFile()) {
                break;
            }

            $locations[] = [
                'file' => $file,
                'line' => isset($frame['line']) ? (int) $frame['line'] : null,
                'container' => self::container($trace[$index + 1] ?? null),
                'in' => self::signature($trace[$index + 1] ?? null),
                'topLevel' => self::isTopLevel($trace[$index + 1] ?? null),
            ];
        }

        return array_values(array_filter(
            $locations,
            static fn (array $location) => $location['file'] === null || ! self::isOurs($location['file']),
        ));
    }

    /**
     * Whether a location is the kernel's own machinery rather than the user's
     * code.
     *
     * The evaluated-code check comes first and is the whole subtlety: a cell's
     * frames report a file name built from the executor's path, so without it
     * every cell frame would look like one of ours.
     */
    private static function isOurs(string $file): bool
    {
        return ! self::isEvaluated($file) && str_starts_with($file, self::engineDirectory());
    }

    /** The directory holding the engine's own classes. */
    private static function engineDirectory(): string
    {
        static $directory = null;

        return $directory ??= dirname(self::executorFile()).DIRECTORY_SEPARATOR;
    }

    /**
     * One location as the pair a traceback line is built from: where it is, and
     * the function it is in.
     *
     * A null `where` means the frame could not be placed, and the caller says
     * only what it is in. See the class docblock on the frames it cannot place.
     *
     * @return array{0: ?string, 1: ?string}
     */
    private function resolve(array $location, CleanedCell $cell, int $label): array
    {
        $file = $location['file'];
        $line = $location['line'];
        $in = $location['in'];

        if ($file === null || $line === null) {
            return [null, $in];
        }

        if (! self::isEvaluated($file)) {
            return [sprintf('%s, line %d', $file, $line), $in];
        }

        $origin = match (true) {
            // Top-level code of the cell now running.
            $location['topLevel'] => ['label' => $label, 'preludeLines' => $cell->preludeLines],
            // A built-in has no source of its own, so PHP reports the caller's
            // position as the throw site: the location really is in the cell
            // that called it.
            self::isInternal($location['container']) => ['label' => $label, 'preludeLines' => $cell->preludeLines],
            // Inside something a cell declared, which may be an earlier cell,
            // whose own prelude is what the line has to be measured against.
            $location['container'] !== null => $this->origins[strtolower($location['container'])] ?? null,
            default => null,
        };

        if ($origin === null) {
            return [null, $in];
        }

        return [sprintf('Cell %d, line %d', $origin['label'], $line - $origin['preludeLines']), $in];
    }

    /**
     * The name to look up in {@see $origins} for a frame, or null when nothing
     * about it names a cell.
     */
    private static function container(?array $frame): ?string
    {
        if ($frame === null) {
            return null;
        }

        $function = $frame['function'] ?? null;

        // `eval` is PHP's pseudo-frame, and a closure has no name any cell
        // declared. The closure check comes before the class, because a closure
        // written in a cell is bound to the Executor's scope and would
        // otherwise be looked up under that class name.
        if ($function === null || $function === 'eval' || self::isClosure($function)) {
            return null;
        }

        return $frame['class'] ?? $function;
    }

    /** Whether a frame is top-level code of the cell now running. */
    private static function isTopLevel(?array $frame): bool
    {
        return $frame === null || ($frame['function'] ?? null) === 'eval';
    }

    /**
     * How a frame's function reads in a traceback line, parentheses included.
     *
     * A closure is rendered as plain words. From PHP 8.4 its name embeds the
     * file and line it was declared on, which for a cell is juphyter's own
     * absolute path and the `eval()'d code` marker, and a closure written in a
     * cell is bound to the Executor's scope, so the raw name reads as
     * `Juphyter\Engine\Executor->{closure:/Users/.../Executor.php(265) :
     * eval()'d code:2}`. None of that belongs in a notebook.
     */
    private static function signature(?array $frame): ?string
    {
        $function = $frame === null ? null : ($frame['function'] ?? null);

        if ($function === null || $function === 'eval') {
            return null;
        }

        if (self::isClosure($function)) {
            return 'a closure';
        }

        $scope = isset($frame['class']) ? $frame['class'].($frame['type'] ?? '::') : '';

        return $scope.$function.'()';
    }

    /** Whether a frame's function name is one PHP made up for a closure. */
    private static function isClosure(string $function): bool
    {
        return str_contains($function, '{closure');
    }

    /**
     * Whether a container is built into PHP rather than written in a cell.
     *
     * It matters because a built-in has no source of its own, so the throw site
     * PHP reports is the position of the call. `intdiv(1, 0)` in a cell reports
     * the cell's line, and treating it as unplaceable would throw away a
     * location that is both known and right.
     */
    private static function isInternal(?string $name): bool
    {
        if ($name === null) {
            return false;
        }

        static $known = [];

        if (isset($known[$name])) {
            return $known[$name];
        }

        try {
            if (function_exists($name)) {
                return $known[$name] = (new \ReflectionFunction($name))->isInternal();
            }

            if (class_exists($name, false) || interface_exists($name, false)) {
                return $known[$name] = (new \ReflectionClass($name))->isInternal();
            }
        } catch (\ReflectionException) {
            // Nothing reflectable is nothing built in.
        }

        return $known[$name] = false;
    }

    /** The executor's file, which is where the user's stack is cut off. */
    private static function executorFile(): string
    {
        static $file = null;

        return $file ??= (new \ReflectionClass(Executor::class))->getFileName() ?: '';
    }
}
