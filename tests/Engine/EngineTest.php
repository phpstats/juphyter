<?php

declare(strict_types=1);

use Juphyter\Engine\Interrupt;
use Juphyter\Protocol\Event;
use Juphyter\Tests\Engine\Harness;

/**
 * The engine over a real socket, driven the way the Python transport will drive
 * it. Every test here spawns a process and talks to it.
 */

/**
 * The engine for one test, stopped afterwards however the test ends.
 *
 * The started harnesses are held in a global rather than on the test case,
 * because a test's properties are reached through a Pest proxy that cannot be
 * appended to: `test()->harnesses[] = ...` raises "indirect modification ...
 * has no effect" and silently keeps nothing, which left an engine process
 * running for every test in the file.
 */
function engine(?string $directory = null, array $environment = [], array $arguments = []): Harness
{
    $harness = Harness::start($directory, $environment, $arguments);
    $GLOBALS['juphyter_harnesses'][] = $harness;

    return $harness;
}

beforeEach(function () {
    $GLOBALS['juphyter_harnesses'] = [];
});

afterEach(function () {
    foreach ($GLOBALS['juphyter_harnesses'] ?? [] as $harness) {
        $harness->stop();
    }

    $GLOBALS['juphyter_harnesses'] = [];
});

/** The types of a run's events, in order. */
function kinds(array $events): array
{
    return array_map(fn (array $e) => $e['type'], $events);
}

/** The events of one type. */
function only(array $events, string $type): array
{
    return array_values(array_filter($events, fn (array $e) => $e['type'] === $type));
}

describe('the connection', function () {
    it('connects to the socket named in JUPHYTER_SOCKET', function () {
        $engine = engine();

        expect(kinds($engine->request('kernel_info')))->toBe(['kernel_info_reply']);
    });

    it('keeps its own stdout and stderr clear of the protocol', function () {
        $engine = engine();
        $engine->request('execute', ['code' => 'echo "printed";']);
        $engine->request('shutdown');
        $engine->wait();

        expect($engine->stdout())->toBe('')
            ->and($engine->stderr())->toBe('');
    });

    it('exits 0 when the transport closes the channel without asking', function () {
        $engine = engine();
        $engine->request('kernel_info');
        $engine->disconnect();

        expect($engine->wait())->toBe(0);
    });
});

describe('every request ends with one terminal event', function () {
    it('ends a cell with nothing to show in a single ok', function () {
        $engine = engine();
        $events = $engine->request('execute', ['code' => '$x = 1;']);

        expect(kinds($events))->toBe(['ok'])
            ->and($events[0]['id'])->toBe('r1');
    });

    it('ends a cell with a value in result then ok', function () {
        $engine = engine();

        expect(kinds($engine->request('execute', ['code' => '1 + 1;'])))->toBe(['result', 'ok']);
    });

    it('ends a thrown cell in error, with no ok', function () {
        $engine = engine();
        $events = $engine->request('execute', ['code' => 'throw new RuntimeException("boom");']);

        expect(kinds($events))->toBe(['error'])
            ->and($events[0]['ename'])->toBe('RuntimeException')
            ->and($events[0]['evalue'])->toBe('boom')
            ->and($events[0]['traceback'][0])->toBe('RuntimeException: boom');
    });

    it('answers each other request with its matching reply', function () {
        $engine = engine();

        expect(kinds($engine->request('complete', ['code' => '$x', 'cursor_pos' => 2])))->toBe(['complete_reply'])
            ->and(kinds($engine->request('is_complete', ['code' => '1;'])))->toBe(['is_complete_reply'])
            ->and(kinds($engine->request('kernel_info')))->toBe(['kernel_info_reply']);
    });

    it('tags every event with the id of the request it answers', function () {
        $engine = engine();
        $id = $engine->send('execute', ['code' => 'echo "out"; 2;']);
        $events = $engine->collect($id);

        expect(kinds($events))->toBe(['stream', 'result', 'ok']);

        foreach ($events as $event) {
            expect($event['id'])->toBe($id);
        }
    });
});

describe('a session across requests', function () {
    it('carries state from one cell to the next', function () {
        $engine = engine();

        $engine->request('execute', ['code' => '$total = 10;']);
        $engine->request('execute', ['code' => '$total = $total * 4;']);
        $events = $engine->request('execute', ['code' => '$total + 2;']);

        expect(only($events, 'result')[0]['bundle']['text/plain'])->toBe('42');
    });

    it('carries a declared function and an import across cells', function () {
        $engine = engine();

        $engine->request('execute', ['code' => 'use ArrayObject as Carried;']);
        $engine->request('execute', ['code' => 'function doubled(int $i): int { return $i * 2; }']);
        $events = $engine->request('execute', ['code' => 'doubled(count(new Carried([1, 2, 3])));']);

        expect(only($events, 'result')[0]['bundle']['text/plain'])->toBe('6');
    });

    it('refuses a redeclaration, so one session guards the whole process', function () {
        $engine = engine();

        $engine->request('execute', ['code' => 'class Once {}']);
        $events = $engine->request('execute', ['code' => 'class Once {}']);

        expect($events[0]['type'])->toBe('error')
            ->and($events[0]['ename'])->toBe('RedeclarationError');
    });
});

describe('streaming', function () {
    it('sends each print as its own event, in order, before the terminal one', function () {
        $engine = engine();
        $events = $engine->request('execute', ['code' => 'echo "one"; echo "two"; echo "three";']);

        expect(kinds($events))->toBe(['stream', 'stream', 'stream', 'ok'])
            ->and(array_column(only($events, 'stream'), 'text'))->toBe(['one', 'two', 'three']);
    });

    it('sends what a cell printed before the error that followed it', function () {
        $engine = engine();
        $events = $engine->request('execute', ['code' => 'echo "first"; throw new LogicException("then");']);

        expect(kinds($events))->toBe(['stream', 'error'])
            ->and($events[0]['text'])->toBe('first');
    });

    it('sends a warning on stderr rather than as an error', function () {
        $engine = engine();
        $events = $engine->request('execute', ['code' => '$y = $neverSet;']);

        expect(kinds($events))->toBe(['stream', 'ok'])
            ->and($events[0]['name'])->toBe('stderr')
            ->and($events[0]['text'])->toContain('Undefined variable $neverSet');
    });
});

describe('the execution count', function () {
    it('advances once per stored cell and matches what the front end shows', function () {
        $engine = engine();

        expect(only($engine->request('execute', ['code' => '1;']), 'ok')[0]['execution_count'])->toBe(1)
            ->and(only($engine->request('execute', ['code' => '2;']), 'ok')[0]['execution_count'])->toBe(2)
            ->and(only($engine->request('execute', ['code' => '3;']), 'ok')[0]['execution_count'])->toBe(3);
    });

    it('does not advance when store_history is false', function () {
        $engine = engine();
        $engine->request('execute', ['code' => '1;']);

        $unstored = $engine->request('execute', ['code' => '2;', 'store_history' => false]);
        $next = $engine->request('execute', ['code' => '3;']);

        expect(only($unstored, 'ok')[0]['execution_count'])->toBe(1)
            ->and(only($next, 'ok')[0]['execution_count'])->toBe(2);
    });

    it('reports the same count on result and ok', function () {
        $engine = engine();
        $events = $engine->request('execute', ['code' => '5;']);

        expect(only($events, 'result')[0]['execution_count'])->toBe(1)
            ->and(only($events, 'ok')[0]['execution_count'])->toBe(1);
    });

    it('advances for a cell that failed, as a notebook does', function () {
        $engine = engine();
        $engine->request('execute', ['code' => 'throw new RuntimeException("x");']);

        expect(only($engine->request('execute', ['code' => '1;']), 'ok')[0]['execution_count'])->toBe(2);
    });

    it('labels a traceback with the number the front end shows', function () {
        $engine = engine();
        $engine->request('execute', ['code' => '$a = 1;']);
        $events = $engine->request('execute', ['code' => "\$b = 2;\nthrow new RuntimeException('here');"]);

        expect($events[0]['traceback'])->toContain('  at Cell 2, line 2');
    });
});

describe('silent', function () {
    it('suppresses the result but not the terminal event', function () {
        $engine = engine();

        expect(kinds($engine->request('execute', ['code' => '42;', 'silent' => true])))->toBe(['ok']);
    });

    it('suppresses a display but not an error', function () {
        $engine = engine();

        expect(kinds($engine->request('execute', [
            'code' => 'Juphyter\display("hidden"); throw new RuntimeException("shown");',
            'silent' => true,
        ])))->toBe(['error']);
    });

    it('does not suppress stream output, which a cell asked for explicitly', function () {
        $engine = engine();

        expect(kinds($engine->request('execute', ['code' => 'echo "still printed";', 'silent' => true])))
            ->toBe(['stream', 'ok']);
    });

    it('applies to one request only', function () {
        $engine = engine();
        $engine->request('execute', ['code' => '1;', 'silent' => true]);

        expect(kinds($engine->request('execute', ['code' => '2;'])))->toBe(['result', 'ok']);
    });
});

describe('the display wiring', function () {
    it('turns a cell value into a result with a text/plain bundle', function () {
        $engine = engine();
        $events = $engine->request('execute', ['code' => '[1, 2, 3];']);

        expect(only($events, 'result')[0]['bundle'])->toHaveKey('text/plain');
    });

    it('publishes nothing extra for a cell that ends in display()', function () {
        $engine = engine();
        $events = $engine->request('execute', ['code' => 'Juphyter\display("shown");']);

        expect(kinds($events))->toBe(['display', 'ok'])
            ->and(only($events, 'result'))->toBe([]);
    });

    it('turns Juphyter\display() into a display event rather than stdout', function () {
        $engine = engine();
        $events = $engine->request('execute', ['code' => 'Juphyter\display("shown"); 1;']);

        expect(kinds($events))->toBe(['display', 'result', 'ok'])
            ->and(only($events, 'display')[0]['bundle'])->toHaveKey('text/plain')
            ->and(only($events, 'stream'))->toBe([]);
    });

    it('publishes several values in the order display() was given them', function () {
        $engine = engine();
        $events = $engine->request('execute', ['code' => 'Juphyter\display("first", "second");']);

        // display() returns void, so the cell's own value is null and no
        // result is published: the two display events are the whole output.
        expect(kinds($events))->toBe(['display', 'display', 'ok'])
            ->and(only($events, 'display')[0]['bundle']['text/plain'])->toContain('first')
            ->and(only($events, 'display')[1]['bundle']['text/plain'])->toContain('second')
            ->and(only($events, 'result'))->toBe([]);
    });

    it('gives a display event a metadata field, as the contract says', function () {
        $engine = engine();
        $events = $engine->request('execute', ['code' => 'Juphyter\display(1);']);

        expect(only($events, 'display')[0])->toHaveKeys(['id', 'type', 'bundle', 'metadata']);
    });
});

describe('is_complete', function () {
    it('calls finished source complete', function () {
        $engine = engine();

        expect($engine->request('is_complete', ['code' => '$x = 1;'])[0]['status'])->toBe('complete');
    });

    it('calls source that only wanted a semicolon complete', function () {
        $engine = engine();

        expect($engine->request('is_complete', ['code' => '1 + 1'])[0]['status'])->toBe('complete');
    });

    it('calls an unclosed brace incomplete, with an indent to continue at', function () {
        $engine = engine();
        $reply = $engine->request('is_complete', ['code' => "if (true) {\n    \$x = 1;"])[0];

        expect($reply['status'])->toBe('incomplete')
            ->and($reply['indent'])->toBe('    ');
    });

    it('indents one level further after a line that opened a brace', function () {
        $engine = engine();

        expect($engine->request('is_complete', ['code' => 'if (true) {'])[0]['indent'])->toBe('    ');
    });

    it('calls a syntax error invalid', function () {
        $engine = engine();
        $reply = $engine->request('is_complete', ['code' => '$x = = 1;'])[0];

        expect($reply['status'])->toBe('invalid')
            ->and($reply['indent'])->toBe('');
    });

    it('does not touch the session, however often a front end asks', function () {
        $engine = engine();

        // Asking about a cell that imports must not lift that import into the
        // live session, or a front end asking on every keystroke would build up
        // a prelude from half-typed code.
        $engine->request('is_complete', ['code' => 'use ArrayObject as Probed;']);
        $events = $engine->request('execute', ['code' => 'class_exists("Probed");']);

        expect(only($events, 'result')[0]['bundle']['text/plain'])->toBe('false');
    });

    it('does not advance the execution count', function () {
        $engine = engine();
        $engine->request('is_complete', ['code' => '1;']);

        expect(only($engine->request('execute', ['code' => '1;']), 'ok')[0]['execution_count'])->toBe(1);
    });
});

describe('kernel_info', function () {
    it('reports the PHP version actually running', function () {
        $engine = engine();
        $reply = $engine->request('kernel_info')[0];

        expect($reply['language_info']['version'])->toBe(PHP_VERSION)
            ->and($reply['language_info']['name'])->toBe('php')
            ->and($reply['language_info']['mimetype'])->toBe('application/x-php')
            ->and($reply['language_info']['file_extension'])->toBe('.php')
            ->and($reply['language_info']['pygments_lexer'])->toBe('php')
            ->and($reply['language_info']['codemirror_mode'])->toBe('php');
    });

    it('carries a banner naming the version too', function () {
        $engine = engine();

        expect($engine->request('kernel_info')[0]['banner'])->toContain(PHP_VERSION);
    });
});

describe('completion', function () {
    it('completes a session variable after $', function () {
        $engine = engine();
        $engine->request('execute', ['code' => '$frame = 1; $frog = 2; $other = 3;']);

        $reply = $engine->request('complete', ['code' => '$fr', 'cursor_pos' => 3])[0];

        expect($reply['matches'])->toBe(['frame', 'frog'])
            ->and($reply['cursor_start'])->toBe(1)
            ->and($reply['cursor_end'])->toBe(3);
    });

    it('completes a declared function by prefix', function () {
        $engine = engine();
        $engine->request('execute', ['code' => 'function juphyter_probe_one() {} function juphyter_probe_two() {}']);

        $reply = $engine->request('complete', ['code' => 'juphyter_probe_', 'cursor_pos' => 15])[0];

        expect($reply['matches'])->toBe(['juphyter_probe_one', 'juphyter_probe_two']);
    });

    it('completes a declared class by prefix', function () {
        $engine = engine();
        $engine->request('execute', ['code' => 'class JuphyterProbeClass {}']);

        expect($engine->request('complete', ['code' => 'JuphyterProbeCl', 'cursor_pos' => 15])[0]['matches'])
            ->toContain('JuphyterProbeClass');
    });

    it('completes a member after -> on a variable the session holds', function () {
        $engine = engine();
        $engine->request('execute', ['code' => '$holder = new ArrayObject([1, 2]);']);

        $reply = $engine->request('complete', ['code' => '$holder->getA', 'cursor_pos' => 13])[0];

        expect($reply['matches'])->toContain('getArrayCopy')
            ->and($reply['cursor_start'])->toBe(9);
    });

    it('completes a dynamic property, which a notebook value often has', function () {
        $engine = engine();
        $engine->request('execute', ['code' => '$o = new stdClass(); $o->measured = 1;']);

        expect($engine->request('complete', ['code' => '$o->meas', 'cursor_pos' => 8])[0]['matches'])
            ->toBe(['measured']);
    });

    it('completes a static member after ::', function () {
        $engine = engine();
        $engine->request('execute', ['code' => '$d = new DateTime();']);

        expect($engine->request('complete', ['code' => '$d::create', 'cursor_pos' => 10])[0]['matches'])
            ->toContain('createFromFormat');
    });

    it('offers nothing for a member of a value it does not hold', function () {
        $engine = engine();

        expect($engine->request('complete', ['code' => '$nothing->at', 'cursor_pos' => 12])[0]['matches'])->toBe([]);
    });

    it('offers nothing rather than guessing where there is no token', function () {
        $engine = engine();

        expect($engine->request('complete', ['code' => '1 + ', 'cursor_pos' => 4])[0]['matches'])->toBe([]);
    });
});

describe('malformed input', function () {
    it('answers a line that is not JSON and keeps serving', function () {
        $engine = engine();
        $engine->write("this is not json\n");
        $error = $engine->next();

        expect($error['type'])->toBe('error')
            ->and($error['id'])->toBeNull()
            ->and($error['ename'])->toBe(Event::PROTOCOL_ERROR)
            ->and(kinds($engine->request('kernel_info')))->toBe(['kernel_info_reply']);
    });

    it('answers JSON that is not an object', function () {
        $engine = engine();
        $engine->write("[1, 2, 3]\n");

        expect($engine->next()['evalue'])->toContain('JSON object');
    });

    it('answers a request with no id', function () {
        $engine = engine();
        $engine->write(json_encode(['type' => 'kernel_info'])."\n");
        $error = $engine->next();

        expect($error['id'])->toBeNull()
            ->and($error['evalue'])->toContain('"id"');
    });

    it('answers a request with no type, against its id', function () {
        $engine = engine();
        $engine->write(json_encode(['id' => 'x1'])."\n");

        expect($engine->next()['evalue'])->toContain('no string "type"');
    });

    it('answers an unknown type against the id it was given', function () {
        $engine = engine();
        $events = $engine->request('frobnicate');

        expect($events[0]['type'])->toBe('error')
            ->and($events[0]['id'])->toBe('r1')
            ->and($events[0]['ename'])->toBe(Event::PROTOCOL_ERROR)
            ->and($events[0]['evalue'])->toContain('frobnicate');
    });

    it('keeps serving after several bad lines in a row', function () {
        $engine = engine();

        foreach (['{', 'null', '"text"', '{"id":1,"type":"execute"}'] as $rubbish) {
            $engine->write($rubbish."\n");
            expect($engine->next()['type'])->toBe('error');
        }

        expect(kinds($engine->request('execute', ['code' => '1 + 1;'])))->toBe(['result', 'ok']);
    });

    it('does not hang on a line with no newline at the end', function () {
        $engine = engine();
        $engine->write('{"id":"n1","type":"kernel_info"}');
        $engine->disconnect();

        expect($engine->wait())->toBe(0);
    });
});

describe('shutdown', function () {
    it('replies, then exits 0', function () {
        $engine = engine();
        $events = $engine->request('shutdown');

        expect(kinds($events))->toBe(['shutdown_reply'])
            ->and($events[0]['restart'])->toBeFalse()
            ->and($engine->wait())->toBe(0);
    });

    it('echoes the restart flag the transport sent', function () {
        $engine = engine();

        expect($engine->request('shutdown', ['restart' => true])[0]['restart'])->toBeTrue();
    });

    it('closes the channel, so there is nothing more to read', function () {
        $engine = engine();
        $engine->request('shutdown');

        expect($engine->wait())->toBe(0)
            ->and($engine->next())->toBeNull();
    });

    it('does not report the shutdown as a cell having ended the process', function () {
        $engine = engine();
        $engine->request('execute', ['code' => '$x = 1;']);
        $events = $engine->request('shutdown');
        $engine->wait();

        expect(only($events, 'error'))->toBe([])
            ->and($engine->stdout())->toBe('');
    });
});

describe('a cell that ends the process', function () {
    it('is reported as SystemExit', function () {
        $engine = engine();
        $events = $engine->request('execute', ['code' => 'exit(0);']);

        expect(kinds($events))->toBe(['error'])
            ->and($events[0]['ename'])->toBe('SystemExit')
            ->and($engine->wait())->toBe(0);
    });

    it('still delivers what the cell printed first', function () {
        $engine = engine();
        $events = $engine->request('execute', ['code' => 'die("goodbye\n");']);

        expect(kinds($events))->toBe(['stream', 'error'])
            ->and($events[0]['text'])->toBe("goodbye\n");
    });
});

describe('interrupts', function () {
    it('reports an interrupt and keeps the session usable', function () {
        $engine = engine();
        $engine->request('execute', ['code' => '$before = "kept";']);

        $id = $engine->send('execute', ['code' => '$n = 0; while (true) { $n++; usleep(1000); }']);
        usleep(200_000);

        expect($engine->interrupt())->toBeTrue();

        $events = $engine->collect($id);

        expect(kinds($events))->toBe(['error'])
            ->and($events[0]['ename'])->toBe(Interrupt::ENAME);

        $after = $engine->request('execute', ['code' => '$before;']);

        // The display registry renders a string value quoted, as a dumper does.
        expect(only($after, 'result')[0]['bundle']['text/plain'])->toBe("'kept'");
    })->skip(! Interrupt::available() || ! function_exists('posix_kill'), 'Interrupts need the pcntl and posix extensions.');

    it('is not swallowed by the cell\'s own catch (Exception)', function () {
        $engine = engine();
        $id = $engine->send('execute', [
            'code' => '$n = 0; while (true) { try { usleep(1000); $n++; } catch (Exception $e) {} }',
        ]);
        usleep(200_000);
        $engine->interrupt();

        expect(only($engine->collect($id), 'error')[0]['ename'])->toBe(Interrupt::ENAME);
    })->skip(! Interrupt::available() || ! function_exists('posix_kill'), 'Interrupts need the pcntl and posix extensions.');

    it('ignores a signal that arrives between cells', function () {
        $engine = engine();
        $engine->request('execute', ['code' => '$x = 1;']);

        $engine->interrupt();
        usleep(100_000);

        expect(kinds($engine->request('execute', ['code' => '$x + 1;'])))->toBe(['result', 'ok']);
    })->skip(! Interrupt::available() || ! function_exists('posix_kill'), 'Interrupts need the pcntl and posix extensions.');
});
