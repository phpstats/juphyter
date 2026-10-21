<?php
declare(strict_types=1);

namespace Phpstats\Sniffs\NamingConventions;

use PHP_CodeSniffer\Files\File;
use PHP_CodeSniffer\Sniffs\Sniff;
use PHP_CodeSniffer\Util\Tokens;

/**
 * Declared parameters and properties are camelCase.
 *
 * PHPCS has no sniff for either one. Local variables are not checked, because
 * the conventions do not reach them. A package sets `allowed` in its ruleset to
 * list the names its own documented exceptions use.
 */
final class CamelCapsParameterAndPropertySniff implements Sniff
{
    /** Parameter and property names exempt from the rule, with or without the sigil. */
    public array $allowed = [];

    public function register(): array
    {
        return [T_FUNCTION, T_CLOSURE, T_FN, T_VARIABLE];
    }

    public function process(File $phpcsFile, $stackPtr)
    {
        $tokens = $phpcsFile->getTokens();
        if ($tokens[$stackPtr]['code'] === T_VARIABLE) {
            // A property is declared directly in the class body. A parameter sits
            // in the declaration's parentheses, where the innermost condition is
            // also the class, and is reported by the branch below instead.
            if (empty($tokens[$stackPtr]['nested_parenthesis']) === false) {
                return;
            }
            $conditions = $tokens[$stackPtr]['conditions'];
            $innermost = end($conditions);
            if ($innermost !== false && isset(Tokens::$ooScopeTokens[$innermost])) {
                $this->check($phpcsFile, $stackPtr, $tokens[$stackPtr]['content'], 'Property', 'PropertyNotCamelCaps');
            }
            return;
        }
        foreach ($phpcsFile->getMethodParameters($stackPtr) as $parameter) {
            $this->check($phpcsFile, $parameter['token'], $parameter['name'], 'Parameter', 'ParameterNotCamelCaps');
        }
    }

    private function check(File $phpcsFile, int $stackPtr, string $variable, string $kind, string $code): void
    {
        $name = ltrim($variable, '$');
        if (in_array($name, $this->allowed, true) || in_array('$'.$name, $this->allowed, true)) {
            return;
        }
        if (preg_match('/^[a-z][a-zA-Z0-9]*$/', $name) === 1) {
            return;
        }
        $phpcsFile->addError('%s "$%s" is not camelCase.', $stackPtr, $code, [$kind, $name]);
    }
}
