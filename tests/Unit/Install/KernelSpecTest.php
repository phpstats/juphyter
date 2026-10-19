<?php

declare(strict_types=1);

use Juphyter\Install\KernelSpec;

/** The kernelspec the plan's example describes, with every path spelled out. */
function exampleKernelSpec(?string $autoload = null): KernelSpec
{
    return new KernelSpec(
        name: 'php85',
        displayName: 'PHP 8.5',
        python: '/opt/homebrew/bin/python3',
        php: '/Users/erik/Library/Application Support/Herd/bin/php85',
        engine: '/app/vendor/phpstats/juphyter/bin/juphyter',
        pythonPath: '/app/vendor/phpstats/juphyter/kernel',
        autoload: $autoload,
    );
}

describe('the kernel name', function () {
    it('lowercases a name and trims it', function () {
        expect(KernelSpec::normalizeName('  PHP85  '))->toBe('php85');
    });

    it('allows dots, dashes, and underscores', function () {
        expect(KernelSpec::normalizeName('php-8.5_dev'))->toBe('php-8.5_dev');
    });

    it('rejects a name Jupyter cannot select', function (string $name) {
        expect(fn () => KernelSpec::normalizeName($name))
            ->toThrow(RuntimeException::class, 'not a usable kernel name');
    })->with([
        '',
        '   ',
        'php 85',
        'php/85',
        '../escape',
        '.hidden',
        '-leading-dash',
        'php:85',
        'php*',
    ]);

    it('keeps a name out of the parent directory', function () {
        // The name becomes a path segment under the kernels directory, so a
        // separator in it would write outside.
        expect(fn () => KernelSpec::normalizeName('../../etc/php'))
            ->toThrow(RuntimeException::class);
    });
});

describe('the document', function () {
    it('holds the keys Jupyter reads, in the plan\'s order', function () {
        expect(exampleKernelSpec()->toArray())->toBe([
            'argv' => [
                '/opt/homebrew/bin/python3',
                '-m',
                'juphyter_kernel',
                '-f',
                '{connection_file}',
            ],
            'display_name' => 'PHP 8.5',
            'language' => 'php',
            'interrupt_mode' => 'signal',
            'env' => [
                'JUPHYTER_PHP' => '/Users/erik/Library/Application Support/Herd/bin/php85',
                'JUPHYTER_ENGINE' => '/app/vendor/phpstats/juphyter/bin/juphyter',
                'PYTHONPATH' => '/app/vendor/phpstats/juphyter/kernel',
            ],
        ]);
    });

    it('leaves {connection_file} for Jupyter to substitute', function () {
        expect(exampleKernelSpec()->toArray()['argv'])->toContain('{connection_file}');
    });

    it('interrupts by signal, because the wrapper forwards SIGINT', function () {
        expect(exampleKernelSpec()->toArray()['interrupt_mode'])->toBe('signal');
    });

    it('adds JUPHYTER_AUTOLOAD only when an autoloader is given', function () {
        expect(exampleKernelSpec()->toArray()['env'])->not->toHaveKey('JUPHYTER_AUTOLOAD')
            ->and(exampleKernelSpec('')->toArray()['env'])->not->toHaveKey('JUPHYTER_AUTOLOAD')
            ->and(exampleKernelSpec('/app/vendor/autoload.php')->toArray()['env']['JUPHYTER_AUTOLOAD'])
            ->toBe('/app/vendor/autoload.php');
    });

    it('reports its own name', function () {
        expect(exampleKernelSpec()->name())->toBe('php85');
    });
});

describe('the JSON', function () {
    it('round-trips through json_decode', function () {
        expect(json_decode(exampleKernelSpec()->toJson(), true))->toBe(exampleKernelSpec()->toArray());
    });

    it('is indented, unescaped, and ends with a newline', function () {
        $json = exampleKernelSpec()->toJson();

        expect($json)->toStartWith("{\n")
            ->and($json)->toEndWith("}\n")
            ->and($json)->toContain('    "language": "php"')
            ->and($json)->toContain('/opt/homebrew/bin/python3')
            ->and($json)->not->toContain('\\/');
    });
});

describe('recognizing juphyter\'s own kernelspec', function () {
    it('recognizes what it wrote', function () {
        expect(KernelSpec::isJuphyter(exampleKernelSpec()->toArray()))->toBeTrue();
    });

    it('does not claim a kernelspec from another kernel', function (mixed $spec) {
        expect(KernelSpec::isJuphyter($spec))->toBeFalse();
    })->with([
        'a python kernel' => [[
            'argv' => ['python3', '-m', 'ipykernel_launcher'],
            'display_name' => 'Python 3',
            'language' => 'python',
        ]],
        'another PHP kernel' => [[
            'argv' => ['php-kernel'],
            'display_name' => 'PHP',
            'language' => 'php',
        ]],
        'a PHP kernel with an empty engine' => [[
            'language' => 'php',
            'env' => ['JUPHYTER_ENGINE' => ''],
        ]],
        'a PHP kernel whose env is not a map' => [[
            'language' => 'php',
            'env' => 'JUPHYTER_ENGINE=/x',
        ]],
        'nothing at all' => [null],
        'a string' => ['php'],
        'an empty document' => [[]],
    ]);
});
