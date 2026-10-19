<?php

declare(strict_types=1);

use Juphyter\Protocol\Channel;

/**
 * An engine that is given nothing to do for a while.
 *
 * Waiting between requests is most of what the engine does, and it used to be
 * what killed it. A socket from `stream_socket_client()` carries
 * `default_socket_timeout` as its read timeout, 60 seconds by default, and
 * `fgets()` returns false when that expires. {@see Channel::receive()} read the
 * false as end of input and the engine's loop returned on it, so an engine sat
 * between requests for a minute and then exited. A notebook left idle for that
 * long lost its session, and its next cell failed with a broken pipe.
 *
 * These tests run against a deliberately tiny timeout rather than the real one,
 * because the bug is the timeout being consulted at all and a test that waits
 * out sixty seconds is a test nobody runs. The engine is spawned with
 * `-d default_socket_timeout=1`, which is why they do not use
 * {@see Juphyter\Tests\Engine\Harness}: an ini setting has to go on the command
 * line ahead of the script, and the harness appends its arguments after it.
 *
 * The second test is the one that keeps the fix honest. Ignoring a read that
 * expired is only correct while a read that reached the end of the input is
 * still obeyed, and the two are told apart by the stream's metadata alone. An
 * engine that stopped obeying end of input would hang forever on a transport
 * that had been killed, which is the failure the first test's fix could cause.
 */

/** How long a read is given before it expires, for the engine under test. */
const IDLE_TIMEOUT = 1;

/**
 * An engine spawned with ini settings of our choosing, and the socket to it.
 *
 * @param  array<string, string>  $ini  Settings passed as `-d name=value`.
 * @return array{0: resource, 1: resource, 2: string} The connection, the
 *                                                    process, and its directory.
 */
function idleEngine(array $ini): array
{
    $root = dirname(__DIR__, 2);
    $directory = sys_get_temp_dir().'/juphyter-idle-'.bin2hex(random_bytes(6));
    mkdir($directory, 0o777, true);
    $socket = $directory.'/engine.sock';

    $server = stream_socket_server('unix://'.$socket, $code, $message);

    if ($server === false) {
        throw new RuntimeException('could not listen on '.$socket.': '.$message);
    }

    $options = [];

    foreach ($ini as $name => $value) {
        $options[] = '-d';
        $options[] = $name.'='.$value;
    }

    $process = proc_open(
        [PHP_BINARY, ...$options, $root.'/bin/juphyter', 'engine'],
        [
            0 => ['file', '/dev/null', 'r'],
            // Files rather than pipes, for the reason the harness gives: a pipe
            // nobody is draining fills and deadlocks the process behind it.
            1 => ['file', $directory.'/stdout', 'w'],
            2 => ['file', $directory.'/stderr', 'w'],
        ],
        $pipes,
        $root,
        ['JUPHYTER_SOCKET' => $socket, 'PATH' => getenv('PATH')],
    );

    if (! is_resource($process)) {
        throw new RuntimeException('could not start the engine');
    }

    $connection = stream_socket_accept($server, 10.0);

    if ($connection === false) {
        throw new RuntimeException('the engine never connected');
    }

    fclose($server);

    // The test's own end of the socket keeps a generous timeout: it is the
    // engine's reads that are under test, not these.
    stream_set_timeout($connection, 10);

    $GLOBALS['juphyter_idle'][] = [$process, $directory, $socket];

    return [$connection, $process, $directory];
}

/** Whether the engine process is still running. */
function stillRunning($process): bool
{
    return is_resource($process) && proc_get_status($process)['running'];
}

/** Wait for the engine to exit and return its code, or null on a timeout. */
function exitCode($process, float $wait = 10.0): ?int
{
    $deadline = microtime(true) + $wait;

    while (microtime(true) < $deadline) {
        $status = proc_get_status($process);

        if (! $status['running']) {
            return $status['exitcode'];
        }

        usleep(5_000);
    }

    return null;
}

beforeEach(function () {
    $GLOBALS['juphyter_idle'] = [];
});

afterEach(function () {
    foreach ($GLOBALS['juphyter_idle'] ?? [] as [$process, $directory, $socket]) {
        if (is_resource($process)) {
            if (proc_get_status($process)['running']) {
                proc_terminate($process, 9);
            }

            proc_close($process);
        }

        foreach (['stdout', 'stderr'] as $file) {
            @unlink($directory.'/'.$file);
        }

        @unlink($socket);
        @rmdir($directory);
    }

    $GLOBALS['juphyter_idle'] = [];
});

it('answers a request after sitting idle for longer than a read timeout', function () {
    [$connection, $process] = idleEngine(['default_socket_timeout' => (string) IDLE_TIMEOUT]);

    // Comfortably past two expiries of the engine's read timeout, which is
    // what used to end its loop.
    usleep((int) (2.5 * IDLE_TIMEOUT * 1_000_000));

    expect(stillRunning($process))->toBeTrue(
        'the engine exited while idle, so a read timeout is still being read as end of input',
    );

    fwrite($connection, json_encode(['id' => 'r1', 'type' => 'kernel_info'])."\n");
    $reply = json_decode((string) fgets($connection), true);

    // The reply, and nothing ahead of it. An expired read answered with an
    // empty line rather than absorbed would reach the engine's loop as an
    // unreadable request, and the first thing read here would be the
    // unattached protocol error it sent back for it.
    expect($reply['type'])->toBe('kernel_info_reply')
        ->and($reply['id'])->toBe('r1');

    // Nor anything behind it, for the same reason.
    stream_set_timeout($connection, 1);

    expect(fgets($connection))->toBeFalse();
});

it('exits when the transport closes the socket, timeout or no timeout', function () {
    [$connection, $process] = idleEngine(['default_socket_timeout' => (string) IDLE_TIMEOUT]);

    // Past one expiry first, so the engine is inside the loop that waits out a
    // timed-out read when the socket goes.
    usleep((int) (1.5 * IDLE_TIMEOUT * 1_000_000));

    expect(stillRunning($process))->toBeTrue();

    $closed = microtime(true);
    fclose($connection);
    $code = exitCode($process);
    $took = microtime(true) - $closed;

    expect($code)->toBe(0)
        // Promptly, rather than after however many expiries it took to notice.
        // A fix that waited out a timeout before looking at the metadata would
        // still arrive here, just late.
        ->and($took)->toBeLessThan(2.0 * IDLE_TIMEOUT);
});

it('waits out an expired read and still obeys end of input', function () {
    // The distinction {@see Channel::receive()} draws, at the class rather than
    // through an engine. A channel this class connected has no timeout at all,
    // so the stream here is one handed to the constructor and given a short
    // one, which is the case the metadata check in receive() is for.
    //
    // The writer is a separate process because the point is a read that is
    // already blocked when the data arrives: the expiry has to happen inside
    // receive(), which cannot be arranged from the process doing the reading.
    $directory = sys_get_temp_dir().'/juphyter-channel-'.bin2hex(random_bytes(6));
    mkdir($directory, 0o777, true);
    $path = $directory.'/channel.sock';
    $server = stream_socket_server('unix://'.$path);

    $writer = proc_open(
        [
            PHP_BINARY,
            '-r',
            // Connect at once, so the accept below returns and the read starts,
            // then write well after that read has expired.
            '$c = stream_socket_client("unix://".$argv[1]);'
            .'usleep(2_000_000);'
            .'fwrite($c, "{\"id\":\"late\"}\n");'
            .'usleep(2_000_000);',
            '--',
            $path,
        ],
        [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
        $pipes,
    );

    try {
        expect(is_resource($writer))->toBeTrue();

        $peer = stream_socket_accept($server, 10.0);

        expect($peer)->not->toBeFalse();

        stream_set_timeout($peer, IDLE_TIMEOUT);
        $channel = new Channel($peer);

        $started = microtime(true);
        $line = $channel->receive();
        $waited = microtime(true) - $started;

        expect($line)->toBe('{"id":"late"}')
            ->and($waited)->toBeGreaterThan((float) IDLE_TIMEOUT);

        // And a peer that really goes is end of input rather than another wait.
        proc_terminate($writer, 9);

        expect($channel->receive())->toBeNull();
    } finally {
        if (is_resource($writer)) {
            if (proc_get_status($writer)['running']) {
                proc_terminate($writer, 9);
            }

            proc_close($writer);
        }

        fclose($server);
        @unlink($path);
        @rmdir($directory);
    }
});
