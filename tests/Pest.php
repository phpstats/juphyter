<?php

declare(strict_types=1);

use Juphyter\Tests\TestCase;

pest()->extend(TestCase::class)->in(__DIR__);

/** Run a callable with fresh stdout and stderr streams, and read both back. */
function captured(Closure $fn): array
{
    $stdout = fopen('php://memory', 'r+');
    $stderr = fopen('php://memory', 'r+');

    $result = $fn($stdout, $stderr);

    rewind($stdout);
    rewind($stderr);

    return [
        'result' => $result,
        'stdout' => (string) stream_get_contents($stdout),
        'stderr' => (string) stream_get_contents($stderr),
    ];
}
