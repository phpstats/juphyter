<?php

declare(strict_types=1);

namespace Juphyter\Engine;

use PhpParser\Node\Stmt\Use_;

/**
 * What the cleaner remembers between cells.
 *
 * Two things, both of which exist because one `eval()` does not inherit them
 * from the last: the imports the session has accumulated, which the prelude
 * re-emits ahead of every cell, and the names the session has declared, which
 * PHP cannot redeclare.
 *
 * The two are committed at different moments, and deliberately so. An import is
 * committed when the cell is cleaned, because a `use` statement cannot fail. A
 * declaration is committed by {@see commitDeclarations()} after the executor
 * has run the cell, because reading `class A {}` is no proof that `A` was
 * declared.
 *
 * The variables a cell binds are not here. They live in the executor, which
 * owns `extract()` and `get_defined_vars()`, and nothing in this class needs
 * to know about them.
 *
 * ## Case
 *
 * PHP's rules are followed rather than simplified. Class-like names, function
 * names and the aliases of both are case-insensitive, so they are keyed
 * lowercased and the spelling the user wrote is kept for the message.
 * Constants are case-sensitive, so a `use const` alias is keyed as written.
 *
 * ## One namespace for four keywords
 *
 * `class`, `interface`, `trait` and `enum` share a single namespace in PHP: a
 * cell declaring `interface Foo` after a cell declared `class Foo` fails with
 * "Cannot redeclare class Foo". They are therefore tracked together, and
 * functions, which do not collide with them, separately.
 */
final class Session
{
    /** @var array<string, array{type: int, name: string, alias: string}> keyed "type\0alias" */
    private array $imports = [];

    /** @var array<string, string> lowercased name => the spelling the cell used */
    private array $classLike = [];

    /** @var array<string, string> lowercased name => the spelling the cell used */
    private array $functions = [];

    /**
     * Record an import, which replaces any earlier one with the same alias.
     *
     * Replacing is deliberate. Re-running an edited cell that changed an import
     * is ordinary notebook work, and emitting both bindings would be an
     * uncatchable fatal error rather than a reportable one.
     *
     * @param  int  $type  One of the Use_::TYPE_* constants.
     */
    public function import(int $type, string $name, string $alias): void
    {
        $this->imports[self::importKey($type, $alias)] = [
            'type' => $type,
            'name' => $name,
            'alias' => $alias,
        ];
    }

    /** The alias an import is bound under, as the key the collision check uses. */
    public static function importKey(int $type, string $alias): string
    {
        return $type."\0".($type === Use_::TYPE_CONSTANT ? $alias : strtolower($alias));
    }

    /**
     * Every accumulated import, as a `use` statement without its newline.
     *
     * Sorted, so the prelude a given session produces is the same every time
     * and a test can assert on it.
     *
     * @return list<string>
     */
    public function importStatements(): array
    {
        $statements = [];

        foreach ($this->imports as $import) {
            $keyword = match ($import['type']) {
                Use_::TYPE_FUNCTION => 'use function ',
                Use_::TYPE_CONSTANT => 'use const ',
                default => 'use ',
            };

            $last = strrchr($import['name'], '\\');
            $last = $last === false ? $import['name'] : substr($last, 1);
            $alias = $last === $import['alias'] ? '' : ' as '.$import['alias'];

            $statements[] = $keyword.$import['name'].$alias.';';
        }

        sort($statements);

        return $statements;
    }

    /** Whether $alias is already bound, for the given use type. */
    public function hasImport(int $type, string $alias): bool
    {
        return isset($this->imports[self::importKey($type, $alias)]);
    }

    /** The fully qualified name $alias is bound to, or null. */
    public function importedName(int $type, string $alias): ?string
    {
        return $this->imports[self::importKey($type, $alias)]['name'] ?? null;
    }

    /**
     * Record the declarations of a cell that has been through `eval()`.
     *
     * **The executor must call this after every `eval()` attempt, whether the
     * cell threw or not.** Only a name that now exists in the PHP runtime is
     * recorded, which is the whole point: a cell can be perfectly valid source
     * and still fail to declare anything, as `class A extends Typo {}` does, and
     * reserving `A` on the strength of having read it would refuse the corrected
     * cell for the rest of the session.
     *
     * Existence is checked after the attempt rather than only on success,
     * because PHP hoists an unconditional top-level declaration at compile
     * time: `class A {} throw new Exception();` really does declare `A`. A
     * success-only rule would forget it and let the next cell declaring `A`
     * reach the uncatchable fatal error.
     *
     * Autoloading is off for every check. A name the autoloader could find is
     * not a name this session declared.
     */
    public function commitDeclarations(CleanedCell $cell): void
    {
        foreach ($cell->declaresClassLike as $name) {
            if (self::classLikeExists($name)) {
                $this->declareClassLike($name);
            }
        }

        foreach ($cell->declaresFunctions as $name) {
            if (function_exists($name)) {
                $this->declareFunction($name);
            }
        }
    }

    /** Whether $name is a declared class, interface, trait or enum right now. */
    private static function classLikeExists(string $name): bool
    {
        return class_exists($name, false)
            || interface_exists($name, false)
            || trait_exists($name, false)
            || enum_exists($name, false);
    }

    /** Record a class, interface, trait or enum the session has declared. */
    public function declareClassLike(string $name): void
    {
        $this->classLike[strtolower($name)] = $name;
    }

    public function declareFunction(string $name): void
    {
        $this->functions[strtolower($name)] = $name;
    }

    public function hasClassLike(string $name): bool
    {
        return isset($this->classLike[strtolower($name)]);
    }

    public function hasFunction(string $name): bool
    {
        return isset($this->functions[strtolower($name)]);
    }

    /** @return list<string> The spellings the cells used, sorted. */
    public function declaredClassLike(): array
    {
        $names = array_values($this->classLike);
        sort($names);

        return $names;
    }

    /** @return list<string> The spellings the cells used, sorted. */
    public function declaredFunctions(): array
    {
        $names = array_values($this->functions);
        sort($names);

        return $names;
    }
}
