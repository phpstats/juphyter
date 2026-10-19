<?php

declare(strict_types=1);

use Juphyter\Engine\CellError;
use Juphyter\Engine\Cleaner;
use Juphyter\Engine\Executor;
use Juphyter\Engine\Interrupt;
use Juphyter\Engine\Session;
use Juphyter\Engine\Traceback;

/**
 * Run cells through a real executor and return the traceback of the error the
 * last one produced.
 *
 * The traceback is only meaningful against frames a real throw produced, so
 * these tests go through the executor rather than constructing exceptions by
 * hand.
 *
 * @param  list<string>  $cells  Run in order, numbered from 1.
 * @return list<string>
 */
function tracebackOf(array $cells): array
{
    $events = [];
    $executor = new Executor(function (array $event) use (&$events): void {
        $events[] = $event;
    });

    foreach ($cells as $index => $code) {
        $executor->execute($code, $index + 1);
    }

    $errors = array_values(array_filter($events, fn (array $e) => $e['type'] === 'error'));

    expect($errors)->not->toBeEmpty('no error event was emitted');

    return end($errors)['traceback'];
}

/** A name no other test has used, since PHP's symbol tables are process-wide. */
function tracebackName(string $stem): string
{
    static $n = 0;

    return $stem.'_'.(++$n).'_'.bin2hex(random_bytes(3));
}

describe('a throw from cell code', function () {
    it('reports the cell and the line the user wrote', function () {
        $traceback = tracebackOf(["\$a = 1;\n\$b = 2;\nthrow new RuntimeException('here');"]);

        expect($traceback)->toBe([
            'RuntimeException: here',
            '  at Cell 1, line 3',
        ]);
    });

    it('subtracts the prelude, however long it has grown', function () {
        // Three imports, so the prelude is four lines and a naive traceback
        // would report line 5 for what the user wrote on line 1.
        $traceback = tracebackOf([
            'use ArrayObject as A;',
            'use SplStack as B;',
            'use SplQueue as C;',
            "throw new LogicException('offset');",
        ]);

        expect($traceback)->toBe([
            'LogicException: offset',
            '  at Cell 4, line 1',
        ]);
    });

    it('reports the class of the thrown value as the ename line', function () {
        expect(tracebackOf(['throw new InvalidArgumentException("bad");'])[0])
            ->toBe('InvalidArgumentException: bad');
    });

    it('reports an Error as readily as an Exception', function () {
        $traceback = tracebackOf(['intdiv(1, 0);']);

        expect($traceback[0])->toStartWith('DivisionByZeroError:')
            ->and($traceback[1])->toBe('  at Cell 1, line 1, in intdiv()');
    });

    it('stops at the executor, leaving juphyter\'s own frames out', function () {
        $traceback = tracebackOf(["throw new RuntimeException('clean');"]);

        expect($traceback)->toHaveCount(2)
            ->and(implode("\n", $traceback))->not->toContain('Executor.php')
            ->and(implode("\n", $traceback))->not->toContain('eval()')
            ->and(implode("\n", $traceback))->not->toContain('vendor/bin');
    });
});

describe('a throw from a function an earlier cell declared', function () {
    it('maps the throw to the cell that declared the function', function () {
        $fn = tracebackName('boom');

        $traceback = tracebackOf([
            "function {$fn}() {\n    throw new LogicException('from the function');\n}",
            "\$x = 1;\n{$fn}();",
        ]);

        expect($traceback)->toBe([
            'LogicException: from the function',
            "  at Cell 1, line 2, in {$fn}()",
            '  at Cell 2, line 2',
        ]);
    });

    it('uses the declaring cell\'s prelude, not the running cell\'s', function () {
        $fn = tracebackName('boom');

        // The function is declared in cell 1, whose prelude is one line. By
        // cell 3 the prelude is three lines, so using the running cell's offset
        // would put the function's frame two lines out.
        $traceback = tracebackOf([
            "function {$fn}() {\n    throw new LogicException('offsets differ');\n}",
            'use ArrayObject as D; use SplStack as E;',
            "{$fn}();",
        ]);

        expect($traceback)->toBe([
            'LogicException: offsets differ',
            "  at Cell 1, line 2, in {$fn}()",
            '  at Cell 3, line 1',
        ]);
    });

    it('maps a method of a class an earlier cell declared', function () {
        $class = tracebackName('Thrower');

        $traceback = tracebackOf([
            "class {$class} {\n    public function go(): void\n    {\n        throw new RuntimeException('from the method');\n    }\n}",
            "(new {$class}())->go();",
        ]);

        expect($traceback)->toBe([
            'RuntimeException: from the method',
            "  at Cell 1, line 4, in {$class}->go()",
            '  at Cell 2, line 1',
        ]);
    });

    it('maps a static method too', function () {
        $class = tracebackName('StaticThrower');

        $traceback = tracebackOf([
            "class {$class} {\n    public static function go(): void\n    {\n        throw new RuntimeException('static');\n    }\n}",
            "{$class}::go();",
        ]);

        expect($traceback[1])->toBe("  at Cell 1, line 4, in {$class}::go()");
    });

    it('maps a chain through two earlier cells', function () {
        $inner = tracebackName('inner');
        $outer = tracebackName('outer');

        $traceback = tracebackOf([
            "function {$inner}() {\n    throw new LogicException('deep');\n}",
            "function {$outer}() {\n    {$inner}();\n}",
            "{$outer}();",
        ]);

        expect($traceback)->toBe([
            'LogicException: deep',
            "  at Cell 1, line 2, in {$inner}()",
            "  at Cell 2, line 2, in {$outer}()",
            '  at Cell 3, line 1',
        ]);
    });
});

describe('a throw from inside vendor code', function () {
    it('leaves a vendor frame as its own file and line', function () {
        // A real package in this project's vendor directory, thrown from inside
        // its own code rather than from the cell.
        $traceback = tracebackOf([
            '(new PhpParser\\ParserFactory())->createForNewestSupportedVersion()->parse("<?php if (true) {");',
        ]);

        $joined = implode("\n", $traceback);

        expect($traceback[0])->toStartWith('PhpParser\\Error:')
            ->and($joined)->toContain('vendor/nikic/php-parser')
            ->and($joined)->toContain('.php, line ');
    });

    it('rewrites the cell frame while leaving the vendor frames alone', function () {
        $traceback = tracebackOf([
            "\$parser = (new PhpParser\\ParserFactory())->createForNewestSupportedVersion();\n\$parser->parse('<?php if (true) {');",
        ]);

        $cellFrames = array_values(array_filter($traceback, fn (string $l) => str_contains($l, 'Cell ')));
        $vendorFrames = array_values(array_filter($traceback, fn (string $l) => str_contains($l, 'vendor/')));

        expect($cellFrames)->toHaveCount(1)
            ->and($cellFrames[0])->toStartWith('  at Cell 1, line 2')
            ->and($vendorFrames)->not->toBeEmpty();

        // Nothing in a vendor frame pretends to be a cell.
        foreach ($vendorFrames as $frame) {
            expect($frame)->not->toContain('Cell ');
        }
    });

    it('names the function a vendor frame was called from', function () {
        $traceback = tracebackOf(['str_repeat("x", -1);']);

        expect($traceback[1])->toBe('  at Cell 1, line 1, in str_repeat()');
    });

    it('does not report an internal function\'s location twice', function () {
        // The throw site and the call site are the same place for an internal
        // function, and only the one naming the function is kept.
        $traceback = tracebackOf(['str_repeat("x", -1);']);

        expect($traceback)->toBe([
            'ValueError: str_repeat(): Argument #2 ($times) must be greater than or equal to 0',
            '  at Cell 1, line 1, in str_repeat()',
        ]);
    });
});

describe('a frame no cell claims', function () {
    it('says what a closure is without claiming to know where', function () {
        // A closure declared in one cell and called from another cannot be
        // placed: its frame says {closure} on 8.3 and embeds the eval'd file
        // and line from 8.4, and every cell's eval shares one file string. The
        // old behaviour fell back to the running cell and printed a confident
        // Cell 2, line 2, which was simply wrong.
        $traceback = tracebackOf([
            "\$f = function () {\n    throw new RuntimeException('in a closure');\n};",
            "\$a = 1;\n\$b = 2;\n\$f();",
        ]);

        expect($traceback)->toBe([
            'RuntimeException: in a closure',
            '  in a closure',
            '  at Cell 2, line 3',
        ]);
    });

    it('never shows the closure\'s raw name, whatever this PHP calls it', function () {
        $joined = implode("\n", tracebackOf([
            "\$f = function () {\n    throw new RuntimeException('x');\n};",
            '$f();',
        ]));

        expect($joined)->not->toContain('{closure')
            ->and($joined)->not->toContain("eval()'d code")
            ->and($joined)->not->toContain('Juphyter\\Engine\\Executor')
            ->and($joined)->not->toContain(dirname(__DIR__, 3));
    });

    it('treats a closure declared and called in one cell the same way', function () {
        $traceback = tracebackOf([
            "\$f = function () {\n    throw new RuntimeException('same cell');\n};\n\$f();",
        ]);

        expect($traceback)->toBe([
            'RuntimeException: same cell',
            '  in a closure',
            '  at Cell 1, line 4',
        ]);
    });

    it('treats an arrow function the same way', function () {
        $traceback = tracebackOf(["\$g = fn () => throw new RuntimeException('arrow');\n\$g();"]);

        expect($traceback[1])->toBe('  in a closure')
            ->and($traceback[2])->toBe('  at Cell 1, line 2');
    });

    it('still places the cell frame that called the closure', function () {
        $traceback = tracebackOf([
            "\$f = function () {\n    throw new RuntimeException('x');\n};",
            "\$a = 1;\n\$b = 2;\n\$c = 3;\n\$f();",
        ]);

        expect(end($traceback))->toBe('  at Cell 2, line 4');
    });
});

describe('an interrupt', function () {
    it('reports KeyboardInterrupt rather than the exception class', function () {
        $traceback = (new Traceback())->format(
            new Interrupt('Execution interrupted.'),
            (new Cleaner())->clean('$x = 1;', new Session()),
            3,
        );

        expect($traceback[0])->toBe(Interrupt::ENAME.': Execution interrupted.');
    });

    it('does not show the kernel\'s own signal handler or its path', function () {
        // The handler that throws lives in src/Engine/Interrupt.php, and a
        // frame from a real file inside src/Engine is the kernel's machinery
        // rather than the user's code.
        $thrown = new Interrupt('Execution interrupted.');
        $traceback = (new Traceback())->format(
            $thrown,
            (new Cleaner())->clean('$x = 1;', new Session()),
            2,
        );

        $joined = implode("\n", $traceback);

        expect($joined)->not->toContain('Interrupt.php')
            ->and($joined)->not->toContain('Executor.php')
            ->and($joined)->not->toContain(dirname(__DIR__, 3).'/src');
    });
});

describe('a refused cell', function () {
    it('reads as the refusal and the line the user wrote', function () {
        $traceback = (new Traceback())->forRefusal(
            CellError::parseError('Syntax error, unexpected EOF', 2),
            6,
        );

        expect($traceback)->toBe([
            'ParseError: Syntax error, unexpected EOF',
            '  at Cell 6, line 2',
        ]);
    });

    it('omits the location when the refusal has no line', function () {
        $traceback = (new Traceback())->forRefusal(CellError::unsupported('No line here'), 1);

        expect($traceback)->toBe(['UnsupportedSyntax: No line here']);
    });
});

describe('describing one location', function () {
    it('returns null for a file that is not evaluated code', function () {
        $cell = (new Cleaner())->clean('$x = 1;', new Session());

        expect((new Traceback())->describe('/app/src/Thing.php', 12, $cell, 2))->toBeNull();
    });

    it('recognises evaluated code by its file name', function () {
        expect(Traceback::isEvaluated("/app/src/Engine/Executor.php(265) : eval()'d code"))->toBeTrue()
            ->and(Traceback::isEvaluated('/app/src/Engine/Executor.php'))->toBeFalse();
    });

    it('maps a line through the running cell when nothing else claims it', function () {
        $session = new Session();
        $cleaner = new Cleaner();
        $cleaner->clean('use ArrayObject as F;', $session);
        $cell = $cleaner->clean('$x = 1;', $session);

        // Prelude of two lines, so source line 5 is cell line 3.
        expect((new Traceback())->describe("x.php(1) : eval()'d code", 5, $cell, 4))
            ->toBe('Cell 4, line 3');
    });

    it('is what a warning\'s location comes from', function () {
        $events = [];
        $executor = new Executor(function (array $event) use (&$events): void {
            $events[] = $event;
        });

        $executor->execute('use ArrayObject as G;', 1);
        $executor->execute("\$a = 1;\n\$b = \$missingHere;", 8);

        $streams = array_values(array_filter($events, fn (array $e) => $e['type'] === 'stream'));

        expect($streams)->toHaveCount(1)
            ->and($streams[0]['text'])->toContain('in Cell 8, line 2');
    });
});
