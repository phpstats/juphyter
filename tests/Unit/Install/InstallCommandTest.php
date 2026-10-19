<?php

declare(strict_types=1);

use Juphyter\Console\InstallCommand;
use Juphyter\Engine\Interrupt;
use Juphyter\Install\JupyterPaths;
use Juphyter\Install\KernelSpec;
use Juphyter\Install\PythonProbe;

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
    $temporary = glob(sys_get_temp_dir().'/juphyter-probe-path-*') ?: [];

    foreach ([...$temporary, ...(glob(sys_get_temp_dir().'/juphyter-package-root-*') ?: [])] as $directory) {
        installRemoveTree($directory);
    }
});

/**
 * Run `juphyter install` with fakes, and return the exit code and both streams.
 *
 * @param  list<string>  $arguments
 * @param  array<string, string>  $environment  What {@see JupyterPaths} reads.
 * @return array{result: int, stdout: string, stderr: string}
 */
function runInstall(
    array $arguments,
    array $environment = [],
    ?Closure $runner = null,
    ?string $packageRoot = null,
    string $osFamily = 'Darwin',
): array {
    $runner ??= installRunner();
    $packageRoot ??= installPackageRoot();

    return captured(fn ($stdout, $stderr) => (new InstallCommand(
        new JupyterPaths($environment, $osFamily),
        new PythonProbe($runner, installPythonPath()),
        $runner,
        $packageRoot,
        $stdout,
        $stderr,
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
                'JUPHYTER_PHP' => PHP_BINARY,
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

        expect($spec['env']['JUPHYTER_PHP'])->toBe(PHP_BINARY);

        foreach ($calls as $call) {
            expect(implode(' ', $call))->not->toContain('PHP_BINARY');
        }
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
