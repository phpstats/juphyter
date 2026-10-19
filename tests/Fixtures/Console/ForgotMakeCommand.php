<?php

declare(strict_types=1);

namespace Juphyter\Tests\Fixtures\Console;

use Juphyter\Console\Command;

/**
 * A command that declares a constructor dependency but does not override
 * make(), which is the mistake the dispatcher has to report clearly.
 */
final class ForgotMakeCommand extends Command
{
    public function __construct(private readonly string $needed) {}

    public static function description(): string
    {
        return 'Declares a dependency but forgets make()';
    }

    public function run(array $arguments): int
    {
        return 0;
    }
}
