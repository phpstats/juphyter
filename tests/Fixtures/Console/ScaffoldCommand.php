<?php

declare(strict_types=1);

namespace Juphyter\Tests\Fixtures\Console;

use Juphyter\Console\Command;

/** An abstract command, which discovery must leave out of the listing. */
abstract class ScaffoldCommand extends Command
{
    public static function description(): string
    {
        return 'Never listed';
    }
}
