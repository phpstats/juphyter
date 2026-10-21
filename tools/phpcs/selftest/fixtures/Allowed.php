<?php
declare(strict_types=1);
namespace Phpstats\Sniffs\Fixtures;
function allow_list_probe(array $_by, array $_keepAll, array $_notListed): void
{
    unset($_by, $_keepAll, $_notListed);
}
