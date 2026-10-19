<?php

declare(strict_types=1);

/**
 * The PHAR build, and the two commands run from inside it.
 *
 * The PHAR is the install route for someone who wants a PHP kernel without
 * creating a Composer project, so it has to dispatch and install exactly as the
 * checkout does. Two things make that worth asserting rather than assuming.
 * The dispatcher discovers commands with `scandir()` over a `phar://` path, and
 * the install command has to name the archive's real path on disk in
 * `JUPHYTER_ENGINE` and copy the Python wrapper out of the archive, because
 * Python cannot import from inside one.
 *
 * No Python is involved. A fake `python3` on a fake `PATH` answers the two
 * questions the probe asks, so these tests run wherever the suite runs.
 *
 * The build itself needs `composer`, which is how the production dependency
 * tree gets into the archive. Without it, every test here is skipped with that
 * reason rather than passing on nothing.
 */

/** The repository root. */
function pharRoot(): string
{
    return dirname(__DIR__, 3);
}

/** A directory that lives for the whole file, holding the built archive. */
function pharScratch(): string
{
    static $directory = null;

    if ($directory === null) {
        $directory = sys_get_temp_dir().'/juphyter-phar-test-'.bin2hex(random_bytes(6));
        mkdir($directory, 0o777, true);
    }

    return $directory;
}

/** Delete a directory tree. */
function pharRemoveTree(string $path): void
{
    if (is_dir($path) && ! is_link($path)) {
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                pharRemoveTree($path.'/'.$entry);
            }
        }

        rmdir($path);

        return;
    }

    if (file_exists($path) || is_link($path)) {
        unlink($path);
    }
}

/** Whether `composer` is on `PATH`, which the build needs. */
function pharHasComposer(): bool
{
    foreach (explode(PATH_SEPARATOR, (string) getenv('PATH')) as $directory) {
        if ($directory !== '' && is_executable(rtrim($directory, '/').'/composer')) {
            return true;
        }
    }

    return false;
}

/**
 * Run a command with a controlled environment, and return its result.
 *
 * @param  list<string>  $command
 * @param  array<string, string>  $environment
 * @return array{code: int, stdout: string, stderr: string}
 */
function pharRun(array $command, array $environment): array
{
    $process = proc_open(
        $command,
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        pharScratch(),
        $environment,
    );

    if (! is_resource($process)) {
        return ['code' => 127, 'stdout' => '', 'stderr' => 'could not start'];
    }

    $stdout = (string) stream_get_contents($pipes[1]);
    $stderr = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    return ['code' => proc_close($process), 'stdout' => $stdout, 'stderr' => $stderr];
}

/**
 * Build the archive once, and return its path.
 *
 * The build output is kept so that a failure reports what the script said.
 */
function pharArchive(): string
{
    static $built = null;

    if ($built === null) {
        $output = pharScratch().'/juphyter.phar';

        $build = pharRun(
            [PHP_BINARY, pharRoot().'/scripts/build-phar.php', '--output='.$output, '--quiet'],
            ['PATH' => (string) getenv('PATH'), 'HOME' => (string) getenv('HOME')],
        );

        $built = [
            'path' => $output,
            'code' => $build['code'],
            'output' => trim($build['stdout']."\n".$build['stderr']),
        ];
    }

    expect($built['code'])->toBe(0, 'The PHAR build failed: '.$built['output']);
    expect($built['path'])->toBeFile();

    return $built['path'];
}

/**
 * A directory holding a fake `python3` that answers the probe's two questions.
 *
 * The probe asks for `sys.executable` and for the `ipykernel` version, in that
 * order, and reads the first line of standard output for each.
 */
function pharFakePythonPath(): string
{
    static $directory = null;

    if ($directory === null) {
        $directory = pharScratch().'/bin';
        mkdir($directory, 0o777, true);

        file_put_contents($directory.'/python3', <<<'SH'
            #!/bin/sh
            case "$2" in
                *sys.executable*) echo "$0" ;;
                *ipykernel*) echo "7.4.0" ;;
                *) echo "unexpected: $*" >&2; exit 1 ;;
            esac
            SH);

        chmod($directory.'/python3', 0o755);
    }

    return $directory;
}

/**
 * Run the built archive with the fake Python on `PATH`.
 *
 * @param  list<string>  $arguments
 * @return array{code: int, stdout: string, stderr: string}
 */
function pharJuphyter(array $arguments, ?string $archive = null): array
{
    return pharRun(
        array_merge([PHP_BINARY, $archive ?? pharArchive()], $arguments),
        ['PATH' => pharFakePythonPath(), 'HOME' => pharScratch()],
    );
}

/** A temporary install prefix. */
function pharPrefix(): string
{
    $prefix = pharScratch().'/prefix-'.bin2hex(random_bytes(6));
    mkdir($prefix, 0o777, true);

    return $prefix;
}

/**
 * A compiled Python file in the checkout's own `kernel/` directory.
 *
 * Running the protocol tests leaves bytecode there, and the build stages
 * `kernel/` wholesale, so without an exclusion the archive would carry a
 * `.pyc` pinned to one CPython version and copy it beside every kernelspec a
 * PHAR install writes. The file is made before the archive is built and
 * removed afterwards; it is ignored by git either way.
 */
function pharBytecode(): string
{
    return pharRoot().'/kernel/juphyter_kernel/__pycache__/juphyter-phar-test.pyc';
}

beforeEach(function () {
    if (! pharHasComposer()) {
        $this->markTestSkipped('Building the PHAR needs composer on PATH.');
    }
});

beforeAll(function () {
    if (! is_dir(dirname(pharBytecode()))) {
        mkdir(dirname(pharBytecode()), 0o777, true);
    }

    file_put_contents(pharBytecode(), "not really bytecode\n");
});

afterAll(function () {
    @unlink(pharBytecode());
    @rmdir(dirname(pharBytecode()));
    pharRemoveTree(pharScratch());
});

describe('the build', function () {
    it('produces a runnable archive', function () {
        expect(pharArchive())->toBeFile()
            ->and(filesize(pharArchive()))->toBeGreaterThan(0)
            ->and(is_executable(pharArchive()))->toBeTrue();
    });

    it('carries the production dependencies and not the dev ones', function () {
        $phar = new Phar(pharArchive());

        expect($phar->offsetExists('vendor/autoload.php'))->toBeTrue()
            ->and($phar->offsetExists('src/Console/Application.php'))->toBeTrue()
            ->and($phar->offsetExists('src/Console/InstallCommand.php'))->toBeTrue()
            ->and($phar->offsetExists('vendor/nikic/php-parser/lib/PhpParser/Parser.php'))->toBeTrue()
            ->and($phar->offsetExists('vendor/pestphp/pest/src/Plugin.php'))->toBeFalse()
            ->and($phar->offsetExists('tests/Pest.php'))->toBeFalse();
    });

    it('carries the Python wrapper, which a PHAR install copies out of it', function () {
        $phar = new Phar(pharArchive());

        expect($phar->offsetExists('kernel/juphyter_kernel/__init__.py'))->toBeTrue()
            ->and($phar->offsetExists('kernel/juphyter_kernel/__main__.py'))->toBeTrue()
            ->and($phar->offsetExists('kernel/juphyter_kernel/kernel.py'))->toBeTrue();
    });

    it('leaves compiled Python bytecode out, however the checkout looks', function () {
        $phar = new Phar(pharArchive());
        $compiled = [];

        foreach (new RecursiveIteratorIterator($phar) as $path => $file) {
            $relative = substr((string) $path, strlen('phar://'.pharArchive().'/'));

            if (str_ends_with($relative, '.pyc') || str_contains($relative, '__pycache__')) {
                $compiled[] = $relative;
            }
        }

        expect(pharBytecode())->toBeFile('the fixture bytecode was not created')
            ->and($compiled)->toBe([]);
    });

    it('drops the shebang of bin/juphyter, which would otherwise be printed', function () {
        $phar = new Phar(pharArchive());
        $entry = file_get_contents('phar://'.pharArchive().'/bin/juphyter');

        expect($phar->offsetExists('bin/juphyter'))->toBeTrue()
            ->and($entry)->toStartWith('<?php');
    });
});

describe('dispatching from the archive', function () {
    it('lists the commands it holds, because scandir() reads phar:// paths', function () {
        $run = pharJuphyter([]);

        expect($run['code'])->toBe(0)
            ->and($run['stdout'])
            ->toContain('Usage: juphyter <command>')
            ->toContain('install')
            ->toContain('uninstall');
    });

    it('lists every command the checkout lists', function () {
        $checkout = pharRun(
            [PHP_BINARY, pharRoot().'/bin/juphyter'],
            ['PATH' => pharFakePythonPath(), 'HOME' => pharScratch()],
        );

        expect(pharJuphyter([])['stdout'])->toBe($checkout['stdout']);
    });

    it('rejects an unknown command with usage and a non-zero code', function () {
        $run = pharJuphyter(['nope']);

        expect($run['code'])->not->toBe(0)
            ->and($run['stderr'])
            ->toContain('unknown command "nope"')
            ->toContain('Usage: juphyter <command>');
    });

    it('dispatches a command it holds', function () {
        $run = pharJuphyter(['install', '--help']);

        expect($run['code'])->toBe(0)
            ->and($run['stdout'])->toContain('Usage: juphyter install [options]');
    });
});

describe('installing from the archive', function () {
    it('writes a kernelspec that names the archive as the engine', function () {
        $prefix = pharPrefix();
        $run = pharJuphyter(['install', '--prefix='.$prefix, '--name=php-phar']);

        expect($run['code'])->toBe(0);

        $directory = $prefix.'/share/jupyter/kernels/php-phar';
        $spec = json_decode((string) file_get_contents($directory.'/kernel.json'), true);

        expect($spec['language'])->toBe('php')
            ->and($spec['interrupt_mode'])->toBe('signal')
            ->and($spec['argv'])->toBe([
                pharFakePythonPath().'/python3',
                '-m',
                'juphyter_kernel',
                '-f',
                '{connection_file}',
            ])
            // The path on disk rather than a phar:// URL, because the wrapper
            // runs this file with the PHP binary. Phar::running() reports it
            // with symlinks resolved, which is why realpath() is on both sides.
            ->and($spec['env']['JUPHYTER_ENGINE'])->toBe(realpath(pharArchive()))
            ->and($spec['env']['JUPHYTER_ENGINE'])->not->toContain('phar://')
            ->and($spec['env']['JUPHYTER_ENGINE'])->toEndWith('/juphyter.phar')
            ->and($spec['env']['JUPHYTER_PHP'])->toBe(PHP_BINARY)
            // Beside the kernelspec, because Python cannot import from a PHAR.
            ->and($spec['env']['PYTHONPATH'])->toBe($directory.'/kernel');
    });

    it('copies the Python wrapper out of the archive', function () {
        $archive = pharScratch().'/with-kernel.phar';
        copy(pharArchive(), $archive);

        $added = pharRun([
            PHP_BINARY, '-d', 'phar.readonly=0', '-r',
            '$p = new Phar($argv[1]);'
            .'$p->addFromString("kernel/juphyter_kernel/__init__.py", "KERNEL = 1\n");'
            .'$p->addFromString("kernel/juphyter_kernel/kernel.py", "pass\n");',
            $archive,
        ], ['PATH' => (string) getenv('PATH')]);

        expect($added['code'])->toBe(0, 'Could not add a kernel/ tree to the archive: '.$added['stderr']);

        $prefix = pharPrefix();
        $run = pharJuphyter(['install', '--prefix='.$prefix], $archive);

        $module = $prefix.'/share/jupyter/kernels/php/kernel';

        expect($run['code'])->toBe(0)
            ->and($run['stderr'])->toBe('')
            ->and($module.'/juphyter_kernel/__init__.py')->toBeFile()
            ->and(file_get_contents($module.'/juphyter_kernel/__init__.py'))->toBe("KERNEL = 1\n")
            ->and($module.'/juphyter_kernel/kernel.py')->toBeFile();
    });

    it('copies the real wrapper out, so the installed kernel can start', function () {
        $prefix = pharPrefix();
        $run = pharJuphyter(['install', '--prefix='.$prefix, '--name=php-real']);

        $module = $prefix.'/share/jupyter/kernels/php-real/kernel';

        expect($run['code'])->toBe(0)
            ->and($run['stderr'])->toBe('')
            ->and($module.'/juphyter_kernel/kernel.py')->toBeFile()
            ->and($module.'/juphyter_kernel/__main__.py')->toBeFile()
            ->and(file_get_contents($module.'/juphyter_kernel/__init__.py'))
            ->toContain('juphyter_kernel');
    });

    it('prints the pip command when the interpreter has no ipykernel', function () {
        $bin = pharScratch().'/bin-bare';
        mkdir($bin, 0o777, true);
        file_put_contents($bin.'/python3', "#!/bin/sh\ncase \"\$2\" in\n*sys.executable*) echo \"\$0\" ;;\n*) exit 1 ;;\nesac\n");
        chmod($bin.'/python3', 0o755);

        $prefix = pharPrefix();
        $run = pharRun(
            [PHP_BINARY, pharArchive(), 'install', '--prefix='.$prefix],
            ['PATH' => $bin, 'HOME' => pharScratch()],
        );

        expect($run['code'])->toBe(1)
            ->and($run['stderr'])->toContain('-m pip install "ipykernel>=7.0"')
            ->and(is_dir($prefix.'/share'))->toBeFalse();
    });

    it('uninstalls what it installed', function () {
        $prefix = pharPrefix();

        expect(pharJuphyter(['install', '--prefix='.$prefix, '--name=php-phar'])['code'])->toBe(0);

        $run = pharJuphyter(['uninstall', '--prefix='.$prefix, '--name=php-phar']);

        expect($run['code'])->toBe(0)
            ->and($run['stdout'])->toContain('Removed the juphyter kernel "php-phar"')
            ->and(is_dir($prefix.'/share/jupyter/kernels/php-phar'))->toBeFalse();
    });
});
