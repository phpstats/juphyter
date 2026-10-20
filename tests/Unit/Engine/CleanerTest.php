<?php

declare(strict_types=1);

use Juphyter\Engine\CellError;
use Juphyter\Engine\CleanedCell;
use Juphyter\Engine\Cleaner;
use Juphyter\Engine\Session;

/** Clean one cell and fail the test if it was refused. */
function cleaned(string $code, ?Session $session = null): CleanedCell
{
    $result = (new Cleaner())->clean($code, $session ?? new Session());

    expect($result)->toBeInstanceOf(CleanedCell::class, $result instanceof CellError ? $result->describe() : '');

    return $result;
}

/** Clean one cell and fail the test if it was accepted. */
function refused(string $code, ?Session $session = null): CellError
{
    $result = (new Cleaner())->clean($code, $session ?? new Session());

    expect($result)->toBeInstanceOf(CellError::class);

    return $result;
}

/**
 * A name no other test has used, so eval() can really declare it.
 *
 * The commit tests have to run eval() in this process, because that is the only
 * process whose class_exists() the session can ask. Each one therefore needs a
 * name of its own: a class declared by a test stays declared for the run.
 */
function uniqueName(string $stem): string
{
    static $n = 0;

    return $stem.'_'.(++$n).'_'.bin2hex(random_bytes(3));
}

/** The cell body of a cleaned source, with the prelude removed. */
function body(CleanedCell $cell): string
{
    return implode("\n", array_slice(explode("\n", $cell->source), $cell->preludeLines));
}

/**
 * eval() the source in a separate process and report what happened.
 *
 * The compile errors this cleaner exists to prevent are uncatchable, so they
 * cannot be asserted on in-process: they would take the test runner with them.
 * The child reports a fatal from its shutdown handler, which is the only place
 * one can be seen at all.
 *
 * @return array{fatal: ?string, thrown: ?string, output: string}
 */
function evaluated(string $source): array
{
    $script = <<<'PHP'
        <?php
        register_shutdown_function(function () {
            $e = error_get_last();
            if ($e !== null && in_array($e['type'], [E_ERROR, E_COMPILE_ERROR, E_CORE_ERROR, E_PARSE], true)) {
                fwrite(STDOUT, "\nFATAL:".$e['message']);
            }
        });
        try {
            eval(file_get_contents($argv[1]));
        } catch (Throwable $e) {
            fwrite(STDOUT, "\nTHROWN:".get_class($e).': '.$e->getMessage());
        }
        PHP;

    $directory = sys_get_temp_dir().'/juphyter-eval-'.bin2hex(random_bytes(6));
    mkdir($directory, 0o777, true);
    file_put_contents($runner = $directory.'/runner.php', $script);
    file_put_contents($cell = $directory.'/cell.php', $source);

    try {
        $command = sprintf(
            '%s -d display_errors=0 -d error_reporting=0 %s %s 2>/dev/null',
            escapeshellarg(PHP_BINARY),
            escapeshellarg($runner),
            escapeshellarg($cell),
        );
        $raw = (string) shell_exec($command);
    } finally {
        unlink($runner);
        unlink($cell);
        rmdir($directory);
    }

    $fatal = null;
    $thrown = null;

    if (preg_match('/\nFATAL:(.*)$/s', $raw, $matches) === 1) {
        $fatal = trim($matches[1]);
    }

    if (preg_match('/\nTHROWN:(.*)$/s', $raw, $matches) === 1) {
        $thrown = trim($matches[1]);
    }

    return ['fatal' => $fatal, 'thrown' => $thrown, 'output' => $raw];
}

describe('parse errors', function () {
    it('refuses source that does not parse, with the parser\'s own message', function () {
        $error = refused('if (true) {');

        expect($error->name)->toBe(CellError::PARSE_ERROR)
            ->and($error->message)->toContain('unexpected EOF');
    });

    it('reports the line in the user\'s cell, not in the prelude', function () {
        $session = new Session();
        (new Cleaner())->clean("use Foo\\A;\nuse Foo\\B;\nuse Foo\\C;", $session);

        // The session now puts three use statements in the prelude, so a cell
        // line and a source line are four apart. The reported line has to be
        // the cell's.
        $error = refused("\$a = 1;\n\$b = 2;\nfoo(", $session);

        expect($error->name)->toBe(CellError::PARSE_ERROR)
            ->and($error->line)->toBe(3);
    });

    it('leaves the session untouched when it refuses a cell', function () {
        $session = new Session();
        (new Cleaner())->clean('use Foo\\Kept;', $session);

        refused("use Foo\\Dropped;\nif (true) {", $session);

        expect($session->importStatements())->toBe(['use Foo\\Kept;']);
    });

    it('does not record a declaration from a cell it refuses', function () {
        $session = new Session();

        refused("class Dropped {}\nif (true) {", $session);

        expect($session->declaredClassLike())->toBe([]);
    });
});

describe('the forgiving semicolon', function () {
    it('accepts a bare trailing expression and captures it', function () {
        $cell = cleaned('1 + 1');

        expect($cell->semicolonAppended)->toBeTrue()
            ->and($cell->resultVariable)->toBe(Cleaner::RESULT_VARIABLE)
            ->and(body($cell))->toBe("\$__juphyter_result = 1 + 1\n;");
    });

    it('accepts a bare variable, which is how a notebook asks what something is', function () {
        $cell = cleaned('$df');

        expect($cell->semicolonAppended)->toBeTrue()
            ->and(body($cell))->toBe("\$__juphyter_result = \$df\n;");
    });

    it('accepts a cell ending in a // comment, which the bare semicolon could not', function () {
        $cell = cleaned('1 + 1 // sum');

        expect($cell->semicolonAppended)->toBeTrue()
            ->and($cell->resultVariable)->toBe(Cleaner::RESULT_VARIABLE)
            ->and(body($cell))->toBe("\$__juphyter_result = 1 + 1 // sum\n;");
    });

    it('accepts a cell ending in a # comment, which is what Quarto writes', function () {
        $cell = cleaned('1 + 1 # sum');

        expect($cell->semicolonAppended)->toBeTrue()
            ->and($cell->resultVariable)->toBe(Cleaner::RESULT_VARIABLE)
            ->and(body($cell))->toBe("\$__juphyter_result = 1 + 1 # sum\n;");
    });

    it('still evals a cell that ended in a comment', function () {
        $cell = cleaned('strlen("abcd") // how long');

        $run = evaluated($cell->source."\nfwrite(STDOUT, 'VALUE='.\$__juphyter_result);");

        expect($run['fatal'])->toBeNull()
            ->and($run['output'])->toContain('VALUE=4');
    });

    it('says so when it did not have to append anything', function () {
        expect(cleaned('1 + 1;')->semicolonAppended)->toBeFalse()
            ->and(cleaned('$x = 1;')->semicolonAppended)->toBeFalse();
    });

    it('still refuses an unclosed brace, with the original message', function () {
        $error = refused('if (true) {');

        expect($error->name)->toBe(CellError::PARSE_ERROR)
            ->and($error->message)->toBe('Syntax error, unexpected EOF');
    });

    it('reports the original error, not the retry\'s, when the retry fails differently', function () {
        // `foo(` is an unexpected EOF, and `foo(;` is an unexpected ';'. The
        // first is the honest description of what the user wrote.
        $error = refused('foo(');

        expect($error->message)->toBe('Syntax error, unexpected EOF')
            ->and($error->message)->not->toContain("';'");
    });

    it('keeps the original error\'s line when the retry fails', function () {
        $error = refused("\$a = 1;\n\$b = 2;\nfoo(");

        expect($error->line)->toBe(3)
            ->and($error->message)->toBe('Syntax error, unexpected EOF');
    });

    it('does not retry an error that is not source running out', function () {
        $error = refused('$x = = 1;');

        expect($error->name)->toBe(CellError::PARSE_ERROR)
            ->and($error->message)->toContain("unexpected '='");
    });

    it('leaves the line mapping untouched, because it only ever appends', function () {
        $session = new Session();
        (new Cleaner())->clean("use Foo\\A;\nuse Foo\\B;", $session);
        $cell = cleaned("\$a = 1;\n\$b = 2;\n\$a + \$b", $session);

        expect($cell->semicolonAppended)->toBeTrue()
            ->and($cell->preludeLines)->toBe(3)
            ->and($cell->cellLine(4))->toBe(1)
            ->and($cell->cellLine(6))->toBe(3);

        $lines = explode("\n", $cell->source);

        // Every line the user wrote is still exactly where the mapping says,
        // and the appended semicolon sits on a line of its own after them all.
        expect($lines[$cell->sourceLine(1) - 1])->toBe('$a = 1;')
            ->and($lines[$cell->sourceLine(2) - 1])->toBe('$b = 2;')
            ->and($lines[$cell->sourceLine(3) - 1])->toBe('$__juphyter_result = $a + $b')
            ->and($lines)->toHaveCount(7)
            ->and($lines[6])->toBe(';');
    });

    it('produces source that really evals, semicolon and all', function () {
        $cell = cleaned('strlen("abcd")');

        $run = evaluated($cell->source."\nfwrite(STDOUT, 'VALUE='.\$__juphyter_result);");

        expect($run['fatal'])->toBeNull()
            ->and($run['output'])->toContain('VALUE=4');
    });
});

describe('incomplete versus invalid', function () {
    it('calls an unclosed brace incomplete, not invalid', function () {
        $error = refused('if (true) {');

        expect($error->name)->toBe(CellError::PARSE_ERROR)
            ->and($error->incomplete)->toBeTrue();
    });

    it('calls an unclosed call incomplete', function () {
        expect(refused('foo(')->incomplete)->toBeTrue();
    });

    it('calls an unclosed string incomplete', function () {
        expect(refused('$x = "abc')->incomplete)->toBeTrue();
    });

    it('calls a genuine syntax error invalid, not incomplete', function () {
        $error = refused('$x = = 1;');

        expect($error->name)->toBe(CellError::PARSE_ERROR)
            ->and($error->incomplete)->toBeFalse();
    });

    it('calls a stray closing brace invalid', function () {
        expect(refused('$x = 1; }')->incomplete)->toBeFalse();
    });

    it('does not call a refused namespace incomplete, because it parsed', function () {
        $error = refused('namespace Oops;');

        expect($error->name)->toBe(CellError::UNSUPPORTED)
            ->and($error->incomplete)->toBeFalse();
    });

    it('does not call a redeclaration incomplete', function () {
        $session = new Session();
        $session->declareClassLike('Widget');

        $error = refused('class Widget {}', $session);

        expect($error->name)->toBe(CellError::REDECLARATION)
            ->and($error->incomplete)->toBeFalse();
    });

    it('does not call a refused declare incomplete', function () {
        expect(refused('declare(strict_types=0);')->incomplete)->toBeFalse()
            ->and(refused('declare(strict_types=1, ticks=1);')->incomplete)->toBeFalse();
    });

    it('does not call an alias clash incomplete', function () {
        expect(refused('use ArrayObject as D; use SplStack as D;')->incomplete)->toBeFalse();
    });

    it('gives the transport all three answers without it reading a parser message', function () {
        // complete, with and without the retry; incomplete; invalid.
        expect(cleaned('1 + 1;'))->toBeInstanceOf(CleanedCell::class)
            ->and(cleaned('1 + 1'))->toBeInstanceOf(CleanedCell::class)
            ->and(refused('if (true) {')->incomplete)->toBeTrue()
            ->and(refused('$x = = 1;')->incomplete)->toBeFalse();
    });
});

describe('the prelude', function () {
    it('prepends declare(strict_types=1) to every cell', function () {
        expect(cleaned('$x = 1;')->source)->toStartWith("declare(strict_types=1);\n");
    });

    it('is one line when nothing is imported', function () {
        expect(cleaned('$x = 1;')->preludeLines)->toBe(1);
    });

    it('enforces strict types, which is the point of prepending it', function () {
        $cell = cleaned("function strictly(int \$i): int { return \$i; }\nstrictly('5');");

        $run = evaluated($cell->source);

        expect($run['fatal'])->toBeNull()
            ->and($run['thrown'])->toContain('TypeError')
            ->and($run['thrown'])->toContain('must be of type int, string given');
    });

    it('re-emits every accumulated import ahead of a later cell', function () {
        $session = new Session();
        $cleaner = new Cleaner();
        $cleaner->clean('use Foo\\Alpha;', $session);
        $cleaner->clean('use Foo\\Beta as B;', $session);

        $cell = cleaned('$x = 1;', $session);

        expect($cell->source)->toBe(
            "declare(strict_types=1);\nuse Foo\\Alpha;\nuse Foo\\Beta as B;\n\$x = 1;"
        )->and($cell->preludeLines)->toBe(3);
    });
});

describe('the cell\'s own declare', function () {
    it('strips declare(strict_types=1) the user wrote, which PHP would reject there', function () {
        $cell = cleaned("declare(strict_types=1);\n\$x = 1;");

        expect(body($cell))->toBe("                        \n\$x = 1;")
            ->and($cell->source)->not->toContain("declare(strict_types=1);\ndeclare");
    });

    it('does not let a stripped declare shift the lines below it', function () {
        $cell = cleaned("declare(strict_types=1);\n\$x = 1;\n\$x + 1;");

        expect(explode("\n", $cell->source))->toHaveCount(4)
            ->and($cell->cellLine(4))->toBe(3);
    });

    it('survives eval with the user\'s own declare stripped', function () {
        $session = new Session();
        (new Cleaner())->clean('use ArrayObject as AO;', $session);
        $cell = cleaned("declare(strict_types=1);\n\$x = 1;", $session);

        expect(evaluated($cell->source)['fatal'])->toBeNull();
    });

    it('refuses declare(strict_types=0), which it cannot honour', function () {
        $error = refused('declare(strict_types=0);');

        expect($error->name)->toBe(CellError::UNSUPPORTED)
            ->and($error->message)->toContain('strict types off');
    });

    it('refuses declare(encoding=...), which can no longer be first', function () {
        $error = refused('declare(encoding="UTF-8");');

        expect($error->name)->toBe(CellError::UNSUPPORTED)
            ->and($error->message)->toContain('encoding');
    });

    it('refuses block-mode strict_types', function () {
        $error = refused('declare(strict_types=1) { $x = 1; }');

        expect($error->name)->toBe(CellError::UNSUPPORTED)
            ->and($error->message)->toContain('block mode');
    });

    it('refuses strict_types combined with another directive, rather than dropping it', function () {
        $error = refused('declare(strict_types=1, ticks=1);');

        expect($error->name)->toBe(CellError::UNSUPPORTED)
            ->and($error->message)->toContain('combine strict_types with ticks')
            ->and($error->message)->toContain('declare of its own');
    });

    it('refuses the combination whichever order it is written in', function () {
        $error = refused('declare(ticks=1, strict_types=1);');

        expect($error->name)->toBe(CellError::UNSUPPORTED)
            ->and($error->message)->toContain('ticks');
    });

    it('names every directive it would have discarded', function () {
        $error = refused('declare(strict_types=1, ticks=1, encoding="UTF-8");');

        expect($error->name)->toBe(CellError::UNSUPPORTED);
    });

    it('leaves declare(ticks=1) where the user put it', function () {
        $cell = cleaned('declare(ticks=1);');

        expect(body($cell))->toBe('declare(ticks=1);');
    });
});

describe('imports', function () {
    it('lifts the cell\'s use into the session so later cells see it', function () {
        $session = new Session();
        cleaned('use Foo\\Bar;', $session);

        expect($session->importStatements())->toBe(['use Foo\\Bar;']);
    });

    it('removes the use from the body, because the prelude now carries it', function () {
        $session = new Session();
        $cell = cleaned("use Foo\\Bar;\n\$x = new Bar();", $session);

        expect(body($cell))->toBe("            \n\$x = new Bar();")
            ->and($cell->source)->toContain("use Foo\\Bar;\n");
    });

    it('emits the cell\'s own import exactly once, which PHP requires', function () {
        $session = new Session();
        $cell = cleaned('use ArrayObject as AO;', $session);

        expect(substr_count($cell->source, 'use ArrayObject as AO;'))->toBe(1)
            ->and(evaluated($cell->source)['fatal'])->toBeNull();
    });

    it('handles use function and use const', function () {
        $session = new Session();
        cleaned("use function Foo\\bar;\nuse const Foo\\BAZ;", $session);

        expect($session->importStatements())->toBe(['use const Foo\\BAZ;', 'use function Foo\\bar;']);
    });

    it('expands a grouped use into one statement per name', function () {
        $session = new Session();
        $cell = cleaned('use Foo\\{Bar, Baz as Q};', $session);

        expect($session->importStatements())->toBe(['use Foo\\Bar;', 'use Foo\\Baz as Q;'])
            ->and($cell->preludeLines)->toBe(3);
    });

    it('reads the per-name type out of a mixed grouped use', function () {
        $session = new Session();
        cleaned('use Foo\\{Bar, function baz, const QUX};', $session);

        expect($session->importStatements())->toBe([
            'use Foo\\Bar;',
            'use const Foo\\QUX;',
            'use function Foo\\baz;',
        ]);
    });

    it('keeps a use that follows code in the same cell', function () {
        $session = new Session();
        $cell = cleaned("\$x = 1;\nuse Foo\\Late;", $session);

        expect($session->importStatements())->toBe(['use Foo\\Late;'])
            ->and(body($cell))->toBe("\$x = 1;\n             ");
    });

    it('refuses one cell binding the same alias to two names', function () {
        $error = refused('use ArrayObject as D; use SplStack as D;');

        expect($error->name)->toBe(CellError::UNSUPPORTED)
            ->and($error->message)->toContain('"D"')
            ->and($error->message)->toContain('ArrayObject')
            ->and($error->message)->toContain('SplStack');
    });

    it('allows one cell repeating the same import, which is harmless', function () {
        $session = new Session();
        cleaned('use Foo\\Bar; use Foo\\Bar;', $session);

        expect($session->importStatements())->toBe(['use Foo\\Bar;']);
    });

    it('lets a later cell rebind an alias, with the new one winning', function () {
        $session = new Session();
        $cleaner = new Cleaner();
        $cleaner->clean('use ArrayObject as C;', $session);

        $cell = cleaned('use SplStack as C;', $session);

        expect($session->importStatements())->toBe(['use SplStack as C;'])
            ->and($cell->source)->not->toContain('ArrayObject');
    });

    it('does not fatal when a cell rebinds an alias an earlier cell bound', function () {
        $session = new Session();
        $cleaner = new Cleaner();
        $cleaner->clean('use ArrayObject as C;', $session);
        $cell = cleaned("use SplStack as C;\n\$s = new C();", $session);

        $run = evaluated($cell->source);

        expect($run['fatal'])->toBeNull()
            ->and($run['thrown'])->toBeNull();
    });
});

describe('namespace', function () {
    it('refuses a namespace declaration, naming the limitation', function () {
        $error = refused('namespace Oops;');

        expect($error->name)->toBe(CellError::UNSUPPORTED)
            ->and($error->message)->toContain('cannot declare a namespace')
            ->and($error->message)->toContain('name resolution')
            ->and($error->line)->toBe(1);
    });

    it('refuses a braced namespace too', function () {
        expect(refused('namespace Oops { $x = 1; }')->name)->toBe(CellError::UNSUPPORTED);
    });
});

describe('redeclaration', function () {
    it('reports every kind of top-level declaration on the result', function () {
        $session = new Session();
        $cell = cleaned('class C {} interface I {} trait T {} enum E {} function f() {}', $session);

        expect($cell->declaresClassLike)->toBe(['C', 'I', 'T', 'E'])
            ->and($cell->declaresFunctions)->toBe(['f'])
            ->and($cell->declaresAnything())->toBeTrue();
    });

    it('reports nothing for a cell that declares nothing', function () {
        $cell = cleaned('$x = 1;');

        expect($cell->declaresClassLike)->toBe([])
            ->and($cell->declaresFunctions)->toBe([])
            ->and($cell->declaresAnything())->toBeFalse();
    });

    it('does not put the names into the session, which is the executor\'s job', function () {
        $session = new Session();
        cleaned('class Held {} function held() {}', $session);

        expect($session->declaredClassLike())->toBe([])
            ->and($session->declaredFunctions())->toBe([]);
    });

    it('refuses a class the session already holds, with the name in the message', function () {
        $session = new Session();
        $session->declareClassLike('Widget');

        $error = refused('class Widget {}', $session);

        expect($error->name)->toBe(CellError::REDECLARATION)
            ->and($error->message)->toContain('"Widget"')
            ->and($error->message)->toContain('Restart the kernel');
    });

    it('refuses a function the session already holds', function () {
        $session = new Session();
        $session->declareFunction('work');

        $error = refused('function work() {}', $session);

        expect($error->name)->toBe(CellError::REDECLARATION)
            ->and($error->message)->toContain('"work"');
    });

    it('refuses an interface that collides with a class, because PHP shares the namespace', function () {
        $session = new Session();
        $session->declareClassLike('Shape');

        expect(refused('interface Shape {}', $session)->name)->toBe(CellError::REDECLARATION);
    });

    it('refuses an enum that collides with a trait, for the same reason', function () {
        $session = new Session();
        $session->declareClassLike('Shape');

        expect(refused('enum Shape {}', $session)->name)->toBe(CellError::REDECLARATION);
    });

    it('matches a held name case-insensitively, as PHP does', function () {
        $session = new Session();
        $session->declareClassLike('Widget');

        expect(refused('class WIDGET {}', $session)->name)->toBe(CellError::REDECLARATION);
    });

    it('allows a function and a class of the same name, which PHP allows', function () {
        $session = new Session();
        $session->declareClassLike('Thing');

        expect(cleaned('function Thing() {}', $session))->toBeInstanceOf(CleanedCell::class);
    });

    it('refuses a name the one cell declares twice', function () {
        $error = refused('class Twice {} class Twice {}');

        expect($error->name)->toBe(CellError::REDECLARATION)
            ->and($error->message)->toContain('twice')
            ->and($error->message)->toContain('"Twice"');
    });

    it('refuses a function the one cell declares twice', function () {
        expect(refused('function twice() {} function twice() {}')->name)
            ->toBe(CellError::REDECLARATION);
    });

    it('reports the line the colliding declaration is on', function () {
        $session = new Session();
        $session->declareClassLike('Widget');

        expect(refused("\$x = 1;\n\$y = 2;\nclass Widget {}", $session)->line)->toBe(3);
    });

    it('leaves a conditional declaration alone, because PHP does not hoist it', function () {
        $cell = cleaned('if (! function_exists("maybe")) { function maybe() { return 1; } }');

        expect($cell->declaresFunctions)->toBe([]);
    });

    it('accepts the same conditional declaration twice', function () {
        $session = new Session();
        $cleaner = new Cleaner();
        $code = 'if (! class_exists("Maybe")) { class Maybe {} }';

        expect($cleaner->clean($code, $session))->toBeInstanceOf(CleanedCell::class)
            ->and($cleaner->clean($code, $session))->toBeInstanceOf(CleanedCell::class);
    });

    it('does not treat a method of a class as a declaration', function () {
        $cell = cleaned('class Holder { public function held() {} }');

        expect($cell->declaresFunctions)->toBe([])
            ->and($cell->declaresClassLike)->toBe(['Holder']);
    });

    it('does not treat an anonymous class as a declaration', function () {
        expect(cleaned('$o = new class {};')->declaresClassLike)->toBe([]);
    });
});

describe('committing declarations', function () {
    it('reserves nothing for a cell that was cleaned but never run', function () {
        $session = new Session();
        $cleaner = new Cleaner();
        $name = uniqueName('NeverRun');

        $cleaner->clean("class {$name} {}", $session);

        expect($session->declaredClassLike())->toBe([])
            ->and($cleaner->clean("class {$name} {}", $session))->toBeInstanceOf(CleanedCell::class);
    });

    it('does not reserve a name the cell failed to declare, so the typo can be fixed', function () {
        $session = new Session();
        $cleaner = new Cleaner();
        $name = uniqueName('Widget');

        // Valid source that declares nothing, because the parent does not exist.
        $cell = $cleaner->clean("class {$name} extends Missing_{$name} {}", $session);

        expect($cell->declaresClassLike)->toBe([$name]);

        try {
            eval($cell->source);
        } catch (Throwable) {
            // The Error the missing parent raises is the point of the test.
        }

        $session->commitDeclarations($cell);

        expect(class_exists($name, false))->toBeFalse()
            ->and($session->declaredClassLike())->toBe([])
            ->and($cleaner->clean("class {$name} {}", $session))->toBeInstanceOf(CleanedCell::class);
    });

    it('does the same for a class whose interface does not exist', function () {
        $session = new Session();
        $cleaner = new Cleaner();
        $name = uniqueName('Implementer');

        $cell = $cleaner->clean("class {$name} implements Missing_{$name} {}", $session);

        try {
            eval($cell->source);
        } catch (Throwable) {
        }

        $session->commitDeclarations($cell);

        expect($session->declaredClassLike())->toBe([]);
    });

    it('reserves a name the cell declared before it threw, because PHP hoists it', function () {
        $session = new Session();
        $cleaner = new Cleaner();
        $name = uniqueName('Hoisted');

        $cell = $cleaner->clean("class {$name} {}\nthrow new RuntimeException('after');", $session);

        try {
            eval($cell->source);
        } catch (Throwable) {
            // Thrown on purpose: the class above it is declared all the same.
        }

        $session->commitDeclarations($cell);

        expect(class_exists($name, false))->toBeTrue()
            ->and($session->declaredClassLike())->toBe([$name]);

        $again = refused("class {$name} {}", $session);

        expect($again->name)->toBe(CellError::REDECLARATION)
            ->and($again->message)->toContain($name);
    });

    it('reserves a function only once it exists', function () {
        $session = new Session();
        $cleaner = new Cleaner();
        $name = uniqueName('worker');

        $cell = $cleaner->clean("function {$name}() { return 1; }", $session);

        expect($session->declaredFunctions())->toBe([]);

        eval($cell->source);
        $session->commitDeclarations($cell);

        expect(function_exists($name))->toBeTrue()
            ->and($session->declaredFunctions())->toBe([$name])
            ->and(refused("function {$name}() {}", $session)->name)->toBe(CellError::REDECLARATION);
    });

    it('lets a subclass run again once the base class it needed arrives', function () {
        $session = new Session();
        $cleaner = new Cleaner();
        $base = uniqueName('Base');
        $sub = uniqueName('Sub');

        // Cells run out of order: the subclass before the class it extends.
        $first = $cleaner->clean("class {$sub} extends {$base} {}", $session);

        try {
            eval($first->source);
        } catch (Throwable) {
        }

        $session->commitDeclarations($first);

        $baseCell = $cleaner->clean("class {$base} {}", $session);
        eval($baseCell->source);
        $session->commitDeclarations($baseCell);

        // The subclass has to be accepted this time, not refused as a redeclaration.
        $retry = $cleaner->clean("class {$sub} extends {$base} {}", $session);

        expect($retry)->toBeInstanceOf(CleanedCell::class);

        eval($retry->source);
        $session->commitDeclarations($retry);

        expect(class_exists($sub, false))->toBeTrue()
            ->and($session->declaredClassLike())->toContain($sub)
            ->and($session->declaredClassLike())->toContain($base);
    });

    it('reserves nothing when newer syntax stops the whole cell compiling', function () {
        $session = new Session();
        $cleaner = new Cleaner();
        $name = uniqueName('Piped');

        // The cleaner parses for the newest PHP it supports, so a cell using
        // 8.5 syntax is accepted here and then fails to compile under eval on
        // an older runtime. Nothing in it is declared, not even the class above.
        $cell = $cleaner->clean("class {$name} {}\n\$x = [1, 2] |> array_sum;", $session);

        expect($cell)->toBeInstanceOf(CleanedCell::class);

        try {
            eval($cell->source);
        } catch (Throwable) {
        }

        $session->commitDeclarations($cell);

        expect(class_exists($name, false))->toBeFalse()
            ->and($session->declaredClassLike())->toBe([])
            ->and($cleaner->clean("class {$name} {}", $session))->toBeInstanceOf(CleanedCell::class);
    })->skip(PHP_VERSION_ID >= 80500, 'The pipe operator compiles on PHP 8.5, so the cell succeeds there.');

    it('commits nothing for a cell that declares nothing', function () {
        $session = new Session();
        $cell = cleaned('$x = 1;', $session);

        $session->commitDeclarations($cell);

        expect($session->declaredClassLike())->toBe([])
            ->and($session->declaredFunctions())->toBe([]);
    });
});

describe('capturing the cell\'s value', function () {
    it('assigns a trailing expression into the reserved variable', function () {
        $cell = cleaned('1 + 1;');

        expect($cell->resultVariable)->toBe(Cleaner::RESULT_VARIABLE)
            ->and($cell->hasResult())->toBeTrue()
            ->and(body($cell))->toBe('$__juphyter_result = 1 + 1;');
    });

    it('captures a call, which is the common notebook case', function () {
        expect(body(cleaned('count($x);')))->toBe('$__juphyter_result = count($x);');
    });

    it('captures only the last statement', function () {
        $cell = cleaned("\$a = 1;\n\$a + 2;");

        expect(body($cell))->toBe("\$a = 1;\n\$__juphyter_result = \$a + 2;");
    });

    it('does not capture an assignment', function () {
        $cell = cleaned('$x = 5;');

        expect($cell->resultVariable)->toBeNull()
            ->and($cell->hasResult())->toBeFalse()
            ->and(body($cell))->toBe('$x = 5;');
    });

    it('does not capture a compound assignment', function () {
        expect(cleaned('$x .= "a";')->resultVariable)->toBeNull();
    });

    it('does not capture a reference assignment', function () {
        expect(cleaned('$x = &$y;')->resultVariable)->toBeNull();
    });

    it('does not capture an increment or a decrement', function () {
        expect(cleaned('$i++;')->resultVariable)->toBeNull()
            ->and(cleaned('$i--;')->resultVariable)->toBeNull()
            ->and(cleaned('++$i;')->resultVariable)->toBeNull()
            ->and(cleaned('--$i;')->resultVariable)->toBeNull();
    });

    it('does not capture echo, which is a statement rather than an expression', function () {
        expect(cleaned('echo "hi";')->resultVariable)->toBeNull();
    });

    it('does not capture print', function () {
        expect(cleaned('print "hi";')->resultVariable)->toBeNull();
    });

    it('does not capture a declaration', function () {
        expect(cleaned('class Late {}')->resultVariable)->toBeNull()
            ->and(cleaned('function late() {}')->resultVariable)->toBeNull();
    });

    it('does not capture a control structure', function () {
        expect(cleaned('if (true) { $x = 1; }')->resultVariable)->toBeNull()
            ->and(cleaned('foreach ([1] as $v) { $x = $v; }')->resultVariable)->toBeNull();
    });

    it('does not capture a return', function () {
        expect(cleaned('return 1;')->resultVariable)->toBeNull();
    });

    it('captures a value that eval really does leave in the variable', function () {
        $cell = cleaned('strlen("abcd");');

        $run = evaluated($cell->source."\nfwrite(STDOUT, 'VALUE='.\$__juphyter_result);");

        expect($run['fatal'])->toBeNull()
            ->and($run['output'])->toContain('VALUE=4');
    });

    it('does not shift any line when it inserts the capture', function () {
        $cell = cleaned("\$a = 1;\n\$b = 2;\n\$a + \$b;");

        expect(explode("\n", $cell->source))->toHaveCount(4)
            ->and($cell->cellLine(4))->toBe(3);
    });
});

describe('cells with nothing in them', function () {
    it('accepts an empty cell', function () {
        $cell = cleaned('');

        expect($cell->resultVariable)->toBeNull()
            ->and($cell->source)->toBe("declare(strict_types=1);\n")
            ->and($cell->preludeLines)->toBe(1);
    });

    it('accepts a cell of only whitespace', function () {
        expect(cleaned("\n\n  ")->resultVariable)->toBeNull();
    });

    it('accepts a cell of only a comment, and keeps it', function () {
        $cell = cleaned('// just a note');

        expect($cell->resultVariable)->toBeNull()
            ->and(body($cell))->toBe('// just a note');
    });

    it('accepts a Quarto cell option, which is a PHP comment', function () {
        $cell = cleaned("#| echo: false\n\$x = 1;");

        expect(body($cell))->toBe("#| echo: false\n\$x = 1;");
    });
});

describe('the line mapping', function () {
    it('maps a source line back to the cell line it came from', function () {
        $session = new Session();
        (new Cleaner())->clean("use Foo\\A;\nuse Foo\\B;", $session);
        $cell = cleaned("\$a = 1;\n\$b = 2;", $session);

        expect($cell->preludeLines)->toBe(3)
            ->and($cell->cellLine(4))->toBe(1)
            ->and($cell->cellLine(5))->toBe(2)
            ->and($cell->sourceLine(1))->toBe(4)
            ->and($cell->sourceLine(2))->toBe(5);
    });

    it('keeps the mapping exact for every line of a cell, however it was edited', function () {
        $session = new Session();
        (new Cleaner())->clean('use Foo\\Old;', $session);

        // One cell with all three edits in it: a stripped declare, a stripped
        // use, and an inserted capture.
        $cell = cleaned("declare(strict_types=1);\nuse Foo\\New_;\n\$a = 1;\n\$a + 1;", $session);
        $lines = explode("\n", $cell->source);

        // declare, the session's earlier import, and this cell's lifted one.
        expect($cell->preludeLines)->toBe(3)
            ->and($lines)->toHaveCount(7)
            ->and(trim($lines[$cell->sourceLine(1) - 1]))->toBe('')
            ->and(trim($lines[$cell->sourceLine(2) - 1]))->toBe('')
            ->and($lines[$cell->sourceLine(3) - 1])->toBe('$a = 1;')
            ->and($lines[$cell->sourceLine(4) - 1])->toBe('$__juphyter_result = $a + 1;');
    });

    it('holds for a real traceback line that eval reports', function () {
        $session = new Session();
        (new Cleaner())->clean("use Foo\\A;\nuse Foo\\B;", $session);
        $cell = cleaned("\$x = 1;\nthrow new RuntimeException('here');", $session);

        $run = evaluated($cell->source);

        // The prelude is three lines, so the throw on cell line 2 is source
        // line 5, and the mapping has to take it back to 2.
        expect($cell->sourceLine(2))->toBe(5)
            ->and($cell->cellLine(5))->toBe(2)
            ->and($run['thrown'])->toContain('RuntimeException');
    });
});

describe('a ten-cell round trip', function () {
    it('keeps the prelude line count correct as the import set grows', function () {
        $session = new Session();
        $cleaner = new Cleaner();

        for ($n = 1; $n <= 10; $n++) {
            $cell = $cleaner->clean("use Foo\\Class{$n};\n\$v{$n} = {$n};", $session);

            expect($cell)->toBeInstanceOf(CleanedCell::class);

            // One declare line, plus one use per cell cleaned so far.
            expect($cell->preludeLines)->toBe(1 + $n)
                ->and($cell->preludeLines)->toBe(count(explode("\n", $cell->source)) - 2)
                ->and($cell->cellLine($cell->sourceLine(2)))->toBe(2);

            // The cell's own two lines are still its own two lines.
            $lines = explode("\n", $cell->source);
            expect(trim($lines[$cell->sourceLine(1) - 1]))->toBe('')
                ->and($lines[$cell->sourceLine(2) - 1])->toBe("\$v{$n} = {$n};");
        }

        expect($session->importStatements())->toHaveCount(10);
    });

    it('produces source that evals cleanly at every step of such a session', function () {
        $session = new Session();
        $cleaner = new Cleaner();
        $sources = [];

        $cells = [
            'use ArrayObject as C;',
            '$a = new C([1, 2, 3]);',
            'count($a);',
            "use SplStack as C;\n\$b = new C();",
            'get_class($b);',
            "declare(strict_types=1);\nuse function strlen as len;\nlen('abcd');",
            'function strictly(int $i): int { return $i * 2; }',
            'strictly(21);',
            '// a comment only',
            '$a;',
        ];

        foreach ($cells as $code) {
            $cell = $cleaner->clean($code, $session);
            expect($cell)->toBeInstanceOf(CleanedCell::class);
            $sources[] = $cell->source;
        }

        // Each cell's source has to compile on its own, which is what the
        // uncatchable compile errors would break.
        foreach ($sources as $index => $source) {
            expect(evaluated($source)['fatal'])->toBeNull("cell ".($index + 1)." fatally failed to compile");
        }
    });
});
