<?php

declare(strict_types=1);

use Juphyter\Project\Autoload;
use Juphyter\Tests\Engine\Harness;
use Juphyter\Tests\Unit\Project\Tree;

/**
 * Project discovery through a real engine process, which is the only way to see
 * it work: the engine loads the project before it serves anything, and whether
 * a notebook can see its own classes is the whole point.
 */

/** A project tree holding a working autoloader that declares one class. */
function project(string $class, array $extra = []): string
{
    return Tree::make([
        'vendor/autoload.php' => '<?php class '.$class.' { public const WHERE = "vendor"; }',
        ...$extra,
    ]);
}

function engineIn(string $directory, array $environment = [], array $arguments = []): Harness
{
    $harness = Harness::start($directory, $environment, $arguments);
    $GLOBALS['juphyter_project_harnesses'][] = $harness;

    return $harness;
}

beforeEach(function () {
    $GLOBALS['juphyter_project_harnesses'] = [];
});

afterEach(function () {
    foreach ($GLOBALS['juphyter_project_harnesses'] ?? [] as $harness) {
        $harness->stop();
    }

    $GLOBALS['juphyter_project_harnesses'] = [];
    Tree::cleanUp();
});

/** The text/plain of the first result event. */
function resultText(array $events): ?string
{
    foreach ($events as $event) {
        if ($event['type'] === 'result') {
            return $event['bundle']['text/plain'] ?? null;
        }
    }

    return null;
}

describe('the walk up', function () {
    it('gives a notebook its own project with no configuration at all', function () {
        $root = project('ProjectWalkOne');
        $engine = engineIn($root);

        expect(resultText($engine->request('execute', ['code' => 'ProjectWalkOne::WHERE;'])))
            ->toBe("'vendor'");
    });

    it('finds the project from a notebook in a subdirectory', function () {
        $root = project('ProjectWalkTwo', ['notebooks/deep/keep' => '']);
        $engine = engineIn($root.'/notebooks/deep');

        expect(resultText($engine->request('execute', ['code' => 'ProjectWalkTwo::WHERE;'])))
            ->toBe("'vendor'");
    });

    it('serves perfectly well with no project at all', function () {
        $root = Tree::make(['keep' => '']);
        $engine = engineIn($root);

        expect(resultText($engine->request('execute', ['code' => '1 + 1;'])))->toBe('2');
    });
});

describe('JUPHYTER_AUTOLOAD', function () {
    it('overrides the walk', function () {
        $root = project('ProjectEnvVendor', [
            'chosen.php' => '<?php class ProjectEnvChosen { public const WHERE = "chosen"; }',
        ]);
        $engine = engineIn($root, [Autoload::ENV => $root.'/chosen.php']);

        $events = $engine->request('execute', [
            'code' => 'ProjectEnvChosen::WHERE . "/" . (class_exists("ProjectEnvVendor") ? "also" : "only");',
        ]);

        expect(resultText($events))->toBe("'chosen/only'");
    });

    it('is reported as an engine error when it names nothing, rather than hanging', function () {
        $root = Tree::make(['keep' => '']);
        $engine = engineIn($root, [Autoload::ENV => $root.'/absent.php']);

        $error = $engine->next();

        expect($error['id'])->toBeNull()
            ->and($error['type'])->toBe('error')
            ->and($error['evalue'])->toContain(Autoload::ENV)
            ->and($error['evalue'])->toContain('absent.php')
            ->and($engine->wait())->toBe(1);
    });
});

describe('juphyter.json', function () {
    it('names the autoloader, and beats the walk', function () {
        $root = project('ProjectConfigVendor', [
            'juphyter.json' => '{"autoload": "lib/loader.php"}',
            'lib/loader.php' => '<?php class ProjectConfigChosen { public const WHERE = "config"; }',
        ]);
        $engine = engineIn($root);

        expect(resultText($engine->request('execute', ['code' => 'ProjectConfigChosen::WHERE;'])))
            ->toBe("'config'");
    });

    it('loses to the environment', function () {
        $root = Tree::make([
            'juphyter.json' => '{"autoload": "from-config.php"}',
            'from-config.php' => '<?php class ProjectBothConfig {}',
            'from-env.php' => '<?php class ProjectBothEnv { public const WHERE = "env"; }',
        ]);
        $engine = engineIn($root, [Autoload::ENV => $root.'/from-env.php']);

        $events = $engine->request('execute', [
            'code' => 'ProjectBothEnv::WHERE . "/" . (class_exists("ProjectBothConfig") ? "also" : "only");',
        ]);

        expect(resultText($events))->toBe("'env/only'");
    });

    it('sets the memory limit the cells run under', function () {
        $root = Tree::make(['juphyter.json' => '{"memory_limit": "321M"}']);
        $engine = engineIn($root);

        expect(resultText($engine->request('execute', ['code' => 'ini_get("memory_limit");'])))
            ->toBe("'321M'");
    });

    it('adds to the include path without losing what was there', function () {
        $root = Tree::make(['juphyter.json' => '{"include_path": "lib"}', 'lib/keep' => '']);
        $engine = engineIn($root);

        expect(resultText($engine->request('execute', ['code' => 'ini_get("include_path");'])))
            ->toContain($root.'/lib');
    });

    it('applies its settings even when it names no autoloader', function () {
        $root = project('ProjectConfigBoth', ['juphyter.json' => '{"memory_limit": "234M"}']);
        $engine = engineIn($root);

        $events = $engine->request('execute', [
            'code' => 'ini_get("memory_limit") . "/" . ProjectConfigBoth::WHERE;',
        ]);

        expect(resultText($events))->toBe("'234M/vendor'");
    });

    it('is reported as an engine error when it is not valid JSON', function () {
        $root = Tree::make(['juphyter.json' => '{not json']);
        $engine = engineIn($root);

        $error = $engine->next();

        expect($error['type'])->toBe('error')
            ->and($error['evalue'])->toContain('not valid JSON')
            ->and($engine->wait())->toBe(1);
    });
});

describe('the --autoload and --directory options', function () {
    it('takes an autoloader from the command line', function () {
        $root = Tree::make(['given.php' => '<?php class ProjectGiven { public const WHERE = "given"; }']);
        $engine = engineIn($root, [], ['--autoload', $root.'/given.php']);

        expect(resultText($engine->request('execute', ['code' => 'ProjectGiven::WHERE;'])))->toBe("'given'");
    });

    it('takes the notebook directory from the command line', function () {
        $root = project('ProjectElsewhere');
        $engine = engineIn(sys_get_temp_dir(), [], ['--directory', $root]);

        expect(resultText($engine->request('execute', ['code' => 'ProjectElsewhere::WHERE;'])))
            ->toBe("'vendor'");
    });
});

describe('juphyter loading twice', function () {
    it('survives a project that carries its own copy of juphyter', function () {
        // Two Composer autoloaders, each with its own include-once
        // bookkeeping, would require src/functions.php twice and die
        // redeclaring Juphyter\display. The guard in that file is what stops
        // it, and this is the test that would catch its removal.
        $root = Tree::make([
            'vendor/autoload.php' => '<?php require '.var_export(dirname(__DIR__, 2).'/vendor/autoload.php', true).';',
        ]);
        $engine = engineIn($root);

        $events = $engine->request('execute', ['code' => 'function_exists("Juphyter\\\\display");']);

        expect(resultText($events))->toBe('true')
            ->and($engine->stderr())->toBe('');
    });
});
