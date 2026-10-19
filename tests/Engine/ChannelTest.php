<?php

declare(strict_types=1);

use Juphyter\Protocol\Channel;
use Juphyter\Protocol\Event;
use Juphyter\Protocol\Request;

/**
 * The channel on its own, over a socket pair rather than a process, so a test
 * can close one end and watch what the other does.
 *
 * @return array{0: Channel, 1: resource} The engine's end, and the transport's.
 */
function pairedChannel(): array
{
    $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);

    return [new Channel($pair[0]), $pair[1]];
}

describe('framing', function () {
    it('writes one JSON object per line', function () {
        [$channel, $peer] = pairedChannel();

        $channel->send(Event::error('a1', 'Oops', 'went wrong'));
        $channel->close();

        $line = fgets($peer);

        expect($line)->toEndWith("\n")
            ->and(json_decode(trim($line), true))->toBe([
                'id' => 'a1',
                'type' => 'error',
                'ename' => 'Oops',
                'evalue' => 'went wrong',
                'traceback' => ['Oops: went wrong'],
            ]);
    });

    it('reads one line at a time, without the newline', function () {
        [$channel, $peer] = pairedChannel();

        fwrite($peer, "first\nsecond\n");

        expect($channel->receive())->toBe('first')
            ->and($channel->receive())->toBe('second');
    });

    it('reports end of input as null', function () {
        [$channel, $peer] = pairedChannel();

        fclose($peer);

        expect($channel->receive())->toBeNull();
    });

    it('keeps a newline inside a string from breaking the framing', function () {
        [$channel, $peer] = pairedChannel();

        // JSON escapes the newline, which is the whole reason line framing is
        // enough for this protocol.
        $channel->send(Event::fromExecutor(
            ['type' => 'stream', 'name' => 'stdout', 'text' => "two\nlines\n"],
            'a1',
        ));
        $channel->close();

        $lines = explode("\n", trim((string) stream_get_contents($peer)));

        expect($lines)->toHaveCount(1)
            ->and(json_decode($lines[0], true)['text'])->toBe("two\nlines\n");
    });

    it('leaves slashes and unicode readable on the wire', function () {
        [$channel, $peer] = pairedChannel();

        $channel->send(Event::fromExecutor(
            ['type' => 'display', 'bundle' => ['text/html' => '<p>café</p>'], 'metadata' => []],
            'a1',
        ));
        $channel->close();

        $raw = (string) stream_get_contents($peer);

        expect($raw)->toContain('text/html')
            ->and($raw)->toContain('café')
            ->and($raw)->not->toContain('\\/');
    });

    it('substitutes bytes that are not valid UTF-8 rather than failing', function () {
        [$channel, $peer] = pairedChannel();

        // A cell that printed raw bytes must not be able to break the channel.
        $channel->send(Event::fromExecutor(
            ['type' => 'stream', 'name' => 'stdout', 'text' => "ok \xC3\x28 bad"],
            'a1',
        ));
        $channel->close();

        $decoded = json_decode(trim((string) stream_get_contents($peer)), true);

        expect($decoded)->toBeArray()
            ->and($decoded['type'])->toBe('stream')
            ->and($decoded['text'])->toContain('ok ');
    });
});

describe('writing to a peer that has gone', function () {
    // The last thing the engine writes is the shutdown handler's error event,
    // and by then the transport may have stopped reading. None of this may
    // throw, warn or spin.
    it('gives up quietly rather than throwing', function () {
        [$channel, $peer] = pairedChannel();
        fclose($peer);

        $channel->send(Event::error('a1', 'Late', 'after the peer went'));

        expect($channel->isOpen())->toBeFalse();
    });

    it('stays quiet for every write after that', function () {
        [$channel, $peer] = pairedChannel();
        fclose($peer);

        for ($i = 0; $i < 5; $i++) {
            $channel->send(Event::error('a'.$i, 'Late', 'again'));
        }

        expect($channel->isOpen())->toBeFalse();
    });

    it('reads null once the peer has gone', function () {
        [$channel, $peer] = pairedChannel();
        fclose($peer);
        $channel->send(Event::error('a1', 'Late', 'x'));

        expect($channel->receive())->toBeNull();
    });

    it('is closed after close(), and writing then does nothing', function () {
        [$channel, $peer] = pairedChannel();
        $channel->close();

        $channel->send(Event::error('a1', 'After', 'close'));

        expect($channel->isOpen())->toBeFalse()
            ->and((string) stream_get_contents($peer))->toBe('');
    });
});

describe('a line longer than it will read', function () {
    it('gives up on an over-long line and resumes at the next one', function () {
        // Over a file stream, because a socket pair's buffer is far smaller
        // than MAX_LINE and would block the writer before the line was long
        // enough. What matters is the drain: an unreadable line must not stop
        // the engine or swallow the request after it.
        $file = sys_get_temp_dir().'/juphyter-longline-'.bin2hex(random_bytes(6));
        file_put_contents($file, str_repeat('x', Channel::MAX_LINE + 1024)."\n".'{"id":"a1","type":"kernel_info"}'."\n");

        try {
            $channel = new Channel(fopen($file, 'r'));

            expect($channel->receive())->toBe('')
                ->and($channel->receive())->toBe('{"id":"a1","type":"kernel_info"}')
                ->and($channel->receive())->toBeNull();

            $channel->close();
        } finally {
            @unlink($file);
        }
    });

    it('treats a final line with no newline as the last thing said', function () {
        [$channel, $peer] = pairedChannel();

        fwrite($peer, '{"id":"a1","type":"kernel_info"}');
        fclose($peer);

        expect($channel->receive())->toBe('{"id":"a1","type":"kernel_info"}')
            ->and($channel->receive())->toBeNull();
    });
});

describe('connecting', function () {
    it('refuses clearly when there is nothing listening', function () {
        $missing = sys_get_temp_dir().'/juphyter-absent-'.bin2hex(random_bytes(6)).'.sock';

        expect(fn () => Channel::connect($missing, 0.2))
            ->toThrow(RuntimeException::class, 'could not connect to the transport socket');
    });

    it('connects to a socket that is listening', function () {
        $path = sys_get_temp_dir().'/juphyter-listen-'.bin2hex(random_bytes(6)).'.sock';
        $server = stream_socket_server('unix://'.$path);

        try {
            $channel = Channel::connect($path, 1.0);

            expect($channel->isOpen())->toBeTrue();

            $channel->close();
        } finally {
            fclose($server);
            @unlink($path);
        }
    });
});

describe('the request shape task 6 writes', function () {
    it('reads the five request types', function () {
        foreach ([
            Request::EXECUTE => '{"id":"a1","type":"execute","code":"$x = 1;","silent":false,"store_history":true}',
            Request::COMPLETE => '{"id":"a2","type":"complete","code":"$fr","cursor_pos":3}',
            Request::IS_COMPLETE => '{"id":"a3","type":"is_complete","code":"if (true) {"}',
            Request::KERNEL_INFO => '{"id":"a4","type":"kernel_info"}',
            Request::SHUTDOWN => '{"id":"a5","type":"shutdown","restart":false}',
        ] as $type => $line) {
            expect(Request::parse($line)->type)->toBe($type);
        }
    });

    it('reads execute\'s fields, with the documented defaults', function () {
        $full = Request::parse('{"id":"a1","type":"execute","code":"1;","silent":true,"store_history":false}');
        $bare = Request::parse('{"id":"a2","type":"execute"}');

        expect($full->code())->toBe('1;')
            ->and($full->bool('silent', false))->toBeTrue()
            ->and($full->bool('store_history', true))->toBeFalse()
            ->and($bare->code())->toBe('')
            ->and($bare->bool('silent', false))->toBeFalse()
            ->and($bare->bool('store_history', true))->toBeTrue();
    });

    it('defaults the cursor to the end of the code, and clamps it', function () {
        expect(Request::parse('{"id":"a","type":"complete","code":"$abc"}')->cursor())->toBe(4)
            ->and(Request::parse('{"id":"a","type":"complete","code":"$abc","cursor_pos":2}')->cursor())->toBe(2)
            ->and(Request::parse('{"id":"a","type":"complete","code":"$abc","cursor_pos":99}')->cursor())->toBe(4)
            ->and(Request::parse('{"id":"a","type":"complete","code":"$abc","cursor_pos":-5}')->cursor())->toBe(0);
    });

    it('ignores a field of the wrong type rather than trusting it', function () {
        $request = Request::parse('{"id":"a","type":"execute","code":42,"silent":"yes"}');

        expect($request->code())->toBe('')
            ->and($request->bool('silent', false))->toBeFalse();
    });

    it('refuses what is not a request, with a message fit for an evalue', function () {
        expect(fn () => Request::parse('not json'))
            ->toThrow(RuntimeException::class, 'could not read a line as JSON')
            ->and(fn () => Request::parse('[1,2]'))
            ->toThrow(RuntimeException::class, 'JSON object')
            ->and(fn () => Request::parse('{"type":"execute"}'))
            ->toThrow(RuntimeException::class, '"id"')
            ->and(fn () => Request::parse('{"id":"a"}'))
            ->toThrow(RuntimeException::class, 'no string "type"')
            ->and(fn () => Request::parse('{"id":42,"type":"execute"}'))
            ->toThrow(RuntimeException::class, '"id"');
    });
});

describe('the event shape task 6 reads', function () {
    it('puts the id and type first, then the type\'s own fields', function () {
        $event = Event::fromExecutor(['type' => 'ok', 'execution_count' => 3], 'a1');

        expect($event->toArray())->toBe(['id' => 'a1', 'type' => 'ok', 'execution_count' => 3]);
    });

    it('builds each reply with the fields the contract names', function () {
        expect(Event::completeReply('a1', ['x'], 1, 3)->toArray())
            ->toBe(['id' => 'a1', 'type' => 'complete_reply', 'matches' => ['x'], 'cursor_start' => 1, 'cursor_end' => 3])
            ->and(Event::isCompleteReply('a2', 'incomplete', '    ')->toArray())
            ->toBe(['id' => 'a2', 'type' => 'is_complete_reply', 'status' => 'incomplete', 'indent' => '    '])
            ->and(Event::shutdownReply('a3', true)->toArray())
            ->toBe(['id' => 'a3', 'type' => 'shutdown_reply', 'restart' => true]);
    });

    it('always gives is_complete_reply an indent, even when empty', function () {
        expect(Event::isCompleteReply('a1', 'complete')->toArray())->toHaveKey('indent');
    });

    it('carries a null id for a complaint about the channel itself', function () {
        $event = Event::error(null, Event::PROTOCOL_ERROR, 'bad line');

        expect($event->toArray()['id'])->toBeNull()
            ->and($event->toArray()['traceback'])->toBe([Event::PROTOCOL_ERROR.': bad line']);
    });

    it('keeps a traceback it was given rather than making one up', function () {
        expect(Event::error('a1', 'E', 'v', ['one', 'two'])->toArray()['traceback'])->toBe(['one', 'two']);
    });

    it('reports the running PHP version in kernel_info_reply', function () {
        $reply = Event::kernelInfoReply('a1', ['name' => 'php', 'version' => PHP_VERSION], 'banner')->toArray();

        expect($reply['language_info']['version'])->toBe(PHP_VERSION)
            ->and($reply['banner'])->toBe('banner');
    });
});
