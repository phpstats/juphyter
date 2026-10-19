<?php

declare(strict_types=1);

use Juphyter\Console\Options;

const USAGE = "Usage: juphyter thing [options]\n";

describe('parsing', function () {
    it('reads an option and its value as two arguments', function () {
        expect(Options::parse(['--name', 'php85'], ['name'], USAGE))->toBe(['name' => 'php85']);
    });

    it('reads an option joined to its value by an equals sign', function () {
        expect(Options::parse(['--name=php85'], ['name'], USAGE))->toBe(['name' => 'php85']);
    });

    it('reads both forms together, in any order', function () {
        expect(Options::parse(['--b=two', '--a', 'one'], ['a', 'b'], USAGE))
            ->toBe(['b' => 'two', 'a' => 'one']);
    });

    it('reads nothing from nothing', function () {
        expect(Options::parse([], ['name'], USAGE))->toBe([]);
    });

    it('keeps a value that contains an equals sign', function () {
        expect(Options::parse(['--display-name=PHP 8.5 = fast'], ['display-name'], USAGE))
            ->toBe(['display-name' => 'PHP 8.5 = fast']);
    });

    it('keeps an empty value given with =', function () {
        expect(Options::parse(['--name='], ['name'], USAGE))->toBe(['name' => '']);
    });

    it('lets a later option win over an earlier one', function () {
        expect(Options::parse(['--name', 'first', '--name', 'second'], ['name'], USAGE))
            ->toBe(['name' => 'second']);
    });
});

describe('complaints', function () {
    it('refuses a stray argument, and shows the usage', function () {
        expect(fn () => Options::parse(['loose'], ['name'], USAGE))
            ->toThrow(RuntimeException::class, 'unexpected argument "loose"');
    });

    it('refuses an unknown option, and shows the usage', function () {
        expect(fn () => Options::parse(['--nope', 'x'], ['name'], USAGE))
            ->toThrow(RuntimeException::class, 'unknown option "--nope"');
    });

    it('refuses an option with no value', function () {
        expect(fn () => Options::parse(['--name'], ['name'], USAGE))
            ->toThrow(RuntimeException::class, '--name needs a value');
    });

    it('refuses an option whose value is the next option', function () {
        expect(fn () => Options::parse(['--name', '--prefix', 'p'], ['name', 'prefix'], USAGE))
            ->toThrow(RuntimeException::class, '--name needs a value');
    });

    it('includes the command\'s own usage in a complaint', function () {
        try {
            Options::parse(['--nope', 'x'], ['name'], USAGE);
        } catch (RuntimeException $e) {
            expect($e->getMessage())->toContain(USAGE);
        }
    });
});

describe('help', function () {
    it('spots --help and -h anywhere in the arguments', function () {
        expect(Options::wantsHelp(['--help']))->toBeTrue()
            ->and(Options::wantsHelp(['-h']))->toBeTrue()
            ->and(Options::wantsHelp(['--name', 'x', '--help']))->toBeTrue();
    });

    it('spots help even beside something malformed, so help still works', function () {
        expect(Options::wantsHelp(['--nonsense', '--help']))->toBeTrue();
    });

    it('is false when help was not asked for', function () {
        expect(Options::wantsHelp([]))->toBeFalse()
            ->and(Options::wantsHelp(['--name', 'x']))->toBeFalse()
            ->and(Options::wantsHelp(['--helpful', 'x']))->toBeFalse();
    });
});
