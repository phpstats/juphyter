<?php

declare(strict_types=1);

use Juphyter\Engine\CellError;
use Juphyter\Engine\Cleaner;
use Juphyter\Engine\Executor;
use Juphyter\Engine\Interrupt;
use Juphyter\Engine\Session;
use PHPUnit\Framework\Assert;

/**
 * An executor that records every event it emits.
 *
 * @return array{0: Executor, 1: Closure(): list<array<string, mixed>>}
 */
function recorder(?Closure $formatter = null, ?Session $session = null): array
{
    $GLOBALS['juphyter_test_events'] = [];

    $executor = new Executor(
        function (array $event): void {
            $GLOBALS['juphyter_test_events'][] = $event;
        },
        $session,
        null,
        $formatter,
    );

    return [$executor, fn (): array => $GLOBALS['juphyter_test_events']];
}

/**
 * A name no other test has used.
 *
 * A Session knows only what its own cells declared, but PHP's function and
 * class tables are process-wide, so two tests declaring one name would meet the
 * uncatchable redeclaration fatal rather than the cleaner's refusal of it. One
 * engine process holds one session, so this is a property of the test runner
 * rather than of the kernel.
 */
function freshName(string $stem): string
{
    static $n = 0;

    return $stem.'_'.(++$n).'_'.bin2hex(random_bytes(3));
}

/** Run one cell and return the events it emitted. */
function ran(string $code, int $executionCount = 1): array
{
    [$executor, $events] = recorder();
    $executor->execute($code, $executionCount);

    return $events();
}

/** Only the events of one type. */
function ofType(array $events, string $type): array
{
    return array_values(array_filter($events, fn (array $e) => $e['type'] === $type));
}

/** The types of a run's events, in order. */
function typesOf(array $events): array
{
    return array_map(fn (array $e) => $e['type'], $events);
}

/**
 * The error events of a child run, or a failure saying what the child did.
 *
 * A fatal that goes unreported leaves no events at all, and the count alone
 * does not say whether the handler stayed quiet, died of a second fatal, or
 * never ran. The child's exit status and stderr distinguish those, and are
 * worth having in a CI log for a failure that does not reproduce locally.
 *
 * @param  array{events: list<array<string, mixed>>, stdout: string, stderr: string, status: int}  $run
 * @return list<array<string, mixed>>
 */
function errorsFrom(array $run): array
{
    $errors = ofType($run['events'], 'error');

    if ($errors === []) {
        Assert::fail(sprintf(
            "The child emitted no error event. It exited %d, saying:\n%s",
            $run['status'],
            trim($run['stderr']) === '' ? '  (nothing on stderr)' : '  '.trim($run['stderr']),
        ));
    }

    return $errors;
}

/**
 * Run cells in a child process and return the events it emitted.
 *
 * Uncatchable fatals and signals cannot be exercised in the test runner: the
 * first would end it and the second would throw somewhere unhelpful. The child
 * writes one JSON event per line to stdout, and both of its streams go to files
 * rather than to pipes, so neither can fill and deadlock however much the cell
 * prints.
 *
 * @param  list<string>  $cells
 * @param  list<string>  $ini  php.ini settings as `-d` arguments
 * @return array{events: list<array<string, mixed>>, stdout: string, stderr: string, status: int}
 */
function inChild(array $cells, array $ini = []): array
{
    $directory = sys_get_temp_dir().'/juphyter-child-'.bin2hex(random_bytes(6));
    mkdir($directory, 0o777, true);

    $autoload = dirname(__DIR__, 3).'/vendor/autoload.php';
    $script = $directory.'/run.php';
    $out = $directory.'/out';
    $err = $directory.'/err';

    file_put_contents($script, '<?php
require '.var_export($autoload, true).';

use Juphyter\Engine\Executor;

$sink = function (array $event): void {
    fwrite(STDOUT, json_encode($event)."\n");
    // Unbuffered, so whatever has been emitted survives a fatal.
    fflush(STDOUT);
};

$executor = new Executor($sink);

// So a cell can reach the executor, which is how a test stands in for the
// transport marking an intentional shutdown.
$GLOBALS["juphyter_executor"] = $executor;

foreach (json_decode(file_get_contents('.var_export($directory.'/cells.json', true).'), true) as $i => $code) {
    $executor->execute($code, $i + 1);
}
');
    file_put_contents($directory.'/cells.json', json_encode($cells));

    $arguments = array_map(fn (string $setting) => '-d '.escapeshellarg($setting), $ini);

    $command = sprintf(
        '%s %s %s > %s 2> %s',
        escapeshellarg(PHP_BINARY),
        implode(' ', $arguments),
        escapeshellarg($script),
        escapeshellarg($out),
        escapeshellarg($err),
    );

    $status = 0;
    exec($command, $ignored, $status);

    $stdout = (string) file_get_contents($out);
    $stderr = (string) file_get_contents($err);

    foreach ([$script, $directory.'/cells.json', $out, $err] as $file) {
        @unlink($file);
    }

    @rmdir($directory);

    $events = [];

    foreach (explode("\n", trim($stdout)) as $line) {
        if ($line === '') {
            continue;
        }

        $decoded = json_decode($line, true);

        if (is_array($decoded)) {
            $events[] = $decoded;
        }
    }

    return ['events' => $events, 'stdout' => $stdout, 'stderr' => $stderr, 'status' => $status];
}

describe('terminal events', function () {
    it('ends a cell with nothing to show in a single ok', function () {
        $events = ran('$x = 1;', 7);

        expect(typesOf($events))->toBe(['ok'])
            ->and($events[0]['execution_count'])->toBe(7);
    });

    it('emits the result immediately before ok, carrying the execution count', function () {
        $events = ran('1 + 1;', 4);

        expect(typesOf($events))->toBe(['result', 'ok'])
            ->and($events[0]['execution_count'])->toBe(4)
            ->and($events[0]['bundle'])->toBe(['text/plain' => '2'])
            ->and($events[0]['metadata'])->toBe([]);
    });

    it('emits no result for a cell the cleaner found no value in', function () {
        expect(typesOf(ran('echo "hi";')))->toBe(['stream', 'ok']);
    });

    it('ends a thrown cell in a single error and no ok', function () {
        $events = ran('throw new RuntimeException("nope");');

        expect(typesOf($events))->toBe(['error'])
            ->and($events[0]['ename'])->toBe('RuntimeException')
            ->and($events[0]['evalue'])->toBe('nope');
    });

    it('reports a refused cell as an error without running anything', function () {
        $events = ran('namespace Bad;', 3);

        expect(typesOf($events))->toBe(['error'])
            ->and($events[0]['ename'])->toBe(CellError::UNSUPPORTED)
            ->and($events[0]['evalue'])->toContain('cannot declare a namespace')
            ->and($events[0]['traceback'])->toBe([
                CellError::UNSUPPORTED.': '.$events[0]['evalue'],
                '  at Cell 3, line 1',
            ]);
    });

    it('reports a parse error with the cleaner\'s ename and the cell\'s line', function () {
        $events = ran("\$a = 1;\n\$b = 2;\nfoo(", 2);

        expect($events[0]['ename'])->toBe(CellError::PARSE_ERROR)
            ->and($events[0]['traceback'][1])->toBe('  at Cell 2, line 3');
    });

    it('reports a refusal that has no line without inventing one', function () {
        [$executor, $events] = recorder();
        $executor->execute('class A {} class A {}', 1);

        expect(ofType($events(), 'error'))->toHaveCount(1);
    });
});

describe('variables between cells', function () {
    it('keeps scalars, arrays and objects', function () {
        [$executor, $events] = recorder();
        $executor->execute('$n = 42; $list = [1, 2]; $o = new stdClass(); $o->v = "x";', 1);
        $executor->execute('[$n, $list, $o->v];', 2);

        expect($executor->variables())->toHaveKeys(['n', 'list', 'o'])
            ->and($executor->variables()['n'])->toBe(42)
            ->and($executor->variables()['list'])->toBe([1, 2])
            ->and($executor->variables()['o']->v)->toBe('x');
    });

    it('keeps the same object, not a copy of it', function () {
        [$executor] = recorder();
        $executor->execute('$o = new stdClass();', 1);
        $first = $executor->variables()['o'];
        $executor->execute('$o->touched = true;', 2);

        expect($executor->variables()['o'])->toBe($first)
            ->and($first->touched)->toBeTrue();
    });

    it('keeps a resource open across cells', function () {
        [$executor, $events] = recorder();
        $executor->execute('$h = fopen("php://memory", "r+"); fwrite($h, "abc"); null;', 1);
        $executor->execute('rewind($h); stream_get_contents($h);', 2);

        $results = ofType($events(), 'result');

        expect(end($results)['bundle']['text/plain'])->toBe('abc')
            ->and($executor->variables()['h'])->toBeResource();
    });

    it('keeps functions and classes a cell declared', function () {
        [$executor, $events] = recorder();
        $fn = freshName('twice');
        $class = freshName('Holder');

        $executor->execute("function {$fn}(int \$i): int { return \$i * 2; } class {$class} { public int \$v = 9; }", 1);
        $executor->execute("{$fn}((new {$class}())->v);", 2);

        expect(ofType($events(), 'result')[0]['bundle']['text/plain'])->toBe('18')
            ->and($executor->session()->declaredFunctions())->toBe([$fn])
            ->and($executor->session()->declaredClassLike())->toBe([$class]);
    });

    it('removes a variable a cell unset, rather than merging it back', function () {
        [$executor] = recorder();
        $executor->execute('$keep = 1; $drop = 2;', 1);
        $executor->execute('unset($drop);', 2);

        expect($executor->variables())->toHaveKey('keep')
            ->and($executor->variables())->not->toHaveKey('drop');
    });

    it('keeps the variables a cell bound before it threw', function () {
        [$executor] = recorder();
        $executor->execute('$before = 1; throw new RuntimeException("stop"); $after = 2;', 1);

        expect($executor->variables())->toHaveKey('before')
            ->and($executor->variables()['before'])->toBe(1)
            ->and($executor->variables())->not->toHaveKey('after');
    });

    it('never leaks the reserved result variable into the session', function () {
        [$executor] = recorder();
        $executor->execute('1 + 1;', 1);

        expect($executor->variables())->not->toHaveKey(Cleaner::RESULT_VARIABLE)
            ->and(array_keys($executor->variables()))->toBe([]);
    });

    it('never leaks any reserved variable into the session', function () {
        [$executor] = recorder();
        $executor->execute('$ok = 1; ${"'.Executor::RESERVED_PREFIX.'sneaky"} = 2;', 1);

        expect(array_keys($executor->variables()))->toBe(['ok']);
    });

    it('does not carry a reference across cells, which no PHP REPL does', function () {
        [$executor] = recorder();
        $executor->execute('$a = 1; $b = &$a;', 1);
        $executor->execute('$b = 99;', 2);

        // $b was bound by value on the way in, so $a is untouched.
        expect($executor->variables()['b'])->toBe(99)
            ->and($executor->variables()['a'])->toBe(1);
    });

    it('starts a cell with the session already in scope', function () {
        [$executor, $events] = recorder();
        $executor->execute('$seed = 5;', 1);
        $executor->execute('$seed * 3;', 2);

        expect(ofType($events(), 'result')[0]['bundle']['text/plain'])->toBe('15');
    });
});

describe('output', function () {
    it('turns what a cell prints into a stdout stream event', function () {
        $events = ran('echo "hello";');

        expect(ofType($events, 'stream'))->toHaveCount(1)
            ->and($events[0]['name'])->toBe('stdout')
            ->and($events[0]['text'])->toBe('hello');
    });

    it('emits each print separately and in order', function () {
        $events = ofType(ran('echo "one"; echo "two"; echo "three";'), 'stream');

        expect(array_column($events, 'text'))->toBe(['one', 'two', 'three']);
    });

    it('emits output as it is produced, not when the cell ends', function () {
        // The cell asks how many events have reached the sink. If output were
        // held to the end of the cell, the first echo would not have been
        // delivered yet and the count would be zero.
        [$executor, $events] = recorder();
        $executor->execute(
            'echo "first"; $delivered = count($GLOBALS["juphyter_test_events"]); echo "second";',
            1,
        );

        expect($executor->variables()['delivered'])->toBe(1)
            ->and(array_column(ofType($events(), 'stream'), 'text'))->toBe(['first', 'second']);
    });

    it('delivers output before the error of a cell that printed and then threw', function () {
        $events = ran('echo "printed"; throw new RuntimeException("then failed");');

        expect(typesOf($events))->toBe(['stream', 'error'])
            ->and($events[0]['text'])->toBe('printed');
    });

    it('does not let the cell\'s output reach the real stdout', function () {
        ob_start();
        ran('echo "swallowed";');
        $leaked = ob_get_clean();

        expect($leaked)->toBe('');
    });

    it('closes a buffer the cell left open, without taking any outer one', function () {
        $before = ob_get_level();
        $events = ran('ob_start(); echo "inside";');

        expect(ob_get_level())->toBe($before)
            ->and(typesOf($events))->toContain('ok');
    });

    it('survives a cell that closes more buffers than it opened', function () {
        $before = ob_get_level();
        ran('@ob_end_clean();');

        expect(ob_get_level())->toBe($before);
    });
});

describe('diagnostics', function () {
    it('turns a warning into a stderr stream event, not a result', function () {
        $events = ran('$x = $neverSet;', 5);

        expect(ofType($events, 'result'))->toBe([])
            ->and(ofType($events, 'stream'))->toHaveCount(1)
            ->and($events[0]['name'])->toBe('stderr')
            ->and($events[0]['text'])->toContain('Warning: Undefined variable $neverSet')
            ->and($events[0]['text'])->toContain('in Cell 5, line 1');
    });

    it('turns a deprecation into a stderr stream event', function () {
        $events = ran('trigger_error("old way", E_USER_DEPRECATED);');

        expect($events[0]['name'])->toBe('stderr')
            ->and($events[0]['text'])->toContain('Deprecated: old way');
    });

    it('turns a user notice into a stderr stream event', function () {
        expect(ran('trigger_error("just so you know", E_USER_NOTICE)')[0]['text'])
            ->toContain('Notice: just so you know');
    });

    it('drops a diagnostic the cell suppressed with @', function () {
        $events = ran('$x = @$neverSet;');

        expect(ofType($events, 'stream'))->toBe([])
            ->and(typesOf($events))->toBe(['ok']);
    });

    it('still ends a warning-producing cell with ok', function () {
        expect(typesOf(ran('$x = $neverSet;')))->toBe(['stream', 'ok']);
    });

    it('names the cell line a warning came from', function () {
        $events = ran("\$a = 1;\n\$b = 2;\n\$c = \$neverSet;", 9);

        expect($events[0]['text'])->toContain('in Cell 9, line 3');
    });
});

describe('strict types', function () {
    // The prelude puts declare(strict_types=1) at the top of every cell, so
    // this is really a test that the prelude survives into eval() through the
    // executor: without it, PHP would coerce "5" and no error would be raised.
    it('enforces strict types, so a weak argument is a TypeError', function () {
        [$executor, $events] = recorder();
        $fn = freshName('strictly');

        $executor->execute("function {$fn}(int \$i): int { return \$i; }", 1);
        $executor->execute("{$fn}(\"5\");", 2);

        $error = ofType($events(), 'error');

        expect($error)->toHaveCount(1)
            ->and($error[0]['ename'])->toBe('TypeError')
            ->and($error[0]['evalue'])->toContain('must be of type int, string given');
    });

    it('still accepts a correctly typed argument', function () {
        [$executor, $events] = recorder();
        $fn = freshName('strictly');

        $executor->execute("function {$fn}(int \$i): int { return \$i; }", 1);
        $executor->execute("{$fn}(5);", 2);

        expect(ofType($events(), 'result')[0]['bundle']['text/plain'])->toBe('5');
    });
});

describe('committing declarations', function () {
    // These two are what catch a missing commitDeclarations() call: the first
    // fails if the commit is skipped when a cell throws, and the second fails
    // if the names are committed without checking the runtime.
    it('holds a class a cell declared before it threw, and refuses the cell again', function () {
        [$executor, $events] = recorder();
        $class = freshName('DeclaredThenThrew');
        $code = "class {$class} {} throw new RuntimeException(\"after the class\");";

        $executor->execute($code, 1);

        expect(ofType($events(), 'error'))->toHaveCount(1)
            ->and($executor->session()->declaredClassLike())->toBe([$class])
            ->and(class_exists($class, false))->toBeTrue();

        $executor->execute($code, 2);
        $second = ofType($events(), 'error');

        expect($second)->toHaveCount(2)
            ->and($second[1]['ename'])->toBe(CellError::REDECLARATION)
            ->and($second[1]['evalue'])->toContain($class);
    });

    it('does not hold a class whose parent was missing, and runs the corrected cell', function () {
        [$executor, $events] = recorder();
        $class = freshName('Correctable');

        $executor->execute("class {$class} extends Mispelled_{$class} {}", 1);

        expect(ofType($events(), 'error'))->toHaveCount(1)
            ->and($executor->session()->declaredClassLike())->toBe([])
            ->and(class_exists($class, false))->toBeFalse();

        $executor->execute("class {$class} { public int \$v = 3; }", 2);

        expect(ofType($events(), 'ok'))->toHaveCount(1)
            ->and($executor->session()->declaredClassLike())->toBe([$class])
            ->and(class_exists($class, false))->toBeTrue();
    });

    it('commits after a cell that was interrupted or failed for any reason', function () {
        [$executor] = recorder();
        $fn = freshName('committed_anyway');

        $executor->execute("function {$fn}() {} throw new LogicException(\"x\");", 1);

        expect($executor->session()->declaredFunctions())->toBe([$fn]);
    });
});

describe('display', function () {
    it('publishes a value mid-cell as a display event', function () {
        [$executor, $events] = recorder();
        $executor->publish('shown');

        expect(typesOf($events()))->toBe(['display'])
            ->and($events()[0]['bundle'])->toBe(['text/plain' => 'shown'])
            ->and($events()[0]['metadata'])->toBe([]);
    });

    it('publishes an already-formatted bundle without formatting it again', function () {
        // The display registry formats on its own behalf, so this is the path
        // the exported display() function reaches through the engine's
        // publisher. A formatter that would change the bundle proves it is not
        // run a second time.
        [$executor, $events] = recorder(fn (mixed $v) => [
            'bundle' => ['text/plain' => 'FORMATTED'],
            'metadata' => ['formatted' => true],
        ]);

        $executor->publishBundle(['text/html' => '<p>ready</p>'], ['isolated' => true]);

        expect(typesOf($events()))->toBe(['display'])
            ->and($events()[0]['bundle'])->toBe(['text/html' => '<p>ready</p>'])
            ->and($events()[0]['metadata'])->toBe(['isolated' => true]);
    });

    it('defaults a published bundle\'s metadata to empty', function () {
        [$executor, $events] = recorder();
        $executor->publishBundle(['text/plain' => 'bare']);

        expect($events()[0]['metadata'])->toBe([]);
    });

    it('routes publish() through publishBundle, so one place builds the event', function () {
        [$executor, $events] = recorder();
        $executor->publish('through');

        expect(typesOf($events()))->toBe(['display'])
            ->and($events()[0])->toHaveKeys(['type', 'bundle', 'metadata']);
    });

    it('takes metadata for a published value', function () {
        [$executor, $events] = recorder();
        $executor->publish('shown', ['width' => 400]);

        expect($events()[0]['metadata'])->toBe(['width' => 400]);
    });

    it('uses an injected formatter for a result instead of the fallback', function () {
        [$executor, $events] = recorder(fn (mixed $v) => [
            'bundle' => ['text/html' => '<b>'.$v.'</b>', 'text/plain' => (string) $v],
            'metadata' => ['from' => 'the registry'],
        ]);

        $executor->execute('"table";', 1);
        $result = ofType($events(), 'result')[0];

        expect($result['bundle'])->toBe(['text/html' => '<b>table</b>', 'text/plain' => 'table'])
            ->and($result['metadata'])->toBe(['from' => 'the registry']);
    });

    it('uses an injected formatter for a published value too', function () {
        [$executor, $events] = recorder(fn (mixed $v) => [
            'bundle' => ['application/json' => $v],
            'metadata' => [],
        ]);

        $executor->publish([1, 2]);

        expect($events()[0]['bundle'])->toBe(['application/json' => [1, 2]]);
    });

    it('describes the common value kinds in its fallback', function () {
        foreach ([
            '1 + 1;' => '2',
            '"text";' => 'text',
            'true;' => 'true',
            '1.5;' => '1.5',
            '[1, 2, 3];' => 'array(3)',
            'new stdClass();' => 'object(stdClass)',
        ] as $code => $expected) {
            expect(ofType(ran($code), 'result')[0]['bundle']['text/plain'])->toBe($expected);
        }
    });
});

describe('a value of null', function () {
    // A void call is written for its effect, and an `Out` of "null" after it is
    // noise. Python's notebooks never show None this way either.
    it('publishes no result, and still ends in ok', function () {
        $events = ran('null;');

        expect(typesOf($events))->toBe(['ok'])
            ->and(ofType($events, 'result'))->toBe([]);
    });

    it('publishes nothing for a void call as the last statement', function () {
        [$executor, $events] = recorder();
        $executor->execute('function nothing(): void {} ', 1);
        $executor->execute('nothing();', 2);

        expect(typesOf($events()))->toBe(['ok', 'ok']);
    });

    it('leaves var_dump with its output and no result', function () {
        // var_dump writes in several pieces, and each piece is its own stream
        // event; what matters is that none of them is a result.
        $events = ran('$v = [1, 2]; var_dump($v);');

        expect(ofType($events, 'result'))->toBe([])
            ->and(ofType($events, 'stream'))->not->toBeEmpty()
            ->and(array_values(array_unique(typesOf($events))))->toBe(['stream', 'ok'])
            ->and(end($events)['type'])->toBe('ok');
    });

    it('still advances the execution count', function () {
        [$executor, $events] = recorder();
        $executor->execute('null;', 1);
        $executor->execute('2;', 2);

        expect(ofType($events(), 'ok')[0]['execution_count'])->toBe(1)
            ->and(ofType($events(), 'result')[0]['execution_count'])->toBe(2);
    });

    it('publishes every other falsy value, which are values a cell meant', function () {
        foreach ([
            '0;' => '0',
            'false;' => 'false',
            '"";' => '',
            '[];' => 'array(0)',
            '0.0;' => '0',
            '"null";' => 'null',
        ] as $code => $expected) {
            $events = ran($code);

            expect(ofType($events, 'result'))->toHaveCount(1, 'no result for '.$code)
                ->and(ofType($events, 'result')[0]['bundle']['text/plain'])->toBe($expected);
        }
    });
});

describe('the session object', function () {
    it('uses a session it was given, so the transport can share one', function () {
        $session = new Session();
        [$executor] = recorder(null, $session);
        $executor->execute('use ArrayObject as Shared;', 1);

        expect($session->importStatements())->toBe(['use ArrayObject as Shared;'])
            ->and($executor->session())->toBe($session);
    });

    it('carries accumulated imports into a later cell', function () {
        [$executor, $events] = recorder();
        $executor->execute('use ArrayObject as Carried;', 1);
        $executor->execute('get_class(new Carried([]));', 2);

        expect(ofType($events(), 'result')[0]['bundle']['text/plain'])->toBe('ArrayObject');
    });
});

describe('in a child process', function () {
    it('runs cells and emits the same events there', function () {
        $run = inChild(['echo "child";', '1 + 1;']);

        expect(typesOf($run['events']))->toBe(['stream', 'ok', 'result', 'ok'])
            ->and($run['events'][0]['text'])->toBe('child');
    });

    it('reports an exhausted memory limit from the shutdown handler', function () {
        $run = inChild(
            ['$held = []; while (true) { $held[] = str_repeat("x", 1024 * 64); }'],
            ['memory_limit=24M'],
        );

        $errors = errorsFrom($run);

        expect($errors)->not->toBeEmpty()
            ->and($errors[0]['ename'])->toBe('FatalError')
            ->and($errors[0]['evalue'])->toContain('memory')
            ->and(typesOf($run['events']))->not->toContain('ok');
    });

    it('reports an interrupt and leaves the session usable', function () {
        $run = inChild([
            '$i = 0; while (true) { $i++; if ($i === 500) { posix_kill(posix_getpid(), SIGINT); } }',
            '$survived = "yes"; $survived;',
        ]);

        $errors = ofType($run['events'], 'error');
        $results = ofType($run['events'], 'result');

        expect($errors)->toHaveCount(1)
            ->and($errors[0]['ename'])->toBe(Interrupt::ENAME)
            ->and($results)->toHaveCount(1)
            ->and($results[0]['bundle']['text/plain'])->toBe('yes')
            ->and($run['status'])->toBe(0);
    })->skip(! Interrupt::available() || ! function_exists('posix_kill'), 'Interrupts need the pcntl and posix extensions.');

    it('keeps the variables an interrupted cell had already bound', function () {
        $run = inChild([
            '$bound = "before"; $i = 0; while (true) { $i++; if ($i === 500) { posix_kill(posix_getpid(), SIGINT); } }',
            '$bound;',
        ]);

        expect(ofType($run['events'], 'result')[0]['bundle']['text/plain'])->toBe('before');
    })->skip(! Interrupt::available() || ! function_exists('posix_kill'), 'Interrupts need the pcntl and posix extensions.');

    it('is not swallowed by a cell\'s own catch (Exception)', function () {
        // Interrupt extends Error for exactly this: defensive code of the shape
        // `while (true) { try { ... } catch (Exception $e) {} }` is ordinary,
        // and under Exception it ate the stop button and ran until killed.
        $run = inChild([
            '$n = 0; while (true) { try { usleep(2000); $n++; if ($n === 50) { posix_kill(posix_getpid(), SIGINT); } } catch (Exception $e) {} }',
            '$after = "usable"; $after;',
        ]);

        $errors = ofType($run['events'], 'error');

        expect($errors)->toHaveCount(1)
            ->and($errors[0]['ename'])->toBe(Interrupt::ENAME)
            ->and(ofType($run['events'], 'result')[0]['bundle']['text/plain'])->toBe('usable')
            ->and($run['status'])->toBe(0);
    })->skip(! Interrupt::available() || ! function_exists('posix_kill'), 'Interrupts need the pcntl and posix extensions.');

    it('ignores a signal that arrives between cells rather than dying', function () {
        $run = inChild([
            '$x = 1;',
            'posix_kill(posix_getpid(), SIGINT); $x + 1;',
        ]);

        // The handler is installed for the duration of a cell, so this one is
        // caught; what matters is that the process did not die either way.
        expect($run['status'])->toBe(0)
            ->and(typesOf($run['events']))->toContain('ok');
    })->skip(! Interrupt::available() || ! function_exists('posix_kill'), 'Interrupts need the pcntl and posix extensions.');
});

describe('a cell that ends the process', function () {
    it('reports a bare exit as a terminal error', function () {
        $run = inChild(['echo "before\n"; exit;']);

        expect(typesOf($run['events']))->toBe(['stream', 'error'])
            ->and($run['events'][0]['text'])->toBe("before\n")
            ->and($run['events'][1]['ename'])->toBe(Executor::ENDED)
            ->and($run['events'][1]['evalue'])->toContain('exit or die')
            ->and($run['events'][1]['evalue'])->toContain('Restart the kernel')
            ->and($run['events'][1]['traceback'])->toContain('  in Cell 1')
            ->and($run['status'])->toBe(0);
    });

    it('reports exit(0) the same way', function () {
        $run = inChild(['exit(0);']);

        expect(typesOf($run['events']))->toBe(['error'])
            ->and($run['events'][0]['ename'])->toBe(Executor::ENDED)
            ->and($run['status'])->toBe(0);
    });

    it('reports exit with a non-zero code, and the code still reaches the parent', function () {
        $run = inChild(['exit(3);']);

        expect(typesOf($run['events']))->toBe(['error'])
            ->and($run['events'][0]['ename'])->toBe(Executor::ENDED)
            ->and($run['status'])->toBe(3);
    });

    it('delivers what die() printed before the terminal error', function () {
        $run = inChild(['die("died with a message\n");']);

        expect(typesOf($run['events']))->toBe(['stream', 'error'])
            ->and($run['events'][0]['name'])->toBe('stdout')
            ->and($run['events'][0]['text'])->toBe("died with a message\n")
            ->and($run['events'][1]['ename'])->toBe(Executor::ENDED);
    });

    it('stops the cells after it, since the process is gone', function () {
        $run = inChild(['exit(0);', '$never = "run"; $never;']);

        expect(ofType($run['events'], 'result'))->toBe([])
            ->and(typesOf($run['events']))->toBe(['error']);
    });

    it('says nothing extra on an ordinary shutdown', function () {
        // No cell is running when the process ends, so the handler has nothing
        // to report and the terminal events are the cells' own.
        $run = inChild(['$x = 1;', '2 + 2;']);

        expect(typesOf($run['events']))->toBe(['ok', 'result', 'ok'])
            ->and(ofType($run['events'], 'error'))->toBe([])
            ->and($run['status'])->toBe(0);
    });

    it('says nothing when the shutdown is one the engine asked for', function () {
        // What a shutdown_request arriving mid-cell looks like: the transport
        // marks the shutdown, then ends the process with a cell still running.
        // Reporting SystemExit there would blame the cell for a shutdown the
        // transport asked for.
        $run = inChild(['$GLOBALS["juphyter_executor"]->expectShutdown(); exit(0);']);

        expect(ofType($run['events'], 'error'))->toBe([])
            ->and($run['events'])->toBe([])
            ->and($run['status'])->toBe(0);
    });

    it('still reports an unmarked exit, so the mark is what makes the difference', function () {
        $marked = inChild(['$GLOBALS["juphyter_executor"]->expectShutdown(); exit(0);']);
        $unmarked = inChild(['exit(0);']);

        expect(ofType($marked['events'], 'error'))->toBe([])
            ->and(ofType($unmarked['events'], 'error'))->toHaveCount(1)
            ->and(ofType($unmarked['events'], 'error')[0]['ename'])->toBe(Executor::ENDED);
    });

    it('still delivers what a cell printed before an expected shutdown', function () {
        $run = inChild(['echo "flushed\n"; $GLOBALS["juphyter_executor"]->expectShutdown(); exit(0);']);

        expect(typesOf($run['events']))->toBe(['stream'])
            ->and($run['events'][0]['text'])->toBe("flushed\n");
    });

    it('still reports a fatal even when a shutdown was expected', function () {
        // Running out of memory during a shutdown is still worth saying out
        // loud, so the mark silences only the SystemExit report.
        $run = inChild(
            ['$GLOBALS["juphyter_executor"]->expectShutdown(); $held = []; while (true) { $held[] = str_repeat("x", 1024 * 64); }'],
            ['memory_limit=24M'],
        );

        $errors = errorsFrom($run);

        expect($errors)->toHaveCount(1)
            ->and($errors[0]['ename'])->toBe('FatalError');
    });

    it('reports a fatal from a cell that had already printed something', function () {
        // The regression this guards. Reporting a fatal allocates — reading the
        // error, flushing what the cell printed, encoding the event — and a
        // cell that exhausts the limit leaves the process sitting at it, so
        // whichever step asks first used to fail and take the whole handler
        // with it. The front end then saw the cell's output and no terminal
        // event, and waited on a process that had already gone.
        //
        // The output is what makes it reproducible: it leaves the allocator
        // with less to spare at the moment the limit is hit. Whether the
        // unfixed handler survived came down to that layout, which is why this
        // passed on PHP 8.3.33 and 8.4.25 and failed on 8.3.35 and on CI.
        $run = inChild(
            ['echo "printed\n"; $held = []; while (true) { $held[] = str_repeat("x", 1024 * 64); }'],
            ['memory_limit=24M'],
        );

        $errors = errorsFrom($run);

        expect($errors)->toHaveCount(1)
            ->and($errors[0]['ename'])->toBe('FatalError')
            ->and($errors[0]['evalue'])->toContain('memory')
            ->and(ofType($run['events'], 'stream')[0]['text'])->toBe("printed\n");
    });

    it('still reports a fatal as a fatal rather than as an exit', function () {
        $run = inChild(
            ['$held = []; while (true) { $held[] = str_repeat("x", 1024 * 64); }'],
            ['memory_limit=24M'],
        );

        $errors = errorsFrom($run);

        expect($errors)->toHaveCount(1)
            ->and($errors[0]['ename'])->toBe('FatalError')
            ->and($errors[0]['ename'])->not->toBe(Executor::ENDED);
    });
});
