<?php

declare(strict_types=1);

use Juphyter\Install\VenvBuilder;

/**
 * A runner whose answers are chosen by substring, as the other install tests do.
 *
 * A value may be a closure, which is how a test makes the same command answer
 * differently on a second call: the interpreter that does not run before `venv`
 * and does run after it.
 *
 * @param  array<string, array{code?: int, stdout?: string, stderr?: string}|Closure>  $answers
 * @param  list<string>  $calls
 */
function venvRunner(array $answers, array &$calls = []): Closure
{
    return function (array $argv) use ($answers, &$calls): array {
        $line = implode(' ', $argv);
        $calls[] = $line;

        foreach ($answers as $needle => $answer) {
            if (! str_contains($line, (string) $needle)) {
                continue;
            }

            $resolved = $answer instanceof Closure ? $answer() : $answer;

            return [
                'code' => $resolved['code'] ?? 0,
                'stdout' => $resolved['stdout'] ?? '',
                'stderr' => $resolved['stderr'] ?? '',
            ];
        }

        return ['code' => 127, 'stdout' => '', 'stderr' => 'no such file'];
    };
}

/** A directory holding a fake `python3`, so the builder can find a base interpreter. */
function venvBasePath(): string
{
    static $directory = null;

    if ($directory === null) {
        $directory = sys_get_temp_dir().'/juphyter-venvbase-'.bin2hex(random_bytes(6));
        mkdir($directory, 0o777, true);
        file_put_contents($directory.'/python3', "#!/bin/sh\n");
        chmod($directory.'/python3', 0o755);
    }

    return $directory;
}

/** A temporary directory for a test to build into, removed afterwards. */
function venvWorkspace(): string
{
    $directory = sys_get_temp_dir().'/juphyter-venv-'.bin2hex(random_bytes(6));
    mkdir($directory, 0o777, true);

    return $directory;
}

afterEach(function () {
    foreach (glob(sys_get_temp_dir().'/juphyter-venv-*') ?: [] as $directory) {
        foreach (scandir($directory) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                @unlink($directory.'/'.$entry);
            }
        }

        @rmdir($directory);
    }
});

describe('building an environment', function () {
    it('creates one and installs the packages into its own interpreter', function () {
        $target = venvWorkspace().'/env';
        $ran = 0;
        $calls = [];

        $builder = new VenvBuilder(venvRunner([
            'import sys' => function () use (&$ran): array {
                $ran++;

                // Nothing runs there until `venv` has made it.
                return ['code' => $ran === 1 ? 127 : 0];
            },
            '-m venv' => ['code' => 0],
            'pip install' => ['code' => 0],
        ], $calls), venvBasePath(), ['HOME' => '/home/you']);

        expect($builder->build($target))->toBe($target.'/bin/python');

        expect($calls)->toContain(venvBasePath().'/python3 -m venv '.$target)
            ->and($calls)->toContain($target.'/bin/python -m pip install jupyterlab');
    });

    it('adopts an environment that already runs rather than rebuilding it', function () {
        $target = venvWorkspace().'/env';
        $calls = [];

        $builder = new VenvBuilder(venvRunner([
            'import sys' => ['code' => 0],
            'pip install' => ['code' => 0],
        ], $calls), venvBasePath(), []);

        expect($builder->build($target))->toBe($target.'/bin/python');

        // The packages are installed either way, which is what lets a second
        // run finish an interrupted one.
        expect(implode("\n", $calls))->not->toContain('-m venv')
            ->and($calls)->toContain($target.'/bin/python -m pip install jupyterlab');
    });

    it('installs exactly the documented packages', function () {
        expect(VenvBuilder::PACKAGES)->toBe(['jupyterlab']);
    });

    it('drops a trailing slash from the directory it was given', function () {
        $target = venvWorkspace().'/env';

        $builder = new VenvBuilder(venvRunner([
            'import sys' => ['code' => 0],
            'pip install' => ['code' => 0],
        ]), venvBasePath(), []);

        expect($builder->build($target.'/'))->toBe($target.'/bin/python');
    });
});

describe('refusing to build', function () {
    it('refuses a directory that exists and holds something', function () {
        $target = venvWorkspace();
        file_put_contents($target.'/notes.txt', "mine\n");

        $builder = new VenvBuilder(venvRunner(['import sys' => ['code' => 127]]), venvBasePath(), []);

        expect(fn () => $builder->build($target))
            ->toThrow(RuntimeException::class, 'already exists and is not a virtual environment');
    });

    it('builds into an empty directory that is already there', function () {
        $target = venvWorkspace();
        $ran = 0;

        $builder = new VenvBuilder(venvRunner([
            'import sys' => function () use (&$ran): array {
                $ran++;

                return ['code' => $ran === 1 ? 127 : 0];
            },
            '-m venv' => ['code' => 0],
            'pip install' => ['code' => 0],
        ]), venvBasePath(), []);

        expect($builder->build($target))->toBe($target.'/bin/python');
    });

    it('refuses an empty directory name', function () {
        $builder = new VenvBuilder(venvRunner([]), venvBasePath(), []);

        expect(fn () => $builder->build(''))
            ->toThrow(RuntimeException::class, '--create-venv needs a directory');
    });

    it('says so when PATH holds no Python to build with', function () {
        $builder = new VenvBuilder(venvRunner(['import sys' => ['code' => 127]]), '', []);

        expect(fn () => $builder->build(venvWorkspace().'/env'))
            ->toThrow(RuntimeException::class, 'no python3 on PATH to build a virtual environment');
    });
});

describe('reporting a failure', function () {
    it('reports why venv failed', function () {
        $builder = new VenvBuilder(venvRunner([
            'import sys' => ['code' => 127],
            '-m venv' => ['code' => 1, 'stderr' => 'Error: No module named venv'],
        ]), venvBasePath(), []);

        expect(fn () => $builder->build(venvWorkspace().'/env'))
            ->toThrow(RuntimeException::class, 'No module named venv');
    });

    it('reports a venv that claimed success and left nothing that runs', function () {
        $builder = new VenvBuilder(venvRunner([
            'import sys' => ['code' => 127],
            '-m venv' => ['code' => 0],
        ]), venvBasePath(), []);

        expect(fn () => $builder->build(venvWorkspace().'/env'))
            ->toThrow(RuntimeException::class, 'reported success and');
    });

    it('reports why pip failed, keeping the last lines of it', function () {
        $builder = new VenvBuilder(venvRunner([
            'import sys' => ['code' => 0],
            'pip install' => [
                'code' => 1,
                'stderr' => "line one\nline two\nline three\nline four\n"
                    ."line five\nline six\nthe actual reason",
            ],
        ]), venvBasePath(), []);

        try {
            $builder->build(venvWorkspace().'/env');
            expect(false)->toBeTrue();
        } catch (RuntimeException $e) {
            expect($e->getMessage())->toContain('the actual reason')
                ->and($e->getMessage())->toContain('line three')
                ->and($e->getMessage())->not->toContain('line one');
        }
    });

    it('falls back to the exit code when a failure said nothing', function () {
        $builder = new VenvBuilder(venvRunner([
            'import sys' => ['code' => 0],
            'pip install' => ['code' => 3],
        ]), venvBasePath(), []);

        expect(fn () => $builder->build(venvWorkspace().'/env'))
            ->toThrow(RuntimeException::class, 'exited with code 3');
    });
});

describe('the default directory', function () {
    it('is under HOME', function () {
        $builder = new VenvBuilder(venvRunner([]), '', ['HOME' => '/home/you']);

        expect($builder->defaultDirectory())->toBe('/home/you/.venvs/juphyter');
    });

    it('collapses a trailing slash on HOME', function () {
        $builder = new VenvBuilder(venvRunner([]), '', ['HOME' => '/home/you/']);

        expect($builder->defaultDirectory())->toBe('/home/you/.venvs/juphyter');
    });

    it('is null when HOME is unset, so the caller asks instead of guessing', function () {
        $builder = new VenvBuilder(venvRunner([]), '', []);

        expect($builder->defaultDirectory())->toBeNull();
    });
});
