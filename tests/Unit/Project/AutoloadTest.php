<?php

declare(strict_types=1);

use Juphyter\Project\Autoload;
use Juphyter\Project\Config;

use Juphyter\Tests\Unit\Project\Tree;

/** @param  array<string, string>  $files  Path relative to the root => contents. */
function tree(array $files = []): string
{
    return Tree::make($files);
}

afterEach(fn () => Tree::cleanUp());

/** An environment that holds exactly what it is given. */
function env(array $values): Closure
{
    return static fn (string $name) => $values[$name] ?? false;
}

describe('searching upwards', function () {
    it('finds an autoloader beside the notebook', function () {
        $root = tree(['vendor/autoload.php' => '<?php']);

        expect(Autoload::search($root))->toBe($root.'/vendor/autoload.php');
    });

    it('walks up until it finds one', function () {
        $root = tree(['vendor/autoload.php' => '<?php', 'notebooks/deep/keep' => '']);

        expect(Autoload::search($root.'/notebooks/deep'))->toBe($root.'/vendor/autoload.php');
    });

    it('takes the nearest one, because a package inside a monorepo means the package', function () {
        $root = tree([
            'vendor/autoload.php' => '<?php',
            'packages/inner/vendor/autoload.php' => '<?php',
        ]);

        expect(Autoload::search($root.'/packages/inner'))
            ->toBe($root.'/packages/inner/vendor/autoload.php');
    });

    it('finds nothing when there is nothing, which is not an error', function () {
        $root = tree(['notebooks/keep' => '']);

        // The walk reaches the filesystem root, where this project's own
        // vendor directory is nowhere to be found.
        expect(Autoload::search($root.'/notebooks'))->toBeNull();
    })->skip(Tree::vendorAbove(), 'A vendor directory sits above the temp directory here.');

    it('ignores a vendor directory with no autoloader in it', function () {
        $root = tree(['vendor/keep' => '']);

        expect(Autoload::search($root))->toBeNull();
    })->skip(Tree::vendorAbove(), 'A vendor directory sits above the temp directory here.');

    it('does not fall over on a directory that is not there', function () {
        expect(Autoload::search('/no/such/place/at/all'))->toBeNull();
    });
});

describe('precedence', function () {
    it('prefers the environment over everything', function () {
        $root = tree([
            'vendor/autoload.php' => '<?php',
            'chosen.php' => '<?php',
            'juphyter.json' => '{"autoload": "from-config.php"}',
            'from-config.php' => '<?php',
        ]);

        $located = Autoload::locate(
            $root,
            Config::find($root),
            env([Autoload::ENV => $root.'/chosen.php']),
        );

        expect($located->path)->toBe($root.'/chosen.php')
            ->and($located->source)->toBe(Autoload::FROM_ENV);
    });

    it('prefers the config over the search', function () {
        $root = tree([
            'vendor/autoload.php' => '<?php',
            'juphyter.json' => '{"autoload": "lib/loader.php"}',
            'lib/loader.php' => '<?php',
        ]);

        $located = Autoload::locate($root, Config::find($root), env([]));

        expect($located->path)->toBe($root.'/lib/loader.php')
            ->and($located->source)->toBe(Autoload::FROM_CONFIG);
    });

    it('falls back to the search when nothing names one', function () {
        $root = tree(['vendor/autoload.php' => '<?php']);

        $located = Autoload::locate($root, null, env([]));

        expect($located->path)->toBe($root.'/vendor/autoload.php')
            ->and($located->source)->toBe(Autoload::FROM_SEARCH);
    });

    it('reports finding nothing, rather than failing', function () {
        $root = tree(['notebooks/keep' => '']);

        $located = Autoload::locate($root.'/notebooks', null, env([]));

        expect($located->path)->toBeNull()
            ->and($located->source)->toBe(Autoload::NOT_FOUND);
    })->skip(Tree::vendorAbove(), 'A vendor directory sits above the temp directory here.');

    it('treats an empty environment variable as unset', function () {
        $root = tree(['vendor/autoload.php' => '<?php']);

        expect(Autoload::locate($root, null, env([Autoload::ENV => '']))->source)
            ->toBe(Autoload::FROM_SEARCH);
    });
});

describe('an autoloader that was named but is not there', function () {
    // Named and missing is an error, because the user asked for that autoloader
    // and would otherwise get a session that merely cannot see their classes.
    it('refuses a missing path from the environment, naming the variable', function () {
        $root = tree([]);

        expect(fn () => Autoload::locate($root, null, env([Autoload::ENV => $root.'/absent.php'])))
            ->toThrow(RuntimeException::class, Autoload::ENV);
    });

    it('refuses a missing path from the config, naming the file', function () {
        $root = tree(['juphyter.json' => '{"autoload": "absent.php"}']);

        expect(fn () => Autoload::locate($root, Config::find($root), env([])))
            ->toThrow(RuntimeException::class, Config::FILE);
    });

    it('says where it looked', function () {
        $root = tree([]);

        expect(fn () => Autoload::locate($root, null, env([Autoload::ENV => $root.'/absent.php'])))
            ->toThrow(RuntimeException::class, $root.'/absent.php');
    });
});

describe('loading', function () {
    it('requires the file it found', function () {
        $root = tree(['vendor/autoload.php' => '<?php $GLOBALS["juphyter_loaded"] = true;']);
        unset($GLOBALS['juphyter_loaded']);

        Autoload::locate($root, null, env([]))->load();

        expect($GLOBALS['juphyter_loaded'] ?? false)->toBeTrue();

        unset($GLOBALS['juphyter_loaded']);
    });

    it('does nothing when it found nothing', function () {
        $root = tree(['notebooks/keep' => '']);

        Autoload::locate($root.'/notebooks', null, env([]))->load();

        expect(true)->toBeTrue();
    })->skip(Tree::vendorAbove(), 'A vendor directory sits above the temp directory here.');
});
