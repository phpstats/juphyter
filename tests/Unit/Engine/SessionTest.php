<?php

declare(strict_types=1);

use Juphyter\Engine\CleanedCell;
use Juphyter\Engine\Session;
use PhpParser\Node\Stmt\Use_;

describe('imports', function () {
    it('starts with nothing imported', function () {
        expect((new Session())->importStatements())->toBe([]);
    });

    it('renders a class import, and leaves out a redundant alias', function () {
        $session = new Session();
        $session->import(Use_::TYPE_NORMAL, 'Foo\\Bar', 'Bar');
        $session->import(Use_::TYPE_NORMAL, 'Foo\\Baz', 'Z');

        expect($session->importStatements())->toBe(['use Foo\\Bar;', 'use Foo\\Baz as Z;']);
    });

    it('renders function and constant imports with their keyword', function () {
        $session = new Session();
        $session->import(Use_::TYPE_FUNCTION, 'Foo\\bar', 'bar');
        $session->import(Use_::TYPE_CONSTANT, 'Foo\\BAZ', 'BAZ');

        expect($session->importStatements())->toBe(['use const Foo\\BAZ;', 'use function Foo\\bar;']);
    });

    it('renders an unnamespaced import', function () {
        $session = new Session();
        $session->import(Use_::TYPE_NORMAL, 'ArrayObject', 'AO');

        expect($session->importStatements())->toBe(['use ArrayObject as AO;']);
    });

    it('keeps the statements sorted, so one session always prints the same', function () {
        $first = new Session();
        $first->import(Use_::TYPE_NORMAL, 'B\\Two', 'Two');
        $first->import(Use_::TYPE_NORMAL, 'A\\One', 'One');

        $second = new Session();
        $second->import(Use_::TYPE_NORMAL, 'A\\One', 'One');
        $second->import(Use_::TYPE_NORMAL, 'B\\Two', 'Two');

        expect($first->importStatements())->toBe($second->importStatements());
    });

    it('replaces an earlier binding of the same alias', function () {
        $session = new Session();
        $session->import(Use_::TYPE_NORMAL, 'ArrayObject', 'C');
        $session->import(Use_::TYPE_NORMAL, 'SplStack', 'C');

        expect($session->importStatements())->toBe(['use SplStack as C;'])
            ->and($session->importedName(Use_::TYPE_NORMAL, 'C'))->toBe('SplStack');
    });

    it('keeps the three use types apart, as PHP does', function () {
        $session = new Session();
        $session->import(Use_::TYPE_NORMAL, 'Some\\X', 'X');
        $session->import(Use_::TYPE_FUNCTION, 'some\\x', 'X');
        $session->import(Use_::TYPE_CONSTANT, 'Some\\X_CONST', 'X');

        expect($session->importStatements())->toHaveCount(3)
            ->and($session->importedName(Use_::TYPE_NORMAL, 'X'))->toBe('Some\\X')
            ->and($session->importedName(Use_::TYPE_FUNCTION, 'X'))->toBe('some\\x')
            ->and($session->importedName(Use_::TYPE_CONSTANT, 'X'))->toBe('Some\\X_CONST');
    });

    it('treats a class alias case-insensitively, as PHP does', function () {
        $session = new Session();
        $session->import(Use_::TYPE_NORMAL, 'ArrayObject', 'AO');

        expect($session->hasImport(Use_::TYPE_NORMAL, 'ao'))->toBeTrue()
            ->and($session->hasImport(Use_::TYPE_NORMAL, 'AO'))->toBeTrue();
    });

    it('treats a function alias case-insensitively, as PHP does', function () {
        $session = new Session();
        $session->import(Use_::TYPE_FUNCTION, 'foo\\bar', 'Baz');

        expect($session->hasImport(Use_::TYPE_FUNCTION, 'baz'))->toBeTrue();
    });

    it('treats a constant alias case-sensitively, as PHP does', function () {
        $session = new Session();
        $session->import(Use_::TYPE_CONSTANT, 'Foo\\BAR', 'BAR');

        expect($session->hasImport(Use_::TYPE_CONSTANT, 'BAR'))->toBeTrue()
            ->and($session->hasImport(Use_::TYPE_CONSTANT, 'bar'))->toBeFalse();
    });

    it('reports nothing for an alias it does not hold', function () {
        expect((new Session())->importedName(Use_::TYPE_NORMAL, 'Nope'))->toBeNull();
    });
});

describe('declared names', function () {
    it('starts with nothing declared', function () {
        $session = new Session();

        expect($session->declaredClassLike())->toBe([])
            ->and($session->declaredFunctions())->toBe([]);
    });

    it('remembers a class-like name and keeps the spelling the cell used', function () {
        $session = new Session();
        $session->declareClassLike('Widget');

        expect($session->hasClassLike('Widget'))->toBeTrue()
            ->and($session->declaredClassLike())->toBe(['Widget']);
    });

    it('matches a class-like name case-insensitively, as PHP does', function () {
        $session = new Session();
        $session->declareClassLike('Widget');

        expect($session->hasClassLike('widget'))->toBeTrue()
            ->and($session->hasClassLike('WIDGET'))->toBeTrue();
    });

    it('matches a function name case-insensitively, as PHP does', function () {
        $session = new Session();
        $session->declareFunction('doThing');

        expect($session->hasFunction('dothing'))->toBeTrue()
            ->and($session->declaredFunctions())->toBe(['doThing']);
    });

    it('keeps class-like names and functions apart, because PHP does', function () {
        $session = new Session();
        $session->declareClassLike('Thing');
        $session->declareFunction('Thing');

        expect($session->hasClassLike('Thing'))->toBeTrue()
            ->and($session->hasFunction('Thing'))->toBeTrue()
            ->and($session->declaredClassLike())->toBe(['Thing'])
            ->and($session->declaredFunctions())->toBe(['Thing']);
    });

    it('holds one entry for a name declared twice', function () {
        $session = new Session();
        $session->declareClassLike('Widget');
        $session->declareClassLike('widget');

        expect($session->declaredClassLike())->toHaveCount(1);
    });
});

describe('committing declarations', function () {
    it('records a name that exists in the runtime', function () {
        $session = new Session();
        $name = 'SessionCommitted_'.bin2hex(random_bytes(3));
        eval("class {$name} {}");

        $session->commitDeclarations(new CleanedCell('', 1, null, [$name]));

        expect($session->declaredClassLike())->toBe([$name]);
    });

    it('ignores a name that does not exist, however valid the cell was', function () {
        $session = new Session();

        $session->commitDeclarations(new CleanedCell('', 1, null, ['NeverDeclared_'.bin2hex(random_bytes(3))]));

        expect($session->declaredClassLike())->toBe([]);
    });

    it('records an interface, a trait and an enum, not only a class', function () {
        $session = new Session();
        $suffix = bin2hex(random_bytes(3));
        $names = ["I_{$suffix}", "T_{$suffix}", "E_{$suffix}"];
        eval("interface {$names[0]} {} trait {$names[1]} {} enum {$names[2]} {}");

        $session->commitDeclarations(new CleanedCell('', 1, null, $names));

        expect($session->declaredClassLike())->toHaveCount(3);
    });

    it('records a function that exists', function () {
        $session = new Session();
        $name = 'session_committed_'.bin2hex(random_bytes(3));
        eval("function {$name}() {}");

        $session->commitDeclarations(new CleanedCell('', 1, null, [], [$name]));

        expect($session->declaredFunctions())->toBe([$name]);
    });

    it('ignores a function that does not exist', function () {
        $session = new Session();

        $session->commitDeclarations(new CleanedCell('', 1, null, [], ['never_declared_'.bin2hex(random_bytes(3))]));

        expect($session->declaredFunctions())->toBe([]);
    });

    it('does not count a name only the autoloader could find', function () {
        $session = new Session();

        // A real class of this package's, which is autoloadable but was not
        // declared by any cell. Loading it on the session's behalf would be
        // wrong, so the checks run with autoloading off.
        $session->commitDeclarations(new CleanedCell('', 1, null, ['Juphyter\\Console\\NotLoadedYet']));

        expect($session->declaredClassLike())->toBe([]);
    });

    it('commits nothing for a cell that declares nothing', function () {
        $session = new Session();

        $session->commitDeclarations(new CleanedCell('', 1));

        expect($session->declaredClassLike())->toBe([])
            ->and($session->declaredFunctions())->toBe([]);
    });
});
