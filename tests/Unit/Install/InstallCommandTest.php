<?php

declare(strict_types=1);

use Juphyter\Console\InstallCommand;
use Juphyter\Engine\Interrupt;
use Juphyter\Install\JupyterPaths;
use Juphyter\Install\KernelSpec;
use Juphyter\Install\PythonProbe;
use Juphyter\Install\QuartoProbe;
use Juphyter\Install\VenvBuilder;

/**
 * A temporary directory, removed after the test.
 *
 * Every install in this file writes under one of these, so nothing here can
 * reach the real Jupyter data directory. The prefix form is what `--prefix`
 * takes, and `JUPYTER_DATA_DIR` covers the other path {@see JupyterPaths}
 * resolves.
 */
function installWorkspace(): string
{
    $directory = sys_get_temp_dir().'/juphyter-install-'.bin2hex(random_bytes(6));
    mkdir($directory, 0o777, true);

    return $directory;
}

/** Delete a directory tree. */
function installRemoveTree(string $path): void
{
    if (is_dir($path) && ! is_link($path)) {
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                installRemoveTree($path.'/'.$entry);
            }
        }

        rmdir($path);

        return;
    }

    if (file_exists($path) || is_link($path)) {
        unlink($path);
    }
}

afterEach(function () {
    foreach (glob(sys_get_temp_dir().'/juphyter-install-*') ?: [] as $directory) {
        installRemoveTree($directory);
    }
});

/**
 * A runner that reports a healthy Python and a healthy PHP.
 *
 * The probe asks Python for `sys.executable` and for the `ipykernel` version.
 * The command asks `--php` for its own binary and version. Anything else fails,
 * the way a missing interpreter does.
 *
 * @param  array<string, array{code?: int, stdout?: string, stderr?: string}>  $overrides
 */
function installRunner(array $overrides = [], array &$calls = []): Closure
{
    $answers = $overrides + [
        'sys.executable' => ['stdout' => "/opt/venv/bin/python3\n"],
        'ipykernel' => ['stdout' => "7.4.0\n"],
        'PHP_BINARY' => ['stdout' => "/herd/bin/php85\n8.5.1\n1\n"],
    ];

    return function (array $argv) use ($answers, &$calls): array {
        $calls[] = $argv;

        foreach ($answers as $needle => $answer) {
            if (str_contains(implode(' ', $argv), $needle)) {
                return [
                    'code' => $answer['code'] ?? 0,
                    'stdout' => $answer['stdout'] ?? '',
                    'stderr' => $answer['stderr'] ?? '',
                ];
            }
        }

        return ['code' => 1, 'stdout' => '', 'stderr' => 'not runnable'];
    };
}

/**
 * A directory holding a fake `python3`, to stand in for a PATH with Jupyter on it.
 *
 * The probe needs a candidate interpreter to exist before it asks the runner
 * about it. Which one it picks is {@see PythonProbe}'s own concern and is
 * covered there; here the runner answers for whatever it picks.
 */
function installPythonPath(): string
{
    static $directory = null;

    if ($directory === null) {
        $directory = sys_get_temp_dir().'/juphyter-probe-path-'.bin2hex(random_bytes(6));
        mkdir($directory, 0o777, true);
        file_put_contents($directory.'/python3', "#!/bin/sh\n");
        chmod($directory.'/python3', 0o755);
    }

    return $directory;
}

/**
 * A package root holding the Python wrapper, as an installed package does.
 *
 * `install` refuses a package without `kernel/juphyter_kernel`, because the
 * kernelspec it would write runs `python -m juphyter_kernel`. Every test that
 * is about something else therefore needs a root that has it.
 */
function installPackageRoot(): string
{
    static $directory = null;

    if ($directory === null) {
        $directory = sys_get_temp_dir().'/juphyter-package-root-'.bin2hex(random_bytes(6));
        mkdir($directory.'/kernel/juphyter_kernel', 0o777, true);
        file_put_contents($directory.'/kernel/juphyter_kernel/__init__.py', "KERNEL = 1\n");
        file_put_contents($directory.'/kernel/juphyter_kernel/kernel.py', "pass\n");
    }

    return $directory;
}

afterAll(function () {
    $patterns = ['juphyter-probe-path-*', 'juphyter-package-root-*', 'juphyter-quarto-path-*'];

    foreach ($patterns as $pattern) {
        foreach (glob(sys_get_temp_dir().'/'.$pattern) ?: [] as $directory) {
            installRemoveTree($directory);
        }
    }
});

/**
 * A directory holding a fake `quarto` and a fake `python3`, for the Quarto check.
 *
 * {@see QuartoProbe} only asks `PATH` whether `quarto` is there and which
 * `python3` comes first on it, so an empty file of each name is enough; the
 * runner answers for whatever it picks. The default {@see runInstall()} leaves
 * this `PATH` empty, which is a machine with no Quarto on it and the case where
 * the whole check is skipped.
 */
function installQuartoPath(): string
{
    static $directory = null;

    if ($directory === null) {
        $directory = sys_get_temp_dir().'/juphyter-quarto-path-'.bin2hex(random_bytes(6));
        mkdir($directory, 0o777, true);

        foreach (['quarto', 'python3'] as $name) {
            file_put_contents($directory.'/'.$name, "#!/bin/sh\n");
            chmod($directory.'/'.$name, 0o755);
        }
    }

    return $directory;
}

/**
 * Run `juphyter install` with fakes, and return the exit code and both streams.
 *
 * @param  list<string>  $arguments
 * @param  array<string, string>  $environment  What {@see JupyterPaths} reads, and where `QUARTO_PYTHON` comes from.
 * @param  string|null  $quartoPath  The `PATH` {@see QuartoProbe} searches. Empty by default, so Quarto is absent.
 * @param  string|null  $venvPath  The `PATH` {@see VenvBuilder} searches for a base interpreter. Empty by default, so there is none.
 * @param  (Closure(string): bool)|null  $confirm  How the build-an-environment question is answered. Declines by default.
 * @return array{result: int, stdout: string, stderr: string}
 */
function runInstall(
    array $arguments,
    array $environment = [],
    ?Closure $runner = null,
    ?string $packageRoot = null,
    string $osFamily = 'Darwin',
    ?string $quartoPath = null,
    ?string $venvPath = null,
    ?Closure $confirm = null,
): array {
    $runner ??= installRunner();
    $packageRoot ??= installPackageRoot();
    $confirm ??= fn (string $question): bool => false;

    return captured(fn ($stdout, $stderr) => (new InstallCommand(
        new JupyterPaths($environment, $osFamily),
        new PythonProbe($runner, installPythonPath()),
        new QuartoProbe($runner, $quartoPath ?? '', $environment),
        new VenvBuilder($runner, $venvPath ?? '', $environment),
        $runner,
        $packageRoot,
        $stdout,
        $stderr,
        $confirm,
    ))->run($arguments));
}

/** The decoded kernelspec at a path. */
function readKernelSpec(string $file): array
{
    expect($file)->toBeFile();

    return json_decode((string) file_get_contents($file), true);
}

describe('writing the kernelspec', function () {
    it('writes one under the Jupyter data directory', function () {
        $home = installWorkspace();
        $run = runInstall([], ['JUPYTER_DATA_DIR' => $home.'/jupyter']);

        expect($run['result'])->toBe(0);

        $spec = readKernelSpec($home.'/jupyter/kernels/php/kernel.json');

        expect($spec)->toBe([
            'argv' => ['/opt/venv/bin/python3', '-m', 'juphyter_kernel', '-f', '{connection_file}'],
            'display_name' => 'PHP '.implode('.', array_slice(explode('.', PHP_VERSION), 0, 2)),
            'language' => 'php',
            'interrupt_mode' => 'signal',
            'env' => [
                'JUPHYTER_PHP' => InstallCommand::stableBinary(PHP_BINARY, (string) getenv('PATH')),
                'JUPHYTER_ENGINE' => installPackageRoot().'/bin/juphyter',
                'PYTHONPATH' => installPackageRoot().'/kernel',
            ],
        ]);
    });

    it('writes one under a prefix, as jupyter kernelspec install does', function () {
        $prefix = installWorkspace();
        $run = runInstall(['--prefix', $prefix]);

        expect($run['result'])->toBe(0)
            ->and($prefix.'/share/jupyter/kernels/php/kernel.json')->toBeFile();
    });

    it('takes an option as --name=value too', function () {
        $prefix = installWorkspace();
        $run = runInstall(['--prefix='.$prefix, '--name=php85']);

        expect($run['result'])->toBe(0)
            ->and($prefix.'/share/jupyter/kernels/php85/kernel.json')->toBeFile();
    });

    it('reports where it went', function () {
        $prefix = installWorkspace();
        $run = runInstall(['--prefix', $prefix]);

        expect($run['stdout'])
            ->toContain('Installed the juphyter kernel "php"')
            ->toContain($prefix.'/share/jupyter/kernels/php/kernel.json')
            ->toContain('/opt/venv/bin/python3 (ipykernel 7.4.0)');
    });

    it('creates the kernels directory when it is not there yet', function () {
        $prefix = installWorkspace().'/nested/deeper';
        $run = runInstall(['--prefix', $prefix]);

        expect($run['result'])->toBe(0)
            ->and($prefix.'/share/jupyter/kernels/php/kernel.json')->toBeFile();
    });

    it('adds JUPHYTER_AUTOLOAD when --autoload is given', function () {
        $prefix = installWorkspace();
        runInstall(['--prefix', $prefix, '--autoload', '/proj/vendor/autoload.php']);

        expect(readKernelSpec($prefix.'/share/jupyter/kernels/php/kernel.json')['env']['JUPHYTER_AUTOLOAD'])
            ->toBe('/proj/vendor/autoload.php');
    });

    it('lowercases the kernel name', function () {
        $prefix = installWorkspace();
        $run = runInstall(['--prefix', $prefix, '--name', 'PHP85']);

        expect($run['result'])->toBe(0)
            ->and($prefix.'/share/jupyter/kernels/php85/kernel.json')->toBeFile();
    });

    it('refuses a name it cannot use, before writing anything', function () {
        $prefix = installWorkspace();
        $run = runInstall(['--prefix', $prefix, '--name', '../escape']);

        expect($run['result'])->toBe(1)
            ->and($run['stderr'])->toContain('not a usable kernel name')
            ->and(glob($prefix.'/share/jupyter/kernels/*') ?: [])->toBe([]);
    });
});

describe('several PHP versions side by side', function () {
    it('keeps two kernels apart by --name and --display-name', function () {
        $prefix = installWorkspace();

        $first = runInstall([
            '--prefix', $prefix, '--name', 'php83', '--display-name', 'PHP 8.3',
            '--php', '/herd/bin/php83',
        ], runner: installRunner(['PHP_BINARY' => ['stdout' => "/herd/bin/php83\n8.3.14\n"]]));

        $second = runInstall([
            '--prefix', $prefix, '--name', 'php85',
            '--php', '/herd/bin/php85',
        ]);

        expect($first['result'])->toBe(0)
            ->and($second['result'])->toBe(0);

        $one = readKernelSpec($prefix.'/share/jupyter/kernels/php83/kernel.json');
        $two = readKernelSpec($prefix.'/share/jupyter/kernels/php85/kernel.json');

        expect($one['display_name'])->toBe('PHP 8.3')
            ->and($one['env']['JUPHYTER_PHP'])->toBe('/herd/bin/php83')
            // No --display-name, so the version of the chosen binary names it.
            ->and($two['display_name'])->toBe('PHP 8.5')
            ->and($two['env']['JUPHYTER_PHP'])->toBe('/herd/bin/php85');
    });

    it('replaces its own kernelspec when the same name is installed again', function () {
        $prefix = installWorkspace();
        runInstall(['--prefix', $prefix]);
        $again = runInstall(['--prefix', $prefix, '--display-name', 'PHP, again']);

        expect($again['result'])->toBe(0)
            ->and($again['stdout'])->toContain('Replaced the juphyter kernel')
            ->and(readKernelSpec($prefix.'/share/jupyter/kernels/php/kernel.json')['display_name'])
            ->toBe('PHP, again');
    });

    it('leaves a kernelspec it did not write alone', function () {
        $prefix = installWorkspace();
        $directory = $prefix.'/share/jupyter/kernels/php';
        mkdir($directory, 0o777, true);
        file_put_contents($directory.'/kernel.json', json_encode([
            'argv' => ['other-php-kernel'],
            'display_name' => 'Somebody else\'s PHP',
            'language' => 'php',
        ]));

        $run = runInstall(['--prefix', $prefix]);

        expect($run['result'])->toBe(1)
            ->and($run['stderr'])->toContain('juphyter did not write it')
            ->and(readKernelSpec($directory.'/kernel.json')['argv'])->toBe(['other-php-kernel']);
    });
});

describe('the Python interpreter', function () {
    it('names the interpreter the probe chose', function () {
        $prefix = installWorkspace();
        runInstall(['--prefix', $prefix, '--python', '/opt/venv/bin/python']);

        expect(readKernelSpec($prefix.'/share/jupyter/kernels/php/kernel.json')['argv'][0])
            ->toBe('/opt/venv/bin/python3');
    });

    it('writes nothing and prints the pip command when ipykernel is missing', function () {
        $prefix = installWorkspace();
        $run = runInstall(
            ['--prefix', $prefix],
            runner: installRunner(['ipykernel' => ['code' => 1, 'stderr' => 'No module named ipykernel']]),
        );

        expect($run['result'])->toBe(1)
            ->and($run['stderr'])
            ->toContain('ipykernel is not installed in /opt/venv/bin/python3')
            ->toContain('/opt/venv/bin/python3 -m pip install "ipykernel>=7.0"')
            ->and(is_dir($prefix.'/share'))->toBeFalse();
    });

    it('writes nothing when ipykernel is too old for the wrapper', function () {
        $prefix = installWorkspace();
        $run = runInstall(
            ['--prefix', $prefix],
            runner: installRunner(['ipykernel' => ['stdout' => "6.29.5\n"]]),
        );

        expect($run['result'])->toBe(1)
            ->and($run['stderr'])->toContain('needs 7.0 or newer')
            ->and(is_dir($prefix.'/share'))->toBeFalse();
    });

    it('writes nothing when the interpreter does not run', function () {
        $prefix = installWorkspace();
        $run = runInstall(
            ['--prefix', $prefix, '--python', '/gone/python'],
            runner: installRunner(['sys.executable' => ['code' => 127, 'stderr' => 'no such file']]),
        );

        expect($run['result'])->toBe(1)
            ->and($run['stderr'])->toContain('is not a working Python interpreter')
            ->and(is_dir($prefix.'/share'))->toBeFalse();
    });
});

describe('the PHP binary', function () {
    it('defaults to the PHP running the command, without starting a process', function () {
        $prefix = installWorkspace();
        $calls = [];
        runInstall(['--prefix', $prefix], runner: installRunner([], $calls));

        $spec = readKernelSpec($prefix.'/share/jupyter/kernels/php/kernel.json');

        expect($spec['env']['JUPHYTER_PHP'])
            ->toBe(InstallCommand::stableBinary(PHP_BINARY, (string) getenv('PATH')))
            ->and(realpath($spec['env']['JUPHYTER_PHP']))->toBe(realpath(PHP_BINARY));

        foreach ($calls as $call) {
            expect(implode(' ', $call))->not->toContain('PHP_BINARY');
        }
    });

    it('records the default PHP under the PATH symlink that reaches it, as Homebrew needs', function () {
        // PHP_BINARY on Homebrew is the versioned Cellar path, which brew
        // upgrade deletes; /opt/homebrew/bin/php is a symlink into it that
        // survives. Here a fake Cellar binary and a bin directory of links
        // stand in for both.
        $home = installWorkspace();
        mkdir($home.'/Cellar/php/8.5.10/bin', 0o777, true);
        mkdir($home.'/bin');
        mkdir($home.'/other');
        $cellar = $home.'/Cellar/php/8.5.10/bin/php';
        file_put_contents($cellar, "#!/bin/sh\n");
        chmod($cellar, 0o755);
        symlink($cellar, $home.'/bin/php');

        expect(InstallCommand::stableBinary($cellar, $home.'/other:'.$home.'/bin/:/usr/bin'))
            ->toBe($home.'/bin/php');
    });

    it('keeps the binary when no PATH entry reaches it', function () {
        $home = installWorkspace();
        mkdir($home.'/Cellar', 0o777, true);
        mkdir($home.'/bin');
        $cellar = $home.'/Cellar/php';
        $elsewhere = $home.'/Cellar/php-other';
        file_put_contents($cellar, "#!/bin/sh\n");
        file_put_contents($elsewhere, "#!/bin/sh\n");
        chmod($cellar, 0o755);
        chmod($elsewhere, 0o755);
        symlink($elsewhere, $home.'/bin/php');

        expect(InstallCommand::stableBinary($cellar, $home.'/bin:relative/bin'))->toBe($cellar)
            ->and(InstallCommand::stableBinary($home.'/missing/php', $home.'/bin'))->toBe($home.'/missing/php');
    });

    it('looks only for the same name, so php8.3 is never swapped for php', function () {
        $home = installWorkspace();
        mkdir($home.'/bin');
        $versioned = $home.'/bin/php8.3';
        file_put_contents($versioned, "#!/bin/sh\n");
        chmod($versioned, 0o755);
        mkdir($home.'/alternatives');
        symlink($versioned, $home.'/alternatives/php');

        expect(InstallCommand::stableBinary($versioned, $home.'/alternatives:'.$home.'/bin'))
            ->toBe($versioned);
    });

    it('validates --php by running it, and takes the path it reports', function () {
        $prefix = installWorkspace();
        $calls = [];
        runInstall(['--prefix', $prefix, '--php', 'php85'], runner: installRunner([], $calls));

        expect($calls[0][0])->toBe('php85')
            ->and($calls[0][1])->toBe('-r')
            ->and($calls[0][2])->toContain('PHP_BINARY')
            ->and($calls[0][2])->toContain('PHP_VERSION')
            ->and(readKernelSpec($prefix.'/share/jupyter/kernels/php/kernel.json')['env']['JUPHYTER_PHP'])
            ->toBe('/herd/bin/php85');
    });

    it('keeps an absolute --php exactly as it was given', function () {
        // A symlink, because that is the case that matters: PHP_BINARY is the
        // path with its symlinks resolved, so `--php /opt/homebrew/bin/php`
        // would otherwise be recorded as the versioned Cellar path that
        // Homebrew deletes on the next upgrade.
        $home = installWorkspace();
        $link = $home.'/php-link';
        symlink(PHP_BINARY, $link);

        $run = runInstall(['--prefix', $home.'/prefix', '--php', $link], runner: installRunner([
            'PHP_BINARY' => ['stdout' => "/opt/homebrew/Cellar/php/8.5.10/bin/php\n8.5.10\n1\n"],
        ]));

        $spec = readKernelSpec($home.'/prefix/share/jupyter/kernels/php/kernel.json');

        expect($run['result'])->toBe(0)
            ->and($spec['env']['JUPHYTER_PHP'])->toBe($link)
            ->and($spec['env']['JUPHYTER_PHP'])->not->toContain('Cellar')
            // The version still comes from what the binary reported, which is
            // what the default display name is built from.
            ->and($spec['display_name'])->toBe('PHP 8.5');
    });

    it('resolves a bare name through the binary it reports', function () {
        $home = installWorkspace();

        $run = runInstall(['--prefix', $home.'/prefix', '--php', 'php84'], runner: installRunner([
            'PHP_BINARY' => ['stdout' => "/usr/bin/php8.4\n8.4.12\n1\n"],
        ]));

        expect($run['result'])->toBe(0)
            ->and(readKernelSpec($home.'/prefix/share/jupyter/kernels/php/kernel.json')['env']['JUPHYTER_PHP'])
            ->toBe('/usr/bin/php8.4');
    });

    it('resolves a relative path through the binary it reports', function () {
        // Relative to this command's working directory, which is not the
        // notebook's and not anything the kernelspec can name.
        $home = installWorkspace();

        $run = runInstall(['--prefix', $home.'/prefix', '--php', './php'], runner: installRunner([
            'PHP_BINARY' => ['stdout' => "/srv/app/php\n8.5.10\n1\n"],
        ]));

        expect($run['result'])->toBe(0)
            ->and(readKernelSpec($home.'/prefix/share/jupyter/kernels/php/kernel.json')['env']['JUPHYTER_PHP'])
            ->toBe('/srv/app/php');
    });

    it('probes the path it was given, and not the one that comes back', function () {
        $home = installWorkspace();
        $link = $home.'/php-link';
        symlink(PHP_BINARY, $link);
        $calls = [];

        runInstall(['--prefix', $home.'/prefix', '--php', $link], runner: installRunner([
            'PHP_BINARY' => ['stdout' => "/opt/homebrew/Cellar/php/8.5.10/bin/php\n8.5.10\n1\n"],
        ], $calls));

        expect($calls[0][0])->toBe($link);
    });

    it('writes nothing when --php does not run', function () {
        $prefix = installWorkspace();
        $run = runInstall(
            ['--prefix', $prefix, '--php', '/gone/php'],
            runner: installRunner(['PHP_BINARY' => ['code' => 127, 'stderr' => 'no such file']]),
        );

        expect($run['result'])->toBe(1)
            ->and($run['stderr'])->toContain('--php /gone/php is not a working PHP binary')
            ->and($run['stderr'])->toContain('no such file')
            ->and(is_dir($prefix.'/share'))->toBeFalse();
    });

    it('writes nothing when --php runs but reports no version', function () {
        $prefix = installWorkspace();
        $run = runInstall(
            ['--prefix', $prefix, '--php', '/odd/php'],
            runner: installRunner(['PHP_BINARY' => ['stdout' => "/odd/php\n"]]),
        );

        expect($run['result'])->toBe(1)
            ->and($run['stderr'])->toContain('did not report its version')
            ->and(is_dir($prefix.'/share'))->toBeFalse();
    });
});

describe('running from a PHAR', function () {
    /*
     * A real archive, because the install's PHAR behavior is all about reading
     * one: the wrapper module has to be found inside it, copied out of it, and
     * the archive's own path has to end up in JUPHYTER_ENGINE. Writing a PHAR
     * needs phar.readonly=0, so these skip without it; the same ground is
     * covered by PharTest against an archive the build script produced, which
     * re-runs itself with the setting.
     */
    it('names the archive as the engine and copies the wrapper out of it', function () {
        if (! Phar::canWrite()) {
            $this->markTestSkipped('Writing a PHAR needs phar.readonly=0.');
        }

        $home = installWorkspace();
        $archive = $home.'/juphyter.phar';

        $phar = new Phar($archive);
        $phar->addFromString('kernel/juphyter_kernel/__init__.py', "KERNEL = 1\n");
        $phar->addFromString('kernel/juphyter_kernel/kernel.py', "pass\n");
        $phar->setStub("<?php __HALT_COMPILER(); ?>");
        unset($phar);

        $prefix = $home.'/prefix';
        $run = runInstall(['--prefix', $prefix], packageRoot: 'phar://'.$archive);

        $directory = $prefix.'/share/jupyter/kernels/php';
        $module = $directory.'/kernel';
        $spec = readKernelSpec($directory.'/kernel.json');

        expect($run['result'])->toBe(0)
            ->and($run['stderr'])->toBe('')
            ->and($module.'/juphyter_kernel/__init__.py')->toBeFile()
            ->and(file_get_contents($module.'/juphyter_kernel/__init__.py'))->toBe("KERNEL = 1\n")
            ->and($module.'/juphyter_kernel/kernel.py')->toBeFile()
            // The archive's path on disk, not a phar:// URL: the wrapper runs
            // this file with the PHP binary.
            ->and($spec['env']['JUPHYTER_ENGINE'])->toBe($archive)
            // And beside the kernelspec, because Python cannot import from
            // inside an archive.
            ->and($spec['env']['PYTHONPATH'])->toBe($module);
    });

    it('refuses an archive with no wrapper module in it', function () {
        if (! Phar::canWrite()) {
            $this->markTestSkipped('Writing a PHAR needs phar.readonly=0.');
        }

        $home = installWorkspace();
        $archive = $home.'/empty.phar';

        $phar = new Phar($archive);
        $phar->addFromString('src/Console/InstallCommand.php', "<?php\n");
        $phar->setStub('<?php __HALT_COMPILER(); ?>');
        unset($phar);

        $run = runInstall(['--prefix', $home.'/prefix'], packageRoot: 'phar://'.$archive);

        expect($run['result'])->toBe(1)
            ->and($run['stderr'])->toContain('the Python wrapper is not at phar://'.$archive)
            ->and(is_dir($home.'/prefix'))->toBeFalse();
    });
});

describe('the missing Python wrapper', function () {
    it('fails and writes nothing when the package has no wrapper module', function () {
        $home = installWorkspace();
        mkdir($home.'/package', 0o777, true);

        $run = runInstall(['--prefix', $home.'/prefix'], packageRoot: $home.'/package');

        expect($run['result'])->toBe(1)
            ->and($run['stderr'])
            ->toContain('the Python wrapper is not at '.$home.'/package/kernel/juphyter_kernel')
            ->toContain('python -m juphyter_kernel')
            ->and($run['stdout'])->toBe('')
            ->and(is_dir($home.'/prefix'))->toBeFalse();
    });

    it('fails when the module directory is there but the module is not', function () {
        $home = installWorkspace();
        mkdir($home.'/package/kernel/juphyter_kernel', 0o777, true);

        $run = runInstall(['--prefix', $home.'/prefix'], packageRoot: $home.'/package');

        expect($run['result'])->toBe(1)
            ->and($run['stderr'])->toContain('the Python wrapper is not at')
            ->and(is_dir($home.'/prefix'))->toBeFalse();
    });

    it('asks nothing of Python when the wrapper is missing', function () {
        $home = installWorkspace();
        mkdir($home.'/package', 0o777, true);
        $calls = [];

        runInstall(
            ['--prefix', $home.'/prefix'],
            runner: installRunner([], $calls),
            packageRoot: $home.'/package',
        );

        expect($calls)->toBe([]);
    });

    it('installs when the module is there', function () {
        $home = installWorkspace();
        $run = runInstall(['--prefix', $home.'/prefix']);

        expect($run['result'])->toBe(0)
            ->and($run['stderr'])->toBe('')
            ->and($home.'/prefix/share/jupyter/kernels/php/kernel.json')->toBeFile();
    });
});

describe('the command surface', function () {
    it('is dispatched as "install" and describes itself without being built', function () {
        expect(InstallCommand::name())->toBe('install')
            ->and(InstallCommand::description())->toBe('Register the PHP kernel with Jupyter');
    });

    it('prints its own help for --help and -h', function () {
        foreach (['--help', '-h'] as $flag) {
            $run = runInstall([$flag]);

            expect($run['result'])->toBe(0)
                ->and($run['stdout'])->toContain('Usage: juphyter install [options]')
                ->and($run['stdout'])->toContain('--display-name')
                ->and($run['stderr'])->toBe('');
        }
    });

    it('rejects an unknown option and shows the usage', function () {
        $run = runInstall(['--nope', 'x']);

        expect($run['result'])->toBe(1)
            ->and($run['stderr'])
            ->toContain('unknown option "--nope"')
            ->toContain('Usage: juphyter install');
    });

    it('rejects an argument that is not an option', function () {
        $run = runInstall(['php85']);

        expect($run['result'])->toBe(1)
            ->and($run['stderr'])->toContain('unexpected argument "php85"');
    });

    it('rejects an option with no value', function () {
        expect(runInstall(['--name'])['stderr'])->toContain('--name needs a value')
            ->and(runInstall(['--name', '--prefix', '/tmp'])['stderr'])->toContain('--name needs a value');
    });

    it('is built by make() without arguments, the way the dispatcher builds it', function () {
        expect(InstallCommand::make())->toBeInstanceOf(InstallCommand::class);
    });

    it('resolves its package root to this checkout outside a PHAR', function () {
        expect(InstallCommand::packageRoot())->toBe(dirname(__DIR__, 3))
            ->and(InstallCommand::packageRoot().'/bin/juphyter')->toBeFile();
    });

    it('writes a kernelspec that uninstall recognizes as juphyter\'s', function () {
        $prefix = installWorkspace();
        runInstall(['--prefix', $prefix]);

        expect(KernelSpec::isJuphyter(readKernelSpec($prefix.'/share/jupyter/kernels/php/kernel.json')))
            ->toBeTrue();
    });
});

describe('the stop button', function () {
    // The kernelspec asks for interrupt_mode "signal", so install is where
    // that promise gets checked: against the binary --php named, not against
    // the PHP running Composer or this command.
    it('says nothing when the target PHP can handle signals', function () {
        $prefix = installWorkspace();

        $result = runInstall(['--prefix', $prefix, '--php', 'php85'], runner: installRunner([
            'PHP_BINARY' => ['stdout' => "/herd/bin/php85\n8.5.1\n1\n"],
        ]));

        expect($result['result'])->toBe(0)
            ->and($result['stderr'])->not->toContain('stop button');
    });

    it('warns and names the binary when it cannot, and still installs', function () {
        $prefix = installWorkspace();

        $result = runInstall(['--prefix', $prefix, '--php', 'php83'], runner: installRunner([
            'PHP_BINARY' => ['stdout' => "/herd/bin/php83\n8.3.9\n0\n"],
        ]));

        expect($result['result'])->toBe(0)
            ->and($result['stderr'])->toContain('/herd/bin/php83')
            ->and($result['stderr'])->toContain('stop button')
            ->and($result['stderr'])->toContain('interrupt_mode')
            ->and($result['stderr'])->toContain('disable_functions')
            ->and(readKernelSpec($prefix.'/share/jupyter/kernels/php/kernel.json')['env']['JUPHYTER_PHP'])
            ->toBe('/herd/bin/php83');
    });

    it('names the path the user gave in the warning, not the one PHP reports', function () {
        // The warning is about the binary the kernelspec names, so it has to
        // name the same one. A wrapper script makes the difference plain: it
        // was the wrapper that answered, through whichever PHP it chose.
        $home = installWorkspace();
        $wrapper = $home.'/php-wrapper';
        file_put_contents($wrapper, "#!/bin/sh\nexec php \"$@\"\n");
        chmod($wrapper, 0o755);

        $result = runInstall(['--prefix', $home.'/prefix', '--php', $wrapper], runner: installRunner([
            'PHP_BINARY' => ['stdout' => "/opt/homebrew/Cellar/php/8.3.9/bin/php\n8.3.9\n0\n"],
        ]));

        expect($result['result'])->toBe(0)
            ->and($result['stderr'])->toContain($wrapper)
            ->and($result['stderr'])->not->toContain('Cellar')
            ->and(readKernelSpec($home.'/prefix/share/jupyter/kernels/php/kernel.json')['env']['JUPHYTER_PHP'])
            ->toBe($wrapper);
    });

    it('still reports the install on stdout alongside the warning', function () {
        $prefix = installWorkspace();

        $result = runInstall(['--prefix', $prefix, '--php', 'php83'], runner: installRunner([
            'PHP_BINARY' => ['stdout' => "/herd/bin/php83\n8.3.9\n0\n"],
        ]));

        expect($result['stdout'])->toContain('the juphyter kernel')
            ->and($result['stdout'])->not->toContain('stop button');
    });

    it('asks the target PHP the same question Interrupt::available() asks', function () {
        $prefix = installWorkspace();
        $calls = [];
        runInstall(['--prefix', $prefix, '--php', 'php85'], runner: installRunner([], $calls));

        expect($calls[0][2])->toContain('pcntl_async_signals')
            ->and($calls[0][2])->toContain('pcntl_signal')
            ->and($calls[0][2])->toContain('SIGINT');
    });

    it('says nothing when an older probe answers with only two lines', function () {
        // Absent an answer, say nothing rather than warn about a binary that
        // never reported.
        $prefix = installWorkspace();

        $result = runInstall(['--prefix', $prefix, '--php', 'php85'], runner: installRunner([
            'PHP_BINARY' => ['stdout' => "/herd/bin/php85\n8.5.1\n"],
        ]));

        expect($result['result'])->toBe(0)
            ->and($result['stderr'])->not->toContain('stop button');
    });

    it('checks the running PHP when no --php is given', function () {
        $prefix = installWorkspace();

        $result = runInstall(['--prefix', $prefix]);

        // This test runner has pcntl, so there is nothing to warn about; the
        // point is that the answer comes from Interrupt::available() rather
        // than from a subprocess that was never run.
        expect($result['result'])->toBe(0)
            ->and($result['stderr'])->toContain(Interrupt::available() ? '' : 'stop button');
    });
});

describe('the interpreter Quarto will use', function () {
    // Quarto runs a Python of its own choosing and that one is the one that
    // needs Jupyter in it, so a kernelspec can be correct and complete and
    // still be unreachable from `quarto render`. The recorded interpreter is
    // never moved to match: see InstallCommand::quarto().
    //
    // The runner answers for two interpreters here. /opt/venv/bin/python is
    // what --python names and the kernelspec records, and /usr/bin/python3 is
    // the one on Quarto's PATH, so a test can give them different modules.
    $recorded = '/opt/venv/bin/python';

    it('says nothing about Quarto when quarto is not on PATH', function () {
        $prefix = installWorkspace();
        $run = runInstall(['--prefix', $prefix]);

        expect($run['result'])->toBe(0)
            ->and($run['stdout'])->not->toContain('quarto')
            ->and($run['stderr'])->toBe('');
    });

    it('names the interpreter and its jupyter_client when it has one', function () use ($recorded) {
        $prefix = installWorkspace();
        $run = runInstall(['--prefix', $prefix, '--python', $recorded], quartoPath: installQuartoPath(), runner: installRunner([
            installQuartoPath().'/python3 -c import sys' => ['stdout' => "/usr/bin/python3.12\n"],
            'jupyter_client' => ['stdout' => "8.6.3\n"],
            'sys.executable' => ['stdout' => "/opt/venv/bin/python3\n"],
        ]));

        expect($run['result'])->toBe(0)
            ->and($run['stdout'])->toContain('  quarto       /usr/bin/python3.12 (jupyter_client 8.6.3)')
            ->and($run['stderr'])->toBe('')
            ->and($prefix.'/share/jupyter/kernels/php/kernel.json')->toBeFile();
    });

    it('reports no Jupyter, warns, and installs anyway', function () use ($recorded) {
        $prefix = installWorkspace();
        $run = runInstall(['--prefix', $prefix, '--python', $recorded], quartoPath: installQuartoPath(), runner: installRunner([
            installQuartoPath().'/python3 -c import sys' => ['stdout' => "/usr/bin/python3.12\n"],
            // Only the recorded interpreter has the client. Quarto's does not,
            // which is the Homebrew python3 on a typical macOS machine.
            '/opt/venv/bin/python3 -c import jupyter_client' => ['stdout' => "8.6.3\n"],
            'sys.executable' => ['stdout' => "/opt/venv/bin/python3\n"],
        ]));

        expect($run['result'])->toBe(0)
            ->and($run['stdout'])->toContain('  quarto       /usr/bin/python3.12 (no Jupyter)')
            ->and($run['stderr'])->toContain('/usr/bin/python3.12')
            ->and($run['stderr'])->toContain('no Jupyter in it')
            ->and($run['stderr'])->toContain('quarto check jupyter')
            // The way out is the recorded interpreter's own bin directory.
            ->and($run['stderr'])->toContain('export PATH="/opt/venv/bin:$PATH"')
            ->and($prefix.'/share/jupyter/kernels/php/kernel.json')->toBeFile();
    });

    it('leaves the kernelspec naming the recorded interpreter, not Quarto\'s', function () use ($recorded) {
        $prefix = installWorkspace();
        runInstall(['--prefix', $prefix, '--python', $recorded], quartoPath: installQuartoPath(), runner: installRunner([
            installQuartoPath().'/python3 -c import sys' => ['stdout' => "/usr/bin/python3.12\n"],
            '/usr/bin/python3.12 -c import jupyter_client' => ['stdout' => "8.6.3\n"],
            'sys.executable' => ['stdout' => "/opt/venv/bin/python3\n"],
        ]));

        $spec = readKernelSpec($prefix.'/share/jupyter/kernels/php/kernel.json');

        expect($spec['argv'][0])->toBe('/opt/venv/bin/python3');
    });

    it('takes QUARTO_PYTHON over the python3 on PATH', function () use ($recorded) {
        $prefix = installWorkspace();
        $run = runInstall(
            ['--prefix', $prefix, '--python', $recorded],
            ['QUARTO_PYTHON' => '/other/venv/bin/python'],
            quartoPath: installQuartoPath(),
            runner: installRunner([
                '/other/venv/bin/python -c import sys' => ['stdout' => "/other/venv/bin/python\n"],
                'jupyter_client' => ['stdout' => "8.6.3\n"],
                'sys.executable' => ['stdout' => "/opt/venv/bin/python3\n"],
            ]),
        );

        expect($run['result'])->toBe(0)
            ->and($run['stdout'])->toContain('  quarto       /other/venv/bin/python (jupyter_client 8.6.3)')
            ->and($run['stdout'])->not->toContain('/usr/bin/python3');
    });

    it('reports a QUARTO_PYTHON that does not run, and still installs', function () use ($recorded) {
        $prefix = installWorkspace();
        $run = runInstall(
            ['--prefix', $prefix, '--python', $recorded],
            ['QUARTO_PYTHON' => '/gone/bin/python'],
            quartoPath: installQuartoPath(),
            runner: installRunner([
                '/gone/bin/python -c import sys' => [
                    'code' => 127,
                    'stderr' => '/gone/bin/python is not an executable file',
                ],
                'jupyter_client' => ['stdout' => "8.6.3\n"],
                'sys.executable' => ['stdout' => "/opt/venv/bin/python3\n"],
            ]),
        );

        expect($run['result'])->toBe(0)
            ->and($run['stdout'])->toContain('  quarto       /gone/bin/python (did not run)')
            // Naming the variable is the point: an interpreter the reader
            // cannot otherwise account for came from somewhere.
            ->and($run['stderr'])->toContain('QUARTO_PYTHON names /gone/bin/python')
            ->and($run['stderr'])->toContain('is not an executable file')
            ->and($prefix.'/share/jupyter/kernels/php/kernel.json')->toBeFile();
    });

    it('reports that there is no python3 on PATH for Quarto to use', function () use ($recorded) {
        $prefix = installWorkspace();
        $quarto = installWorkspace();
        file_put_contents($quarto.'/quarto', "#!/bin/sh\n");
        chmod($quarto.'/quarto', 0o755);

        $run = runInstall(['--prefix', $prefix, '--python', $recorded], quartoPath: $quarto, runner: installRunner([
            'jupyter_client' => ['stdout' => "8.6.3\n"],
            'sys.executable' => ['stdout' => "/opt/venv/bin/python3\n"],
        ]));

        expect($run['result'])->toBe(0)
            ->and($run['stdout'])->toContain('  quarto       no python3 on PATH')
            ->and($run['stderr'])->toContain('no python3 on PATH')
            ->and($prefix.'/share/jupyter/kernels/php/kernel.json')->toBeFile();
    });

    it('suggests no PATH entry when the recorded interpreter has no Jupyter either', function () use ($recorded) {
        // ipykernel 7 depends on jupyter_client, so this is a broken
        // environment rather than an ordinary one. There is still no
        // interpreter here to point the reader at, so the command says what
        // Quarto needs and leaves the choice of one to them.
        $prefix = installWorkspace();
        $run = runInstall(['--prefix', $prefix, '--python', $recorded], quartoPath: installQuartoPath(), runner: installRunner([
            installQuartoPath().'/python3 -c import sys' => ['stdout' => "/usr/bin/python3.12\n"],
            'sys.executable' => ['stdout' => "/opt/venv/bin/python3\n"],
        ]));

        expect($run['result'])->toBe(0)
            ->and($run['stdout'])->toContain('  quarto       /usr/bin/python3.12 (no Jupyter)')
            ->and($run['stderr'])->toContain('Quarto needs a Python with Jupyter in it')
            ->and($run['stderr'])->not->toContain('export PATH=')
            ->and($run['stderr'])->toContain('quarto check jupyter');
    });

    it('asks the resolved interpreter for jupyter_client and nothing else', function () use ($recorded) {
        $prefix = installWorkspace();
        $calls = [];
        runInstall(['--prefix', $prefix, '--python', $recorded], quartoPath: installQuartoPath(), runner: installRunner([
            installQuartoPath().'/python3 -c import sys' => ['stdout' => "/usr/bin/python3.12\n"],
            'jupyter_client' => ['stdout' => "8.6.3\n"],
            'sys.executable' => ['stdout' => "/opt/venv/bin/python3\n"],
        ], $calls));

        $asked = array_values(array_filter(
            $calls,
            fn (array $argv) => str_contains($argv[2] ?? '', 'jupyter_client'),
        ));

        // The resolved path, not the python3 on PATH that led to it: the
        // install report and `quarto check jupyter` then name the same file.
        expect($asked[0][0])->toBe('/usr/bin/python3.12')
            ->and($asked[0][2])->toBe('import jupyter_client; print(jupyter_client.__version__)');
    });
});

describe('the Quarto check against a real interpreter', function () {
    // Everything above answers from a table. This one runs the probe's own
    // process runner against a Python that is really on this machine and
    // really has no jupyter_client, which is the macOS default and the whole
    // reason the check exists.
    it('warns and names the Jupyter-less python3 that is first on PATH', function () {
        $python3 = null;

        foreach (explode(PATH_SEPARATOR, (string) getenv('PATH')) as $directory) {
            $candidate = rtrim($directory, '/').'/python3';

            if ($directory !== '' && is_file($candidate) && is_executable($candidate)) {
                $python3 = $candidate;

                break;
            }
        }

        if ($python3 === null) {
            $this->markTestSkipped('No python3 on PATH to probe.');
        }

        $runner = PythonProbe::processRunner();
        $imports = $runner([$python3, '-c', 'import jupyter_client']);

        if ($imports['code'] === 0) {
            $this->markTestSkipped(sprintf(
                '%s has jupyter_client, so the no-Jupyter branch cannot be exercised here.',
                $python3,
            ));
        }

        $workspace = installWorkspace();

        // Quarto's PATH: a quarto that is only ever located, and the real
        // python3 under the name the probe looks for.
        $quarto = $workspace.'/quarto-bin';
        mkdir($quarto, 0o777, true);
        file_put_contents($quarto.'/quarto', "#!/bin/sh\nexit 0\n");
        chmod($quarto.'/quarto', 0o755);
        symlink($python3, $quarto.'/python3');

        // The recorded interpreter, which has to answer for ipykernel and for
        // jupyter_client. A stub keeps the test from needing an environment
        // with ipykernel 7 in it.
        $recorded = $workspace.'/venv/bin';
        mkdir($recorded, 0o777, true);
        file_put_contents($recorded.'/python', <<<'SH'
            #!/bin/sh
            case "$2" in
              *ipykernel*) echo 7.4.0 ;;
              *jupyter_client*) echo 8.6.3 ;;
              *) echo "$0" ;;
            esac
            SH);
        chmod($recorded.'/python', 0o755);

        $run = runInstall(
            ['--prefix', $workspace.'/prefix', '--python', $recorded.'/python'],
            quartoPath: $quarto,
            runner: $runner,
        );

        // What the interpreter calls itself is what Quarto reports, and so is
        // what the install report has to name. Ask the path the probe will run,
        // which is the symlink on Quarto's PATH: invoked through a symlink, a
        // Linux CPython reports a different sys.executable than the same
        // interpreter invoked by its own path, and the report follows the probe.
        $executable = trim($runner([$quarto.'/python3', '-c', 'import sys; print(sys.executable)'])['stdout']);

        expect($run['result'])->toBe(0)
            ->and($workspace.'/prefix/share/jupyter/kernels/php/kernel.json')->toBeFile()
            ->and($run['stdout'])->toContain(sprintf('  quarto       %s (no Jupyter)', $executable))
            ->and($run['stderr'])->toContain($executable)
            ->and($run['stderr'])->toContain('no Jupyter in it')
            ->and($run['stderr'])->toContain(sprintf('export PATH="%s:$PATH"', $recorded))
            ->and($run['stderr'])->toContain('quarto check jupyter');
    });
});

describe('building a Python when none can host the kernel', function () {
    /**
     * A runner for which the machine's Python has no ipykernel and a named
     * environment's interpreter has everything.
     *
     * The keys are matched in order, so the environment's own calls are
     * answered before the general "no ipykernel anywhere" rule.
     *
     * @param  list<string>  $calls
     */
    $world = function (string $environment, array &$calls = []): Closure {
        return installRunner([
            $environment.'/bin/python -c import sys' => ['code' => 0],
            $environment.'/bin/python -m pip install' => ['code' => 0],
            $environment.'/bin/python -c import ipykernel' => ['stdout' => "7.4.0\n"],
            'import ipykernel' => ['code' => 1],
        ], $calls);
    };

    it('offers to build one and installs into it when the answer is yes', function () use ($world) {
        $prefix = installWorkspace();
        $target = installWorkspace().'/env';
        $asked = [];

        $run = runInstall(
            ['--prefix', $prefix],
            ['HOME' => $target.'-home'],
            $world($target.'-home/.venvs/juphyter'),
            null,
            'Darwin',
            null,
            null,
            function (string $question) use (&$asked): bool {
                $asked[] = $question;

                return true;
            },
        );

        expect($run['result'])->toBe(0)
            ->and($asked)->toHaveCount(1)
            ->and($asked[0])->toContain('jupyterlab')
            ->and($asked[0])->toContain($target.'-home/.venvs/juphyter');

        $spec = readKernelSpec($prefix.'/share/jupyter/kernels/php/kernel.json');

        expect($spec['argv'][0])->toBe($target.'-home/.venvs/juphyter/bin/python');
    });

    it('does not ask when --create-venv names a directory', function () use ($world) {
        $prefix = installWorkspace();
        $target = installWorkspace().'/env';
        $asked = [];

        $run = runInstall(
            ['--prefix', $prefix, '--create-venv', $target],
            ['HOME' => '/home/you'],
            $world($target),
            null,
            'Darwin',
            null,
            null,
            function (string $question) use (&$asked): bool {
                $asked[] = $question;

                return true;
            },
        );

        expect($run['result'])->toBe(0)
            ->and($asked)->toBe([]);

        $spec = readKernelSpec($prefix.'/share/jupyter/kernels/php/kernel.json');

        expect($spec['argv'][0])->toBe($target.'/bin/python');
    });

    it('says what it is building, because installing it takes a minute', function () use ($world) {
        $prefix = installWorkspace();
        $target = installWorkspace().'/env';

        $run = runInstall(
            ['--prefix', $prefix, '--create-venv', $target],
            [],
            $world($target),
        );

        expect($run['stderr'])
            ->toContain('building a virtual environment at '.$target)
            ->toContain('jupyterlab');
    });

    it('declines without a terminal and names the option that would do it', function () {
        $prefix = installWorkspace();

        $run = runInstall(
            ['--prefix', $prefix],
            ['HOME' => '/home/you'],
            installRunner(['import ipykernel' => ['code' => 1]]),
        );

        expect($run['result'])->toBe(1)
            ->and($run['stderr'])
            ->toContain('ipykernel is not installed')
            ->toContain('--create-venv /home/you/.venvs/juphyter')
            ->and($prefix.'/share/jupyter/kernels/php/kernel.json')->not->toBeFile();
    });

    it('does not offer to build when --python named an interpreter', function () {
        $prefix = installWorkspace();

        $run = runInstall(
            ['--prefix', $prefix, '--python', '/usr/bin/python3'],
            ['HOME' => '/home/you'],
            installRunner(['import ipykernel' => ['code' => 1]]),
            null,
            'Darwin',
            null,
            null,
            fn (string $question): bool => true,
        );

        // The reader named an interpreter, so building a different one would
        // answer a question they did not ask.
        expect($run['result'])->toBe(1)
            ->and($run['stderr'])->toContain('ipykernel is not installed')
            ->and($run['stderr'])->not->toContain('--create-venv');
    });

    it('has no default directory to offer when HOME is unset', function () {
        $prefix = installWorkspace();

        $run = runInstall(
            ['--prefix', $prefix],
            [],
            installRunner(['import ipykernel' => ['code' => 1]]),
            null,
            'Darwin',
            null,
            null,
            fn (string $question): bool => true,
        );

        expect($run['result'])->toBe(1)
            ->and($run['stderr'])
            ->toContain('--create-venv PATH')
            ->toContain('HOME is unset');
    });

    it('lists --create-venv in its help', function () {
        expect(InstallCommand::usage())->toContain('--create-venv PATH');
    });
});

describe('shell startup files', function () {
    it('writes to none, and offers no option that would', function () {
        $workspace = installWorkspace();
        $rc = $workspace.'/zshrc';
        file_put_contents($rc, "# untouched\n");

        $run = runInstall(['--prefix', $workspace.'/prefix']);

        expect($run['result'])->toBe(0)
            ->and(file_get_contents($rc))->toBe("# untouched\n")
            ->and(InstallCommand::usage())->not->toContain('--path-file');
    });

    it('rejects --path-file, which it no longer has', function () {
        $workspace = installWorkspace();
        $rc = $workspace.'/zshrc';
        file_put_contents($rc, "# untouched\n");

        $run = runInstall(['--prefix', $workspace.'/prefix', '--path-file', $rc]);

        expect($run['result'])->toBe(1)
            ->and(file_get_contents($rc))->toBe("# untouched\n");
    });
});
