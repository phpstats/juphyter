<?php

declare(strict_types=1);

namespace Juphyter\Engine;

use PhpParser\Error as ParserError;
use PhpParser\Node\Expr;
use PhpParser\Node\Stmt;
use PhpParser\Node\Stmt\Use_;
use PhpParser\Node\UseItem;
use PhpParser\Parser;
use PhpParser\ParserFactory;

/**
 * Turns a notebook cell into source `eval()` can run, or refuses it.
 *
 * The cleaner is the kernel's first line of defence, and the reason it exists
 * is narrower than it looks: several things PHP rejects at compile time are
 * uncatchable fatal errors inside `eval()`, which end the kernel process
 * instead of producing an error the notebook can show. Measured on PHP 8.3,
 * 8.4 and 8.5, these kill the process:
 *
 * - binding one `use` alias twice, including to the same target;
 * - `declare(strict_types=...)` anywhere but the very first statement;
 * - `declare(encoding=...)` anywhere but the very first statement;
 * - redeclaring a class-like name or a function.
 *
 * Every one of those is therefore made impossible by construction, or refused
 * with a {@see CellError} before anything runs. A cell that reaches the
 * executor can still throw, but it cannot take the kernel down at compile time.
 *
 * ## What it does, in order
 *
 * 1. Parse the cell on its own. Source that ran out of input is retried once
 *    with {@see RETRY_SUFFIX} — a newline and a semicolon — appended, because a
 *    cell is written the way a prompt is and a trailing expression often
 *    arrives without one; if the retry does not parse either, the cell was
 *    genuinely unfinished and the *original* error is reported, with
 *    `CellError::$incomplete` set so the transport can answer
 *    `is_complete_request` without matching on a parser message of its own. A
 *    `PhpParser\Error` becomes a `ParseError` {@see CellError} carrying the
 *    parser's message and line, and nothing runs. Because the cell is parsed
 *    alone, the line is the user's from the start.
 * 2. Refuse a top-level `namespace`. PHP would accept it inside `eval()`, but
 *    changing namespace mid-session breaks name resolution for everything
 *    already defined, so it is a deliberate refusal rather than a limitation.
 * 3. Handle the cell's own `declare`: strip `strict_types=1`, which the prelude
 *    already guarantees; refuse `strict_types=0`, which it cannot honour;
 *    refuse `encoding`, which can no longer be first. Anything else, `ticks`
 *    among them, is left where it is, having been measured to be legal there.
 * 4. Lift the cell's `use` statements into the session and strip them from the
 *    body, so that every import reaches `eval()` through the prelude and
 *    exactly once. A cell binding one alias twice is refused; a later cell
 *    rebinding an earlier alias is allowed and wins, because re-running an
 *    edited cell is ordinary notebook work.
 * 5. Refuse a top-level declaration of a name the session already holds, or
 *    that the cell declares twice, naming the collision. Only top-level
 *    declarations count, so `if (! function_exists('f')) { function f() {} }`
 *    is left alone: PHP does not hoist it, and it does not collide. The names
 *    the cell declares travel back on the result rather than into the session,
 *    because reading `class A extends Typo {}` is no proof that `A` was
 *    declared — that cell is valid source and still declares nothing. Only the
 *    executor can know, so {@see Session::commitDeclarations()} is its job to
 *    call once the cell has run.
 * 6. Capture the cell's value. If the last statement is an expression statement
 *    whose expression is not an assignment, an increment, or `print`, assign it
 *    into {@see RESULT_VARIABLE} so the executor can publish it.
 *
 * ## Why the cell's bytes are never moved
 *
 * Steps 3 and 4 remove statements, and steps 1 and 6 add to the end. The executor has to
 * rewrite a traceback frame into a cell line, so a removal overwrites the
 * statement with spaces rather than deleting it, which keeps every later line
 * and column where the user put it, and the one insertion goes at the start of
 * the last statement, below which there is nothing to shift. The offset
 * between the evaluated source and the cell is therefore the prelude's line
 * count, always. {@see CleanedCell} documents the contract.
 */
final class Cleaner
{
    /**
     * The variable a cell's value is captured into.
     *
     * Reserved: the executor keeps it out of the session's variables, and a
     * cell that assigns to it itself has its value overwritten.
     */
    public const RESULT_VARIABLE = '__juphyter_result';

    /** The opening tag the parser needs, kept on the cell's first line. */
    private const OPEN_TAG = '<?php ';

    /**
     * The parser's marker for source that simply stops.
     *
     * Every way of running out of input lands on this, from a bare `1 + 1` to
     * an unclosed brace, which is why the retry below has to be judged on
     * whether it parses rather than on the message.
     */
    private const UNFINISHED = 'unexpected EOF';

    /**
     * What the retry appends: a newline, then the semicolon.
     *
     * The newline is the part that matters. Jupyter usually stores cell source
     * without a trailing newline, so a cell ending in a line comment puts the
     * bare semicolon inside that comment, where it cannot close anything:
     * `1 + 1 // sum` and `1 + 1 # sum` both still fail. On its own line it
     * closes the statement for both, and the `#` form is the one Quarto uses
     * for cell options. Measured against every shape the tests cover, there is
     * nothing the bare semicolon parses that this does not.
     */
    private const RETRY_SUFFIX = "\n;";

    private readonly Parser $parser;

    public function __construct(?Parser $parser = null)
    {
        $this->parser = $parser ?? (new ParserFactory())->createForNewestSupportedVersion();
    }

    /**
     * Clean one cell against a session.
     *
     * On success the session is updated with the cell's imports and the names
     * it declares. On a {@see CellError} the session is left untouched, so a
     * refused cell has no effect on the ones after it.
     */
    public function clean(string $code, Session $session): CleanedCell|CellError
    {
        $prefixed = self::OPEN_TAG.$code;
        $semicolonAppended = false;

        try {
            $statements = $this->parser->parse($prefixed) ?? [];
        } catch (ParserError $original) {
            if (! str_contains($original->getRawMessage(), self::UNFINISHED)) {
                // Source that is wrong rather than unfinished: Jupyter's
                // "invalid".
                return self::reportParseError($original);
            }

            // A cell is written the way a prompt is, so a trailing expression
            // often arrives without its semicolon. The retry is tried only for
            // source that ran out of input, only ever appends, and goes after
            // everything the user wrote, so no line moves.
            try {
                $statements = $this->parser->parse($prefixed.self::RETRY_SUFFIX) ?? [];
            } catch (ParserError) {
                // Still unfinished, so this is Jupyter's "incomplete". The
                // original error is the honest one to report: an unclosed brace
                // is not a missing semicolon, even though both arrive here.
                return self::reportParseError($original, incomplete: true);
            }

            $prefixed .= self::RETRY_SUFFIX;
            $semicolonAppended = true;
        }

        // Collected first and applied only once every check has passed, so a
        // refusal cannot leave the session half-updated.
        $edits = [];
        $imports = [];

        foreach ($statements as $statement) {
            if ($statement instanceof Stmt\Namespace_) {
                return CellError::unsupported(
                    'A cell cannot declare a namespace, because changing namespace mid-session '
                    .'breaks name resolution for everything the session has already defined.',
                    $statement->getStartLine(),
                );
            }

            if ($statement instanceof Stmt\Declare_) {
                $outcome = $this->inspectDeclare($statement);

                if ($outcome instanceof CellError) {
                    return $outcome;
                }

                if ($outcome) {
                    $edits[] = $this->blank($prefixed, $statement);
                }

                continue;
            }

            if ($statement instanceof Use_ || $statement instanceof Stmt\GroupUse) {
                $outcome = $this->collectImports($statement, $imports);

                if ($outcome instanceof CellError) {
                    return $outcome;
                }

                $edits[] = $this->blank($prefixed, $statement);
            }
        }

        $declarations = $this->collectDeclarations($statements, $session);

        if ($declarations instanceof CellError) {
            return $declarations;
        }

        $capture = $this->captureLastValue($statements);

        if ($capture !== null) {
            $edits[] = $capture;
        }

        // Every check has passed. Imports are committed here, because a `use`
        // statement cannot fail. The declarations are not: they travel on the
        // result for the executor to commit once it knows which of them PHP
        // really accepted. See Session::commitDeclarations().
        foreach ($imports as $import) {
            $session->import($import['type'], $import['name'], $import['alias']);
        }

        $prelude = Prelude::for($session);
        $body = substr($this->applyEdits($prefixed, $edits), strlen(self::OPEN_TAG));

        return new CleanedCell(
            $prelude->source()."\n".$body,
            $prelude->lineCount(),
            $capture === null ? null : self::RESULT_VARIABLE,
            $declarations['classLike'],
            $declarations['functions'],
            $semicolonAppended,
        );
    }

    /** The parser's own message and line, with the line normalised. */
    private static function reportParseError(ParserError $error, bool $incomplete = false): CellError
    {
        $line = $error->getStartLine();

        return CellError::parseError($error->getRawMessage(), $line > 0 ? $line : null, $incomplete);
    }

    /**
     * What to do with a `declare` the cell wrote.
     *
     * True to strip it, false to leave it, or a refusal.
     */
    private function inspectDeclare(Stmt\Declare_ $statement): bool|CellError
    {
        // Every directive is read before anything is decided. Returning on the
        // first recognised one would strip the whole statement and silently
        // throw away whatever else the user wrote in it.
        $directives = [];

        foreach ($statement->declares as $declare) {
            $directives[$declare->key->toString()] = $declare->value;
        }

        $line = $statement->getStartLine();

        if (array_key_exists('encoding', $directives)) {
            return CellError::unsupported(
                'A cell cannot declare an encoding, because PHP only accepts that as the '
                .'very first statement and the kernel puts declare(strict_types=1) there.',
                $line,
            );
        }

        if (! array_key_exists('strict_types', $directives)) {
            return false;
        }

        if ($statement->stmts !== null) {
            return CellError::unsupported(
                'A cell cannot use declare(strict_types=...) in block mode, which PHP '
                .'rejects outright.',
                $line,
            );
        }

        if (count($directives) > 1) {
            $others = array_keys(array_diff_key($directives, ['strict_types' => null]));

            return CellError::unsupported(sprintf(
                'A cell cannot combine strict_types with %s in one declare. The kernel has to '
                .'remove its strict_types to keep its own first, and that would take %s with '
                .'it. Put %s in a declare of its own.',
                implode(' and ', $others),
                count($others) === 1 ? 'that' : 'those',
                implode(' and ', $others),
            ), $line);
        }

        $value = $directives['strict_types'];
        $strict = $value instanceof Expr && property_exists($value, 'value') ? $value->value : null;

        if ($strict === 0) {
            return CellError::unsupported(
                'A cell cannot turn strict types off: juphyter runs every cell under '
                .'declare(strict_types=1), which PHP only accepts as the first statement.',
                $line,
            );
        }

        return true;
    }

    /**
     * Read a `use` or grouped `use` into $imports, refusing a clash inside the
     * one cell.
     *
     * @param  array<string, array{type: int, name: string, alias: string}>  $imports
     */
    private function collectImports(Use_|Stmt\GroupUse $statement, array &$imports): ?CellError
    {
        $prefix = $statement instanceof Stmt\GroupUse ? $statement->prefix->toString().'\\' : '';

        foreach ($statement->uses as $use) {
            $type = $use->type === Use_::TYPE_UNKNOWN ? $statement->type : $use->type;
            $name = $prefix.$use->name->toString();
            $alias = $use->alias?->toString() ?? self::lastSegment($name);
            $key = Session::importKey($type, $alias);

            if (isset($imports[$key]) && $imports[$key]['name'] !== $name) {
                return CellError::unsupported(sprintf(
                    'This cell binds "%s" to both %s and %s. PHP cannot have one alias mean two '
                    .'things, so pick one or alias the other with "as".',
                    $alias,
                    $imports[$key]['name'],
                    $name,
                ), $this->useLine($statement, $use));
            }

            $imports[$key] = ['type' => $type, 'name' => $name, 'alias' => $alias];
        }

        return null;
    }

    /**
     * The names the cell declares at the top level, or a refusal.
     *
     * @param  list<Stmt>  $statements
     * @return array{classLike: list<string>, functions: list<string>}|CellError
     */
    private function collectDeclarations(array $statements, Session $session): array|CellError
    {
        $classLike = [];
        $functions = [];

        foreach ($statements as $statement) {
            $function = $statement instanceof Stmt\Function_;

            $isClassLike = $statement instanceof Stmt\Class_
                || $statement instanceof Stmt\Interface_
                || $statement instanceof Stmt\Trait_
                || $statement instanceof Stmt\Enum_;

            if (! $function && ! $isClassLike) {
                continue;
            }

            // An anonymous class is an expression, never a statement, so a
            // statement of these types always has a name.
            $name = $statement->name?->toString();

            if ($name === null) {
                continue;
            }

            $kind = $function ? 'function' : 'class, interface, trait or enum';
            $shortKind = $function ? 'function' : 'name';
            $held = $function ? $session->hasFunction($name) : $session->hasClassLike($name);
            $seen = $function
                ? isset($functions[strtolower($name)])
                : isset($classLike[strtolower($name)]);

            if ($seen) {
                return CellError::redeclaration(sprintf(
                    'This cell declares the %s "%s" twice. PHP cannot redeclare either, so '
                    .'remove one of them.',
                    $shortKind,
                    $name,
                ), $statement->getStartLine());
            }

            if ($held) {
                return CellError::redeclaration(sprintf(
                    'The %s "%s" was already declared in this session, and PHP cannot redeclare '
                    .'it. Restart the kernel to redefine it.',
                    $kind,
                    $name,
                ), $statement->getStartLine());
            }

            if ($function) {
                $functions[strtolower($name)] = $name;
            } else {
                $classLike[strtolower($name)] = $name;
            }
        }

        return [
            'classLike' => array_values($classLike),
            'functions' => array_values($functions),
        ];
    }

    /**
     * The edit that captures the cell's value, or null when it has none.
     *
     * An assignment, an increment or a `print` is left alone: each is a
     * statement the user wrote for its effect, and a notebook that echoed its
     * value back would be noise. `echo` never reaches here, being a statement
     * rather than an expression.
     *
     * @param  list<Stmt>  $statements
     * @return array{int, int, string}|null
     */
    private function captureLastValue(array $statements): ?array
    {
        $last = end($statements);

        if (! $last instanceof Stmt\Expression) {
            return null;
        }

        $expression = $last->expr;

        $forEffect = $expression instanceof Expr\Assign
            || $expression instanceof Expr\AssignRef
            || $expression instanceof Expr\AssignOp
            || $expression instanceof Expr\PreInc
            || $expression instanceof Expr\PreDec
            || $expression instanceof Expr\PostInc
            || $expression instanceof Expr\PostDec
            || $expression instanceof Expr\Print_;

        if ($forEffect) {
            return null;
        }

        // Inserted at the start of the expression, which is the last thing in
        // the cell, so nothing below it moves.
        return [$expression->getStartFilePos(), 0, '$'.self::RESULT_VARIABLE.' = '];
    }

    /**
     * An edit that overwrites a statement with spaces, newlines kept.
     *
     * @return array{int, int, string}
     */
    private function blank(string $source, Stmt $statement): array
    {
        $start = $statement->getStartFilePos();
        $length = $statement->getEndFilePos() - $start + 1;
        $original = substr($source, $start, $length);

        return [$start, $length, (string) preg_replace('/[^\n]/', ' ', $original)];
    }

    /**
     * Apply edits back to front, so earlier offsets stay valid.
     *
     * @param  list<array{int, int, string}>  $edits
     */
    private function applyEdits(string $source, array $edits): string
    {
        usort($edits, fn (array $a, array $b) => $b[0] <=> $a[0]);

        foreach ($edits as [$offset, $length, $replacement]) {
            $source = substr_replace($source, $replacement, $offset, $length);
        }

        return $source;
    }

    /** The line a single item of a `use` statement sits on. */
    private function useLine(Use_|Stmt\GroupUse $statement, UseItem $use): int
    {
        $line = $use->getStartLine();

        return $line > 0 ? $line : $statement->getStartLine();
    }

    private static function lastSegment(string $name): string
    {
        $last = strrchr($name, '\\');

        return $last === false ? $name : substr($last, 1);
    }
}
