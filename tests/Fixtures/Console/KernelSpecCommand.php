<?php

declare(strict_types=1);

namespace Juphyter\Tests\Fixtures\Console;

use Juphyter\Console\Command;

/** A two-word command, so the tests pin the kebab-cased name. */
final class KernelSpecCommand extends Command
{
    public static function description(): string
    {
        return 'Write a kernelspec';
    }

    public function run(array $arguments): int
    {
        return 0;
    }
}
