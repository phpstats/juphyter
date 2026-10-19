<?php

declare(strict_types=1);

use Juphyter\Console\UninstallCommand;
use Juphyter\Install\JupyterPaths;

/** A temporary directory to use as an install prefix, removed after the test. */
function uninstallWorkspace(): string
{
    $directory = sys_get_temp_dir().'/juphyter-uninstall-'.bin2hex(random_bytes(6));
    mkdir($directory, 0o777, true);

    return $directory;
}

/** Delete a directory tree. */
function uninstallRemoveTree(string $path): void
{
    if (is_dir($path) && ! is_link($path)) {
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                uninstallRemoveTree($path.'/'.$entry);
            }
        }

        rmdir($path);

        return;
    }

    if (file_exists($path) || is_link($path)) {
        unlink($path);
    }
}

/**
 * Write a kernelspec under a prefix, and return its directory.
 *
 * The document is written here rather than by running `install`, so that a
 * failure in this file points at `uninstall`.
 *
 * @param  array<string, mixed>|string  $spec  The `kernel.json` contents.
 */
function seedKernelSpec(string $prefix, string $name, array|string $spec): string
{
    $directory = $prefix.'/share/jupyter/kernels/'.$name;
    mkdir($directory, 0o777, true);

    file_put_contents(
        $directory.'/kernel.json',
        is_string($spec) ? $spec : json_encode($spec, JSON_PRETTY_PRINT),
    );

    return $directory;
}

/** A kernelspec of the shape juphyter writes. */
function juphyterSpec(): array
{
    return [
        'argv' => ['/opt/venv/bin/python3', '-m', 'juphyter_kernel', '-f', '{connection_file}'],
        'display_name' => 'PHP 8.5',
        'language' => 'php',
        'interrupt_mode' => 'signal',
        'env' => [
            'JUPHYTER_PHP' => '/herd/bin/php85',
            'JUPHYTER_ENGINE' => '/app/vendor/phpstats/juphyter/bin/juphyter',
            'PYTHONPATH' => '/app/vendor/phpstats/juphyter/kernel',
        ],
    ];
}

/**
 * Run `juphyter uninstall` and return the exit code and both streams.
 *
 * @param  list<string>  $arguments
 * @param  array<string, string>  $environment
 * @return array{result: int, stdout: string, stderr: string}
 */
function runUninstall(array $arguments, array $environment = []): array
{
    return captured(fn ($stdout, $stderr) => (new UninstallCommand(
        new JupyterPaths($environment, 'Darwin'),
        $stdout,
        $stderr,
    ))->run($arguments));
}

afterEach(function () {
    foreach (glob(sys_get_temp_dir().'/juphyter-uninstall-*') ?: [] as $directory) {
        uninstallRemoveTree($directory);
    }
});

describe('removing a kernelspec', function () {
    it('removes one juphyter wrote, under a prefix', function () {
        $prefix = uninstallWorkspace();
        $directory = seedKernelSpec($prefix, 'php', juphyterSpec());

        $run = runUninstall(['--prefix', $prefix]);

        expect($run['result'])->toBe(0)
            ->and($run['stdout'])->toContain('Removed the juphyter kernel "php"')
            ->and(is_dir($directory))->toBeFalse()
            ->and(is_dir($prefix.'/share/jupyter/kernels'))->toBeTrue();
    });

    it('removes one under the Jupyter data directory', function () {
        $home = uninstallWorkspace();
        $directory = $home.'/jupyter/kernels/php85';
        mkdir($directory, 0o777, true);
        file_put_contents($directory.'/kernel.json', json_encode(juphyterSpec()));

        $run = runUninstall(['--name', 'php85'], ['JUPYTER_DATA_DIR' => $home.'/jupyter']);

        expect($run['result'])->toBe(0)
            ->and(is_dir($directory))->toBeFalse();
    });

    it('removes the copy of the Python wrapper a PHAR install left inside it', function () {
        $prefix = uninstallWorkspace();
        $directory = seedKernelSpec($prefix, 'php', juphyterSpec());
        mkdir($directory.'/kernel/juphyter_kernel', 0o777, true);
        file_put_contents($directory.'/kernel/juphyter_kernel/__init__.py', "KERNEL = 1\n");

        expect(runUninstall(['--prefix', $prefix])['result'])->toBe(0)
            ->and(is_dir($directory))->toBeFalse();
    });

    it('removes only the kernel named', function () {
        $prefix = uninstallWorkspace();
        seedKernelSpec($prefix, 'php83', juphyterSpec());
        seedKernelSpec($prefix, 'php85', juphyterSpec());

        expect(runUninstall(['--prefix', $prefix, '--name', 'php83'])['result'])->toBe(0)
            ->and(is_dir($prefix.'/share/jupyter/kernels/php83'))->toBeFalse()
            ->and($prefix.'/share/jupyter/kernels/php85/kernel.json')->toBeFile();
    });

    it('lowercases the name it is given', function () {
        $prefix = uninstallWorkspace();
        seedKernelSpec($prefix, 'php85', juphyterSpec());

        expect(runUninstall(['--prefix', $prefix, '--name', 'PHP85'])['result'])->toBe(0)
            ->and(is_dir($prefix.'/share/jupyter/kernels/php85'))->toBeFalse();
    });

    it('unlinks a symlink inside the kernelspec rather than following it', function () {
        $prefix = uninstallWorkspace();
        $directory = seedKernelSpec($prefix, 'php', juphyterSpec());
        $outside = $prefix.'/outside';
        mkdir($outside, 0o777, true);
        file_put_contents($outside.'/keep.txt', "keep\n");
        symlink($outside, $directory.'/linked');

        expect(runUninstall(['--prefix', $prefix])['result'])->toBe(0)
            ->and(is_dir($directory))->toBeFalse()
            ->and($outside.'/keep.txt')->toBeFile();
    });
});

describe('refusing to remove', function () {
    it('leaves a kernelspec juphyter did not write', function () {
        $prefix = uninstallWorkspace();
        $directory = seedKernelSpec($prefix, 'php', [
            'argv' => ['other-php-kernel'],
            'display_name' => 'Somebody else\'s PHP',
            'language' => 'php',
        ]);

        $run = runUninstall(['--prefix', $prefix]);

        expect($run['result'])->toBe(1)
            ->and($run['stderr'])->toContain('was not written by juphyter')
            ->and($directory.'/kernel.json')->toBeFile();
    });

    it('leaves a Python kernelspec that happens to share the name', function () {
        $prefix = uninstallWorkspace();
        $directory = seedKernelSpec($prefix, 'php', [
            'argv' => ['python3', '-m', 'ipykernel_launcher', '-f', '{connection_file}'],
            'display_name' => 'Python 3',
            'language' => 'python',
        ]);

        expect(runUninstall(['--prefix', $prefix])['result'])->toBe(1)
            ->and($directory.'/kernel.json')->toBeFile();
    });

    it('leaves a kernel.json it cannot parse', function () {
        $prefix = uninstallWorkspace();
        $directory = seedKernelSpec($prefix, 'php', '{ not json');

        expect(runUninstall(['--prefix', $prefix])['result'])->toBe(1)
            ->and($directory.'/kernel.json')->toBeFile();
    });

    it('says there is nothing there when the kernel is not installed', function () {
        $prefix = uninstallWorkspace();
        $run = runUninstall(['--prefix', $prefix, '--name', 'php85']);

        expect($run['result'])->toBe(1)
            ->and($run['stderr'])
            ->toContain('no kernel named "php85"')
            ->toContain($prefix.'/share/jupyter/kernels/php85');
    });

    it('says there is nothing there when the directory holds no kernel.json', function () {
        $prefix = uninstallWorkspace();
        mkdir($prefix.'/share/jupyter/kernels/php', 0o777, true);

        expect(runUninstall(['--prefix', $prefix])['result'])->toBe(1)
            ->and(is_dir($prefix.'/share/jupyter/kernels/php'))->toBeTrue();
    });

    it('refuses a name it cannot use', function () {
        $run = runUninstall(['--prefix', '/tmp', '--name', '../escape']);

        expect($run['result'])->toBe(1)
            ->and($run['stderr'])->toContain('not a usable kernel name');
    });
});

describe('the command surface', function () {
    it('is dispatched as "uninstall" and describes itself without being built', function () {
        expect(UninstallCommand::name())->toBe('uninstall')
            ->and(UninstallCommand::description())->toBe('Remove a kernelspec juphyter installed');
    });

    it('prints its own help for --help and -h', function () {
        foreach (['--help', '-h'] as $flag) {
            $run = runUninstall([$flag]);

            expect($run['result'])->toBe(0)
                ->and($run['stdout'])->toContain('Usage: juphyter uninstall [options]')
                ->and($run['stderr'])->toBe('');
        }
    });

    it('rejects an unknown option, a stray argument, and a missing value', function () {
        expect(runUninstall(['--nope', 'x'])['stderr'])->toContain('unknown option "--nope"')
            ->and(runUninstall(['php'])['stderr'])->toContain('unexpected argument "php"')
            ->and(runUninstall(['--name'])['stderr'])->toContain('--name needs a value');
    });

    it('does not take install\'s options', function () {
        expect(runUninstall(['--python', '/opt/venv/bin/python'])['stderr'])
            ->toContain('unknown option "--python"');
    });

    it('is built by make() without arguments, the way the dispatcher builds it', function () {
        expect(UninstallCommand::make())->toBeInstanceOf(UninstallCommand::class);
    });
});
