<?php
declare(strict_types=1);
namespace Phpstats\Sniffs\Fixtures;
final class not_pascal_case
{
    public const GOOD_CONSTANT = 1;
    public const badConstant = 2;
}
final class PascalCase {}
