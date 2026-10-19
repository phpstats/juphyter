<?php

declare(strict_types=1);

namespace Juphyter\Engine;

use Closure;
use Throwable;

/**
 * Runs cells, and is the only thing that does.
 *
 * It takes cell source, has {@see Cleaner} turn it into something `eval()` can
 * run, runs it, and reports what happened through an event sink. Nothing else
 * in juphyter calls `eval()`, which is deliberate: several obligations below
 * have to hold for *every* cell, and the way to guarantee that is to have one
 * door.
 *
 * ## The event sink
 *
 * The sink is a `Closure(array $event): void` given to the constructor. Each
 * event is an array with a `type` and the fields the engine-to-transport
 * contract gives that type. The transport adds the request `id` and turns these
 * into Jupyter messages; the executor never computes a Jupyter message and
 * never serialises anything.
 *
 * ```
 * ['type' => 'stream',  'name' => 'stdout'|'stderr', 'text' => string]
 * ['type' => 'display', 'bundle' => array<string, mixed>, 'metadata' => array<string, mixed>]
 * ['type' => 'result',  'execution_count' => int, 'bundle' => array<string, mixed>, 'metadata' => array<string, mixed>]
 * ['type' => 'error',   'ename' => string, 'evalue' => string, 'traceback' => list<string>]
 * ['type' => 'ok',      'execution_count' => int]
 * ```
 *
 * Every call to {@see execute()} ends with exactly one terminal event, `ok` or
 * `error`, and any number of `stream` and `display` events before it. A cell
 * with a value emits `result` immediately before `ok`; a cell whose value is
 * null emits none, since a void call is written for its effect and an `Out` of
 * "null" after it would be noise. Every other value publishes, `0`, `false`,
 * `''` and `[]` among them. `silent` and
 * `store_history` are the transport's to apply, so a sink that drops `display`
 * and `result` is how `silent` is served.
 *
 * The sink must not print anything. It is called from inside an output-buffer
 * callback, where printing is how one builds an infinite loop.
 *
 * ## Values
 *
 * A cell's value is turned into a MIME bundle by a `Closure(mixed $value):
 * array{bundle: array<string, mixed>, metadata: array<string, mixed>}` given to
 * the constructor. That closure is the display registry, which belongs to
 * another part of the kernel; nothing here knows about it. Without one, a
 * deliberately plain `text/plain` fallback is used so the executor runs on its
 * own.
 *
 * ## What persists between cells, and what does not
 *
 * The cell runs inside {@see evaluate()}, which `extract()`s the session's
 * variables on the way in and collects `get_defined_vars()` on the way out.
 * Scalars, arrays, objects and resources therefore persist. References and
 * `$this` do not: `extract()` binds by value, so a cell that took a reference
 * to another variable has an ordinary copy in the next cell. Every PHP REPL
 * behaves this way, and the documentation should say so.
 *
 * The collection *replaces* the session's variables rather than merging into
 * them, so a cell that `unset()`s a variable really removes it.
 *
 * Collection happens in a `finally`, so a cell that bound two variables and
 * then threw keeps both. That is what makes an interrupted or failed cell
 * leave a usable session rather than a lost one.
 *
 * Variables whose name begins with {@see RESERVED_PREFIX} are the executor's
 * own and never reach the session.
 */
final class Executor
{
    /**
     * Variables under this prefix belong to the executor.
     *
     * {@see evaluate()} names its own parameters this way so that `extract()`
     * of a user variable cannot shadow them, and strips the prefix from what it
     * collects so they cannot leak into the session either.
     */
    public const RESERVED_PREFIX = '__juphyter_';

    /**
     * The `ename` for a cell that ended the kernel process itself.
     *
     * Named as Python's is, because it is the same event from the front end's
     * point of view: `sys.exit()` in IPython reports `SystemExit`, and a
     * notebook that knows the name may style it as an expected ending rather
     * than a crash.
     */
    public const ENDED = 'SystemExit';

    private readonly Cleaner $cleaner;

    private readonly Session $session;

    private readonly Traceback $traceback;

    private readonly Output $output;

    /** @var array<string, mixed> */
    private array $variables = [];

    /** The cell being run, for the shutdown handler to report against. */
    private ?CleanedCell $running = null;

    private int $label = 0;

    private bool $watching = false;

    private bool $expectingShutdown = false;

    /**
     * @param  Closure(array $event): void  $sink  See the class docblock.
     * @param  Closure(mixed $value): array{bundle: array<string, mixed>, metadata: array<string, mixed>}|null  $formatter
     */
    public function __construct(
        private readonly Closure $sink,
        ?Session $session = null,
        ?Cleaner $cleaner = null,
        private readonly ?Closure $formatter = null,
    ) {
        $this->session = $session ?? new Session();
        $this->cleaner = $cleaner ?? new Cleaner();
        $this->traceback = new Traceback();
        $this->output = new Output(
            fn (string $name, string $text) => $this->emit([
                'type' => 'stream',
                'name' => $name,
                'text' => $text,
            ]),
            fn (string $file, int $line): ?string => $this->running === null
                ? null
                : $this->traceback->describe($file, $line, $this->running, $this->label),
        );
    }

    public function session(): Session
    {
        return $this->session;
    }

    /** @return array<string, mixed> The session's variables, for completion and for tests. */
    public function variables(): array
    {
        return $this->variables;
    }

    /**
     * Expect the process to end, because something asked it to.
     *
     * The engine calls this when it is about to end the process on a `shutdown`
     * request, before it exits. Without it, a shutdown that arrives while a
     * cell is still running looks exactly like a cell that called `exit`, and
     * {@see ENDED} would report a SystemExit for a shutdown the transport asked
     * for. After this call that report stays silent for the rest of the
     * process.
     *
     * A cell in flight gets no terminal event, because the process ends part
     * way through it. That is correct rather than unfortunate: the transport is
     * the one that asked, so it knows the execute request will not be answered,
     * and what it owes the front end is a reply to the shutdown request
     * instead.
     *
     * A fatal error is still reported, marked or not. Running out of memory
     * during a shutdown is still worth saying out loud.
     */
    public function expectShutdown(): void
    {
        $this->expectingShutdown = true;
    }

    /**
     * Run one cell.
     *
     * @param  int  $executionCount  The number the front end shows in `In [N]`,
     *                               and the number a traceback frame from this
     *                               cell is labelled with. The transport owns
     *                               it, including whether `store_history` means
     *                               it advanced; nothing here derives or keeps a
     *                               number of its own.
     */
    public function execute(string $code, int $executionCount): void
    {
        $this->watch();
        $this->label = $executionCount;

        $cleaned = $this->cleaner->clean($code, $this->session);

        if ($cleaned instanceof CellError) {
            $this->emit([
                'type' => 'error',
                'ename' => $cleaned->name,
                'evalue' => $cleaned->message,
                'traceback' => $this->traceback->forRefusal($cleaned, $executionCount),
            ]);

            return;
        }

        $this->traceback->remember($cleaned, $executionCount);
        $this->running = $cleaned;

        $interrupting = Interrupt::listen();
        $thrown = null;
        $collected = [];

        $this->output->start();

        try {
            $this->evaluate($cleaned->source, $this->variables, $collected);
        } catch (Throwable $e) {
            $thrown = $e;
        } finally {
            // Released before anything else, so the handler is not live for the
            // flush below. A second SIGINT arriving there would throw from
            // inside this block and abandon the rest of it: the declarations
            // would not be committed and the cell's variables would not be
            // collected. The window is now zero by construction rather than
            // small by timing.
            if ($interrupting) {
                Interrupt::release();
            }

            $this->output->stop();

            // Committed whether the cell threw or not, and from a finally so
            // that no path can skip it. PHP binds an unconditional top-level
            // declaration at compile time, so `throw new Exception(); class Foo
            // {}` really does declare Foo; recording only on success would
            // forget it and let the next cell declaring Foo reach the
            // uncatchable redeclaration fatal the cleaner exists to prevent.
            // Equally, a cell whose `class A extends Typo {}` threw declared
            // nothing, and commitDeclarations() checks the runtime rather than
            // trusting the source, so A stays free for the corrected cell.
            $this->session->commitDeclarations($cleaned);

            $this->running = null;
        }

        $this->variables = $this->withoutReserved($collected);

        if ($thrown !== null) {
            $this->emit([
                'type' => 'error',
                'ename' => $thrown instanceof Interrupt ? Interrupt::ENAME : $thrown::class,
                'evalue' => $thrown->getMessage(),
                'traceback' => $this->traceback->format($thrown, $cleaned, $executionCount),
            ]);

            return;
        }

        if ($cleaned->hasResult() && array_key_exists($cleaned->resultVariable, $collected)) {
            $value = $collected[$cleaned->resultVariable];

            // A cell whose value is null publishes nothing, as a notebook
            // expects: `var_dump($x);`, `Juphyter\display($frame);` and any
            // other void call is written for its effect, and an `Out` of
            // "null" after it is noise. Python's notebooks never show None
            // this way either.
            //
            // The test has to be here, because this is the last place that
            // holds the value itself. By the time the event reaches the
            // transport it is a MIME bundle, where a null and the string
            // "null" differ only by the display layer's choice of quoting.
            if ($value !== null) {
                $formatted = $this->format($value);

                $this->emit([
                    'type' => 'result',
                    'execution_count' => $executionCount,
                    'bundle' => $formatted['bundle'],
                    'metadata' => $formatted['metadata'],
                ]);
            }
        }

        $this->emit(['type' => 'ok', 'execution_count' => $executionCount]);
    }

    /**
     * Publish a value mid-cell, which is what an exported `display()` is for.
     *
     * A cell can show several frames or plots this way rather than only its
     * last value.
     *
     * @param  array<string, mixed>  $metadata
     */
    public function publish(mixed $value, array $metadata = []): void
    {
        $formatted = $this->format($value);

        $this->publishBundle(
            $formatted['bundle'],
            $metadata === [] ? $formatted['metadata'] : $metadata,
        );
    }

    /**
     * Publish a bundle that has already been formatted.
     *
     * This is the entry point for a display registry that formats on its own
     * behalf: the exported `display()` function turns each value into a bundle
     * and hands it to the publisher the engine installed, so passing it back
     * through {@see format()} would do the work twice. Routing both paths
     * through here keeps every `display` event built in one place, so the
     * event's shape has a single author.
     *
     * @param  array<string, mixed>  $bundle  MIME type => representation.
     * @param  array<string, mixed>  $metadata
     */
    public function publishBundle(array $bundle, array $metadata = []): void
    {
        $this->emit([
            'type' => 'display',
            'bundle' => $bundle,
            'metadata' => $metadata,
        ]);
    }

    /**
     * Run the cleaned source with the session's variables in scope, and collect
     * what it leaves behind.
     *
     * Every local here is under {@see RESERVED_PREFIX}, for two reasons at once:
     * `extract()` of a user variable cannot shadow them, and they can be
     * stripped by name from what is collected. The collection is in a `finally`
     * so that a cell which threw still yields the variables it had bound.
     *
     * @param  array<string, mixed>  $__juphyter_before
     * @param  array<string, mixed>  $__juphyter_collected
     */
    private function evaluate(
        string $__juphyter_source,
        array $__juphyter_before,
        array &$__juphyter_collected,
    ): void {
        extract($__juphyter_before);

        try {
            eval($__juphyter_source);
        } finally {
            $__juphyter_collected = get_defined_vars();

            unset(
                $__juphyter_collected['__juphyter_source'],
                $__juphyter_collected['__juphyter_before'],
                $__juphyter_collected['__juphyter_collected'],
            );
        }
    }

    /**
     * The session's variables, with the executor's own removed.
     *
     * The cell's value is under the prefix too, so it is read before this runs
     * and dropped here with the rest.
     *
     * @param  array<string, mixed>  $collected
     * @return array<string, mixed>
     */
    private function withoutReserved(array $collected): array
    {
        foreach (array_keys($collected) as $name) {
            if (str_starts_with($name, self::RESERVED_PREFIX)) {
                unset($collected[$name]);
            }
        }

        return $collected;
    }

    /**
     * @return array{bundle: array<string, mixed>, metadata: array<string, mixed>}
     */
    private function format(mixed $value): array
    {
        if ($this->formatter !== null) {
            return ($this->formatter)($value);
        }

        return ['bundle' => ['text/plain' => self::plainly($value)], 'metadata' => []];
    }

    /**
     * The fallback rendering, for an executor with no display registry behind
     * it.
     *
     * Deliberately plain. Rendering values well is the display layer's work,
     * and duplicating any of it here would only produce a second answer to the
     * same question.
     */
    private static function plainly(mixed $value): string
    {
        return match (true) {
            $value === null => 'null',
            is_bool($value) => $value ? 'true' : 'false',
            is_string($value) => $value,
            is_int($value), is_float($value) => (string) $value,
            is_array($value) => 'array('.count($value).')',
            is_object($value) => 'object('.$value::class.')',
            is_resource($value) => 'resource('.get_resource_type($value).')',
            default => get_debug_type($value),
        };
    }

    /** Report an uncatchable fatal before the process goes, once. */
    private function watch(): void
    {
        if ($this->watching) {
            return;
        }

        $this->watching = true;

        register_shutdown_function(function (): void {
            // A cell is still running at shutdown, so it never reached the
            // finally: either something fatal happened or the cell ended the
            // process itself. With no cell running this is an ordinary exit and
            // none of it is ours to report.
            if ($this->running === null) {
                return;
            }

            // Flush first, so whatever the cell printed — the string `die()`
            // was given, among it — is delivered as stream output before the
            // terminal event, which is the order the contract promises.
            $this->output->stop();

            $error = Fatal::pending();

            if ($error === null) {
                // No error at all, so either the cell called exit or die, or
                // something asked the process to stop. Only the first is worth
                // reporting: without it the request would end with no terminal
                // event and the front end would be left waiting on a process
                // that has gone.
                if (! $this->expectingShutdown) {
                    $this->reportEnded();
                }

                return;
            }

            $where = $this->traceback->describe(
                $error['file'],
                $error['line'],
                $this->running,
                $this->label,
            );

            $traceback = [Fatal::ENAME.': '.Fatal::describe($error, $where)];

            if ($where !== null) {
                $traceback[] = '  at '.$where;
            }

            $this->emit([
                'type' => 'error',
                'ename' => Fatal::ENAME,
                'evalue' => Fatal::describe($error, $where),
                'traceback' => $traceback,
            ]);
        });
    }

    /**
     * Report a cell that ended the kernel process with `exit` or `die`.
     *
     * The exit code is not in reach. PHP hands a shutdown function no argument
     * and exposes the status nowhere: `connection_status()` is 0 and
     * `http_response_code()` is false on the command line whatever the code
     * was. The transport, being the parent process, is the one that can see it,
     * and is welcome to add it to what the front end shows.
     */
    private function reportEnded(): void
    {
        $evalue = 'The cell ended the kernel process with exit or die, so nothing further can run. '
            .'Restart the kernel to carry on.';

        $traceback = [self::ENDED.': '.$evalue];

        if ($this->running !== null) {
            $traceback[] = '  in Cell '.$this->label;
        }

        $this->emit([
            'type' => 'error',
            'ename' => self::ENDED,
            'evalue' => $evalue,
            'traceback' => $traceback,
        ]);
    }

    /** @param array<string, mixed> $event */
    private function emit(array $event): void
    {
        ($this->sink)($event);
    }
}
