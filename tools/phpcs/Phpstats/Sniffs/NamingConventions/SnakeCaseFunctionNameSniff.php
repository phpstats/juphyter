<?php
declare(strict_types=1);

namespace Phpstats\Sniffs\NamingConventions;

use PHP_CodeSniffer\Files\File;
use PHP_CodeSniffer\Sniffs\Sniff;
use PHP_CodeSniffer\Util\Tokens;

/**
 * Standalone functions are snake_case.
 *
 * PHPCS has no sniff for this. Generic.NamingConventions.CamelCapsFunctionName
 * demands the opposite and is switched off in the ruleset. Methods are left to
 * PSR1.Methods.CamelCapsMethodName, which is that sniff's method-only subclass.
 */
final class SnakeCaseFunctionNameSniff implements Sniff
{
    /** Function names exempt from the rule, set from the ruleset. */
    public array $allowed = [];

    public function register(): array
    {
        return [T_FUNCTION];
    }

    public function process(File $phpcsFile, $stackPtr)
    {
        $tokens = $phpcsFile->getTokens();
        foreach ($tokens[$stackPtr]['conditions'] as $condition) {
            if (isset(Tokens::$ooScopeTokens[$condition])) {
                return;
            }
        }
        $name = $phpcsFile->getDeclarationName($stackPtr);
        if ($name === null || in_array($name, $this->allowed, true)) {
            return;
        }
        if (preg_match('/^[a-z][a-z0-9]*(?:_[a-z0-9]+)*$/', $name) === 1) {
            return;
        }
        $phpcsFile->addError('Standalone function "%s()" is not snake_case.', $stackPtr, 'NotSnakeCase', [$name]);
    }
}
