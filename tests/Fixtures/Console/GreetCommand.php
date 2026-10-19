<?php

declare(strict_types=1);

namespace Juphyter\Tests\Fixtures\Console;

use Juphyter\Console\Command;

/**
 * A command the dispatcher tests discover, so they can assert on dispatch
 * before this package has any command of its own.
 */
final class GreetCommand extends Command
{
    /** @var list<string>|null The arguments of the last run, for assertions. */
    public static ?array $received = null;

    public static function description(): string
    {
        return 'Greet somebody';
    }

    public function run(array $arguments): int
    {
        self::$received = $arguments;

        return $arguments === [] ? 0 : 7;
    }
}
