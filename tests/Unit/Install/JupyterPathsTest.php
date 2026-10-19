<?php

declare(strict_types=1);

use Juphyter\Install\JupyterPaths;

/** A set of paths over a made-up environment, so no test reads the real one. */
function jupyterPaths(array $environment, string $osFamily = 'Darwin'): JupyterPaths
{
    return new JupyterPaths($environment, $osFamily);
}

describe('the data directory', function () {
    it('uses JUPYTER_DATA_DIR when it is set', function () {
        expect(jupyterPaths(['JUPYTER_DATA_DIR' => '/tmp/jupyter', 'HOME' => '/home/erik'])->dataDirectory())
            ->toBe('/tmp/jupyter');
    });

    it('ignores an empty JUPYTER_DATA_DIR', function () {
        expect(jupyterPaths(['JUPYTER_DATA_DIR' => '', 'HOME' => '/home/erik'])->dataDirectory())
            ->toBe('/home/erik/Library/Jupyter');
    });

    it('drops a trailing separator', function () {
        expect(jupyterPaths(['JUPYTER_DATA_DIR' => '/tmp/jupyter/'])->dataDirectory())
            ->toBe('/tmp/jupyter');
    });

    it('puts it under ~/Library/Jupyter on macOS', function () {
        expect(jupyterPaths(['HOME' => '/Users/erik'], 'Darwin')->dataDirectory())
            ->toBe('/Users/erik/Library/Jupyter');
    });

    it('puts it under XDG_DATA_HOME on Linux', function () {
        expect(jupyterPaths(['HOME' => '/home/erik', 'XDG_DATA_HOME' => '/home/erik/.data'], 'Linux')->dataDirectory())
            ->toBe('/home/erik/.data/jupyter');
    });

    it('falls back to ~/.local/share on Linux without XDG_DATA_HOME', function () {
        expect(jupyterPaths(['HOME' => '/home/erik'], 'Linux')->dataDirectory())
            ->toBe('/home/erik/.local/share/jupyter')
            ->and(jupyterPaths(['HOME' => '/home/erik', 'XDG_DATA_HOME' => ''], 'Linux')->dataDirectory())
            ->toBe('/home/erik/.local/share/jupyter');
    });

    it('treats BSD like Linux', function () {
        expect(jupyterPaths(['HOME' => '/home/erik'], 'BSD')->dataDirectory())
            ->toBe('/home/erik/.local/share/jupyter');
    });

    it('follows JUPYTER_PLATFORM_DIRS to the platformdirs location', function () {
        expect(jupyterPaths(['HOME' => '/Users/erik', 'JUPYTER_PLATFORM_DIRS' => '1'], 'Darwin')->dataDirectory())
            ->toBe('/Users/erik/Library/Application Support/jupyter');
    });

    it('reads the words jupyter_core reads as false', function () {
        foreach (['no', 'n', 'FALSE', 'off', '0', '0.0'] as $falsey) {
            expect(jupyterPaths(['HOME' => '/Users/erik', 'JUPYTER_PLATFORM_DIRS' => $falsey], 'Darwin')->dataDirectory())
                ->toBe('/Users/erik/Library/Jupyter');
        }

        // Anything else is true, including an empty value, which is how
        // jupyter_core's envset() reads it.
        expect(jupyterPaths(['HOME' => '/Users/erik', 'JUPYTER_PLATFORM_DIRS' => ''], 'Darwin')->dataDirectory())
            ->toBe('/Users/erik/Library/Application Support/jupyter');
    });

    it('refuses Windows, which the kernel cannot run on', function () {
        expect(fn () => jupyterPaths(['HOME' => 'C:\\Users\\erik'], 'Windows')->dataDirectory())
            ->toThrow(RuntimeException::class, 'macOS and Linux only');
    });

    it('says what to do when HOME is not set', function () {
        expect(fn () => jupyterPaths([], 'Linux')->dataDirectory())
            ->toThrow(RuntimeException::class, 'JUPYTER_DATA_DIR');
    });
});

describe('the kernels directory', function () {
    it('sits inside the data directory', function () {
        expect(jupyterPaths(['JUPYTER_DATA_DIR' => '/tmp/jupyter'])->kernelsDirectory())
            ->toBe('/tmp/jupyter/kernels');
    });

    it('moves under a prefix when one is given', function () {
        expect(jupyterPaths(['JUPYTER_DATA_DIR' => '/tmp/jupyter'])->kernelsDirectory('/opt/venv'))
            ->toBe('/opt/venv/share/jupyter/kernels');
    });

    it('ignores an empty prefix', function () {
        expect(jupyterPaths(['JUPYTER_DATA_DIR' => '/tmp/jupyter'])->kernelsDirectory(''))
            ->toBe('/tmp/jupyter/kernels');
    });

    it('does not need HOME or a platform when a prefix is given', function () {
        expect(jupyterPaths([], 'Windows')->kernelsDirectory('/opt/venv'))
            ->toBe('/opt/venv/share/jupyter/kernels');
    });

    it('names one kernelspec after the kernel', function () {
        $paths = jupyterPaths(['JUPYTER_DATA_DIR' => '/tmp/jupyter']);

        expect($paths->kernelDirectory('php85'))->toBe('/tmp/jupyter/kernels/php85')
            ->and($paths->kernelDirectory('php85', '/opt/venv'))
            ->toBe('/opt/venv/share/jupyter/kernels/php85');
    });

    it('collapses a doubled separator from a prefix with a trailing slash', function () {
        expect(jupyterPaths([])->kernelDirectory('php', '/opt/venv/'))
            ->toBe('/opt/venv/share/jupyter/kernels/php');
    });
});

it('reads the running process in fromEnvironment', function () {
    $paths = JupyterPaths::fromEnvironment();

    expect($paths->kernelsDirectory('/opt/venv'))->toBe('/opt/venv/share/jupyter/kernels')
        ->and($paths->dataDirectory())->toStartWith('/')
        ->and($paths->kernelsDirectory())->toBe($paths->dataDirectory().'/kernels');
})->skipOnWindows();
