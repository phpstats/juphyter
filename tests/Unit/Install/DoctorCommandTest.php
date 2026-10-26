<?php

declare(strict_types=1);

use Juphyter\Console\DoctorCommand;
use Juphyter\Install\JupyterPaths;
use Juphyter\Install\PythonProbe;
use Juphyter\Install\QuartoProbe;

/** A temporary directory, removed after the test. */
function doctorWorkspace(): string
{
    $directory = sys_get_temp_dir().'/juphyter-doctor-'.bin2hex(random_bytes(6));
    mkdir($directory, 0o777, true);

    return $directory;
}

/** Delete a directory tree. */
function doctorRemoveTree(string $path): void
{
    if (is_dir($path) && ! is_link($path)) {
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                doctorRemoveTree($path.'/'.$entry);
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
    foreach (glob(sys_get_temp_dir().'/juphyter-doctor-*') ?: [] as $directory) {
        doctorRemoveTree($directory);
    }
});

/**
 * A `PATH` directory holding the executables the probes look for by name.
 *
 * Both probes read `PATH` themselves before asking the runner anything, so the
 * names have to exist as files. What they answer is the runner's business.
 */
function doctorPath(bool $withQuarto = true): string
{
    $directory = sys_get_temp_dir().'/juphyter-doctorpath-'.bin2hex(random_bytes(6))
        .($withQuarto ? '-q' : '');

    if (! is_dir($directory)) {
        mkdir($directory, 0o777, true);

        foreach ($withQuarto ? ['python3', 'quarto'] : ['python3'] as $name) {
            file_put_contents($directory.'/'.$name, "#!/bin/sh\n");
            chmod($directory.'/'.$name, 0o755);
        }
    }

    return $directory;
}

/** A package root holding the Python wrapper, as an installed package has. */
function doctorPackageRoot(): string
{
    static $directory = null;

    if ($directory === null) {
        $directory = sys_get_temp_dir().'/juphyter-doctorroot-'.bin2hex(random_bytes(6));
        mkdir($directory.'/kernel/juphyter_kernel', 0o777, true);
        file_put_contents($directory.'/kernel/juphyter_kernel/kernel.py', "# wrapper\n");
    }

    return $directory;
}

/**
 * A runner answering for a healthy machine, with overrides matched first.
 *
 * @param  array<string, array{code?: int, stdout?: string, stderr?: string}>  $overrides
 */
function doctorRunner(array $overrides = []): Closure
{
    $answers = $overrides + [
        'sys.executable' => ['stdout' => "/opt/venv/bin/python3\n"],
        'import ipykernel' => ['stdout' => "7.4.0\n"],
        'import jupyter_client' => ['stdout' => "8.10.0\n"],
        '-v' => ['stdout' => "PHP 8.5.10\n"],
    ];

    return function (array $argv) use ($answers): array {
        $line = implode(' ', $argv);

        foreach ($answers as $needle => $answer) {
            if (str_contains($line, (string) $needle)) {
                return [
                    'code' => $answer['code'] ?? 0,
                    'stdout' => $answer['stdout'] ?? '',
                    'stderr' => $answer['stderr'] ?? '',
                ];
            }
        }

        return ['code' => 127, 'stdout' => '', 'stderr' => 'not runnable'];
    };
}

/**
 * Write a juphyter kernelspec under a prefix.
 *
 * @param  array<string, string>  $env  Overrides for the spec's env block.
 */
function doctorKernel(string $prefix, string $name, array $env = [], ?string $python = null): void
{
    $directory = $prefix.'/share/jupyter/kernels/'.$name;
    mkdir($directory, 0o777, true);

    $module = doctorPackageRoot().'/kernel';

    file_put_contents($directory.'/kernel.json', json_encode([
        'argv' => [$python ?? '/opt/venv/bin/python3', '-m', 'juphyter_kernel', '-f', '{connection_file}'],
        'display_name' => 'PHP 8.5',
        'language' => 'php',
        'interrupt_mode' => 'signal',
        'env' => $env + [
            'JUPHYTER_PHP' => '/opt/homebrew/bin/php',
            'JUPHYTER_ENGINE' => doctorPackageRoot().'/kernel/juphyter_kernel/kernel.py',
            'PYTHONPATH' => $module,
        ],
    ], JSON_PRETTY_PRINT));
}

/**
 * Run the command with everything injected.
 *
 * @return array{result: int, stdout: string, stderr: string}
 */
function runDoctor(
    array $arguments,
    ?Closure $runner = null,
    bool $withQuarto = true,
    ?string $quartoPath = null,
): array {
    $runner ??= doctorRunner();
    $path = doctorPath($withQuarto);

    return captured(fn ($stdout, $stderr) => (new DoctorCommand(
        new JupyterPaths(['HOME' => '/home/you'], 'Darwin'),
        new PythonProbe($runner, $path),
        new QuartoProbe($runner, $quartoPath ?? $path, []),
        $runner,
        doctorPackageRoot(),
        $stdout,
        $stderr,
    ))->run($arguments));
}

describe('a healthy machine', function () {
    it('reports every check and says both front ends are ready', function () {
        $prefix = doctorWorkspace();
        doctorKernel($prefix, 'php');

        $run = runDoctor(['--prefix', $prefix]);

        expect($run['result'])->toBe(0)
            ->and($run['stdout'])
            ->toContain('the Python wrapper is at')
            ->toContain('/opt/venv/bin/python3 has ipykernel 7.4.0')
            ->toContain('with jupyter_client 8.10.0')
            ->toContain('php is complete')
            ->toContain('JupyterLab: ready, with php.')
            ->toContain('Quarto: ready.');
    });

    it('names every usable kernel in the verdict', function () {
        $prefix = doctorWorkspace();
        doctorKernel($prefix, 'php');
        doctorKernel($prefix, 'php85');

        $run = runDoctor(['--prefix', $prefix]);

        expect($run['result'])->toBe(0)
            ->and($run['stdout'])->toContain('JupyterLab: ready, with php, php85.');
    });

    it('changes nothing it looked at', function () {
        $prefix = doctorWorkspace();
        doctorKernel($prefix, 'php');
        $before = file_get_contents($prefix.'/share/jupyter/kernels/php/kernel.json');

        runDoctor(['--prefix', $prefix]);

        expect(file_get_contents($prefix.'/share/jupyter/kernels/php/kernel.json'))->toBe($before);
    });
});

describe('Quarto', function () {
    it('says it is not installed rather than not ready', function () {
        $prefix = doctorWorkspace();
        doctorKernel($prefix, 'php');

        $run = runDoctor(['--prefix', $prefix], null, false, '');

        expect($run['result'])->toBe(0)
            ->and($run['stdout'])
            ->toContain('quarto is not on PATH')
            ->toContain('Quarto: not installed.');
    });

    it('fails when the interpreter Quarto will run has no Jupyter, and gives the PATH entry', function () {
        $prefix = doctorWorkspace();
        doctorKernel($prefix, 'php');

        $run = runDoctor(
            ['--prefix', $prefix],
            doctorRunner(['import jupyter_client' => ['code' => 1]]),
        );

        expect($run['result'])->toBe(1)
            ->and($run['stdout'])
            ->toContain('which has no Jupyter in it')
            ->toContain('Quarto: not ready.')
            ->toContain('export PATH="/opt/venv/bin:$PATH"')
            // Editor selection leads, and the remedy must not tell anyone to
            // persist the entry: that would change python3 for every Quarto
            // project on the machine.
            ->toContain('Python:')
            ->toContain('Select Interpreter')
            ->not->toContain('>> ~/.zshrc');
    });

    it('takes the PATH entry from a working kernel when no interpreter is usable now', function () {
        $prefix = doctorWorkspace();
        doctorKernel($prefix, 'php', [], '/elsewhere/venv/bin/python');

        // ipykernel is gone from the interpreter a fresh install would pick, so
        // the only interpreter known to work is the one the kernelspec names.
        $runner = doctorRunner([
            '/elsewhere/venv/bin/python -c import ipykernel' => ['stdout' => "7.4.0\n"],
            'import ipykernel' => ['code' => 1],
            'import jupyter_client' => ['code' => 1],
        ]);

        $run = runDoctor(['--prefix', $prefix], $runner);

        expect($run['result'])->toBe(1)
            ->and($run['stdout'])->toContain('export PATH="/elsewhere/venv/bin:$PATH"');
    });
});

describe('a Python that cannot host the kernel', function () {
    it('fails and prints the command that fixes it', function () {
        $prefix = doctorWorkspace();

        $run = runDoctor(
            ['--prefix', $prefix],
            doctorRunner(['import ipykernel' => ['code' => 1]]),
        );

        expect($run['result'])->toBe(1)
            ->and($run['stdout'])
            ->toContain('has no ipykernel')
            ->toContain('-m pip install "ipykernel>=7.0"');
    });

    it('fails an ipykernel older than the kernel needs', function () {
        $prefix = doctorWorkspace();

        $run = runDoctor(
            ['--prefix', $prefix],
            doctorRunner(['import ipykernel' => ['stdout' => "6.29.0\n"]]),
        );

        expect($run['result'])->toBe(1)
            ->and($run['stdout'])->toContain('has ipykernel 6.29.0 and the kernel needs 7.0');
    });
});

describe('kernels whose recorded paths have moved', function () {
    it('names a PHP that no longer runs', function () {
        $prefix = doctorWorkspace();
        doctorKernel($prefix, 'php83', ['JUPHYTER_PHP' => '/opt/homebrew/Cellar/php/8.3.1/bin/php']);

        $run = runDoctor(
            ['--prefix', $prefix],
            doctorRunner(['/opt/homebrew/Cellar/php/8.3.1/bin/php -v' => ['code' => 127]]),
        );

        expect($run['result'])->toBe(1)
            ->and($run['stdout'])
            ->toContain('php83: its PHP /opt/homebrew/Cellar/php/8.3.1/bin/php does not run')
            ->toContain('JupyterLab: no usable kernel.');
    });

    it('names an engine a deleted project took with it', function () {
        $prefix = doctorWorkspace();
        doctorKernel($prefix, 'php', ['JUPHYTER_ENGINE' => '/gone/vendor/phpstats/juphyter/bin/juphyter']);

        $run = runDoctor(['--prefix', $prefix]);

        expect($run['result'])->toBe(1)
            ->and($run['stdout'])->toContain('its engine /gone/vendor/phpstats/juphyter/bin/juphyter is gone');
    });

    it('names a Python wrapper that is gone', function () {
        $prefix = doctorWorkspace();
        doctorKernel($prefix, 'php', ['PYTHONPATH' => '/gone/kernel']);

        $run = runDoctor(['--prefix', $prefix]);

        expect($run['result'])->toBe(1)
            ->and($run['stdout'])->toContain('its Python wrapper /gone/kernel is gone');
    });

    it('names a Python that lost its ipykernel', function () {
        $prefix = doctorWorkspace();
        doctorKernel($prefix, 'php', [], '/gone/venv/bin/python');

        $runner = doctorRunner(['/gone/venv/bin/python -c import ipykernel' => ['code' => 127]]);
        $run = runDoctor(['--prefix', $prefix], $runner);

        expect($run['result'])->toBe(1)
            ->and($run['stdout'])->toContain('its Python /gone/venv/bin/python is gone or has no ipykernel');
    });
});

describe('what it leaves alone', function () {
    it('ignores a kernel juphyter did not write', function () {
        $prefix = doctorWorkspace();
        $directory = $prefix.'/share/jupyter/kernels/python3';
        mkdir($directory, 0o777, true);
        file_put_contents($directory.'/kernel.json', json_encode([
            'argv' => ['/usr/bin/python3', '-m', 'ipykernel_launcher'],
            'display_name' => 'Python 3',
            'language' => 'python',
        ]));

        $run = runDoctor(['--prefix', $prefix]);

        expect($run['stdout'])
            ->toContain('no juphyter kernel is installed here')
            ->and($run['stdout'])->not->toContain('python3 is complete');
    });

    it('says so when nothing is installed, and what to run', function () {
        $run = runDoctor(['--prefix', doctorWorkspace()]);

        expect($run['stdout'])
            ->toContain('no juphyter kernel is installed here')
            ->toContain('Run juphyter install to write one.');
    });

    it('says to install a Python first when there is not one either', function () {
        $run = runDoctor(
            ['--prefix', doctorWorkspace()],
            doctorRunner(['import ipykernel' => ['code' => 1]]),
        );

        expect($run['stdout'])->toContain('Install a Python that can host the kernel');
    });
});

describe('the command surface', function () {
    it('prints its own help for --help and -h', function () {
        foreach (['--help', '-h'] as $flag) {
            $run = runDoctor([$flag]);

            expect($run['result'])->toBe(0)
                ->and($run['stdout'])->toContain('Usage: juphyter doctor');
        }
    });

    it('documents --prefix and the exit code', function () {
        expect(DoctorCommand::usage())
            ->toContain('--prefix PATH')
            ->toContain('exit code is 1');
    });

    it('rejects an unknown option', function () {
        $run = runDoctor(['--nope', 'x']);

        expect($run['result'])->toBe(1)
            ->and($run['stderr'])->toContain('--nope');
    });

    it('is dispatched as "doctor" and describes itself', function () {
        expect(DoctorCommand::name())->toBe('doctor')
            ->and(DoctorCommand::description())->toContain('still run');
    });
});

it('treats a default Python that cannot host a kernel as advisory once kernels work', function () {
    $prefix = doctorWorkspace();
    doctorKernel($prefix, 'php');

    // The default python3 differs from the one the kernel names, and only the
    // default is missing ipykernel.
    $run = runDoctor(['--prefix='.$prefix], doctorRunner([
        '/opt/brew/bin/python3' => ['code' => 1, 'stderr' => "No module named 'ipykernel'\n"],
        'sys.executable' => ['stdout' => "/opt/brew/bin/python3\n"],
    ]), withQuarto: false);

    // The kernel names its own interpreter and runs in it, so the default
    // python3 is worth reporting and is not a fault of this machine.
    expect($run['result'])->toBe(0)
        ->and($run['stdout'])->toContain('warn')
        ->and($run['stdout'])->not->toContain('  fail');
});
