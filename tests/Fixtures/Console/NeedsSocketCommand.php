<?php

declare(strict_types=1);

namespace Juphyter\Tests\Fixtures\Console;

use Juphyter\Console\Command;

/**
 * A command with a required constructor argument, built through make().
 *
 * This is the shape task 5's engine command wants, and the regression guard for
 * the listing: when the listing constructed what it found, one command like
 * this broke the whole CLI.
 */
final class NeedsSocketCommand extends Command
{
    public static ?string $ran = null;

    public function __construct(private readonly string $socket) {}

    public static function description(): string
    {
        return 'Needs a socket to build';
    }

    public static function make(): static
    {
        return new self(getenv('JUPHYTER_TEST_SOCKET') ?: '/tmp/fallback.sock');
    }

    public function run(array $arguments): int
    {
        self::$ran = $this->socket;

        return 0;
    }
}
