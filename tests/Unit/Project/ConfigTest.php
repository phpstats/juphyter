<?php

declare(strict_types=1);

use Juphyter\Project\Config;
use Juphyter\Tests\Unit\Project\Tree;

afterEach(fn () => Tree::cleanUp());

describe('finding the file', function () {
    it('reads a juphyter.json beside the notebook', function () {
        $root = Tree::make(['juphyter.json' => '{"memory_limit": "512M"}']);

        expect(Config::find($root)?->memoryLimit)->toBe('512M');
    });

    it('walks up, so one file at the top of a project serves its subdirectories', function () {
        $root = Tree::make(['juphyter.json' => '{"memory_limit": "256M"}', 'notebooks/deep/keep' => '']);

        $config = Config::find($root.'/notebooks/deep');

        expect($config?->memoryLimit)->toBe('256M')
            ->and($config?->directory)->toBe($root);
    });

    it('takes the nearest one', function () {
        $root = Tree::make([
            'juphyter.json' => '{"memory_limit": "128M"}',
            'notebooks/juphyter.json' => '{"memory_limit": "1G"}',
        ]);

        expect(Config::find($root.'/notebooks')?->memoryLimit)->toBe('1G');
    });

    it('finds nothing when there is none', function () {
        expect(Config::find(Tree::make(['keep' => ''])))->toBeNull();
    });

    it('does not fall over on a directory that is not there', function () {
        expect(Config::find('/no/such/place/at/all'))->toBeNull();
    });
});

describe('reading the file', function () {
    it('reads all three settings', function () {
        $root = Tree::make(['juphyter.json' => json_encode([
            'autoload' => 'vendor/autoload.php',
            'memory_limit' => '1G',
            'include_path' => 'lib',
        ])]);

        $config = Config::find($root);

        expect($config?->autoload)->toBe('vendor/autoload.php')
            ->and($config?->memoryLimit)->toBe('1G')
            ->and($config?->includePath)->toBe('lib');
    });

    it('leaves out what the file does not say', function () {
        $config = Config::find(Tree::make(['juphyter.json' => '{}']));

        expect($config?->autoload)->toBeNull()
            ->and($config?->memoryLimit)->toBeNull()
            ->and($config?->includePath)->toBeNull();
    });

    it('ignores a setting of the wrong type or an empty one', function () {
        $config = Config::find(Tree::make(['juphyter.json' => '{"autoload": 42, "memory_limit": ""}']));

        expect($config?->autoload)->toBeNull()
            ->and($config?->memoryLimit)->toBeNull();
    });

    it('resolves a relative autoload path against the file, not the working directory', function () {
        $root = Tree::make(['notebooks/juphyter.json' => '{"autoload": "../vendor/autoload.php"}']);

        expect(Config::find($root.'/notebooks')?->autoloadPath())
            ->toBe($root.'/notebooks/../vendor/autoload.php');
    });

    it('leaves an absolute autoload path alone', function () {
        $root = Tree::make(['juphyter.json' => '{"autoload": "/opt/loader.php"}']);

        expect(Config::find($root)?->autoloadPath())->toBe('/opt/loader.php');
    });

    it('refuses invalid JSON, rather than ignoring a file the user wrote on purpose', function () {
        $root = Tree::make(['juphyter.json' => '{not json']);

        expect(fn () => Config::find($root))
            ->toThrow(RuntimeException::class, 'not valid JSON');
    });

    it('refuses a file that is not a JSON object', function () {
        $root = Tree::make(['juphyter.json' => '[1, 2, 3]']);

        expect(fn () => Config::find($root))
            ->toThrow(RuntimeException::class, 'JSON object');
    });
});

describe('applying the settings', function () {
    it('sets the memory limit', function () {
        $root = Tree::make(['juphyter.json' => '{"memory_limit": "321M"}']);
        $before = ini_get('memory_limit');

        try {
            Config::find($root)?->apply();

            expect(ini_get('memory_limit'))->toBe('321M');
        } finally {
            ini_set('memory_limit', (string) $before);
        }
    });

    it('prepends the include path rather than replacing it', function () {
        $root = Tree::make(['juphyter.json' => '{"include_path": "lib"}']);
        $before = (string) ini_get('include_path');

        try {
            Config::find($root)?->apply();

            expect(ini_get('include_path'))->toStartWith($root.'/lib'.PATH_SEPARATOR)
                ->and(ini_get('include_path'))->toContain($before);
        } finally {
            ini_set('include_path', $before);
        }
    });

    it('changes nothing it was not told to change', function () {
        $root = Tree::make(['juphyter.json' => '{"autoload": "vendor/autoload.php"}']);
        $memory = ini_get('memory_limit');
        $path = ini_get('include_path');

        Config::find($root)?->apply();

        expect(ini_get('memory_limit'))->toBe($memory)
            ->and(ini_get('include_path'))->toBe($path);
    });
});
