<?php
declare(strict_types=1);
namespace Phpstats\Sniffs\Fixtures;
function camelCapsFreeFunction(): void {}
function fine_free_function(int $okName): void {}
interface LintContract { public function abstract_method(int $bad_parameter): void; }
final class LintFixture
{
    public int $bad_property = 0;
    public int $goodProperty = 0;
    private static array $another_bad = [];
    public function __construct(public string $promoted_property = '') {}
    public function snake_case_method(int $bad_parameter, int $goodParameter): void
    {
        $local_variable_is_not_checked = $bad_parameter + $goodParameter;
        unset($local_variable_is_not_checked, $this->bad_property, self::$another_bad);
    }
    public function closures(): callable
    {
        return fn (int $arrow_parameter) => function (int $closure_parameter) use ($arrow_parameter) { return $arrow_parameter + $closure_parameter; };
    }
}
