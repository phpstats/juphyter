<?php

declare(strict_types=1);

use Juphyter\Install\QuartoProbe;

/**
 * A runner that answers from a table of canned results and records the calls.
 *
 * The key is a substring of the whole command line, so a test can answer for
 * one interpreter and not for another. An unmatched call comes back the way
 * {@see Juphyter\Install\PythonProbe::processRunner()} reports a path that is
 * not there, because that is what a `QUARTO_PYTHON` pointing at a moved
 * virtual environment looks like.
 *
 * @param  array<string, array{code?: int, stdout?: string, stderr?: string}>  $answers
 * @param  list<list<string>>  $calls  Filled with every argument list the probe ran.
 */
function quartoRunner(array $answers, array &$calls = []): Closure
{
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

        return [
            'code' => 127,
            'stdout' => '',
            'stderr' => sprintf('%s is not an executable file', $argv[0] ?? ''),
        ];
    };
}

/**
 * A directory of fake executables, to stand in for a PATH entry.
 *
 * @param  list<string>  $names
 */
function quartoBinDirectory(array $names): string
{
    $directory = sys_get_temp_dir().'/juphyter-quarto-bin-'.bin2hex(random_bytes(6));
    mkdir($directory, 0o777, true);

    foreach ($names as $name) {
        file_put_contents($directory.'/'.$name, "#!/bin/sh\n");
        chmod($directory.'/'.$name, 0o755);
    }

    return $directory;
}

afterEach(function () {
    foreach (glob(sys_get_temp_dir().'/juphyter-quarto-bin-*') ?: [] as $directory) {
        foreach (scandir($directory) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                unlink($directory.'/'.$entry);
            }
        }

        rmdir($directory);
    }
});

describe('whether Quarto is installed', function () {
    it('finds quarto on PATH', function () {
        $bin = quartoBinDirectory(['quarto']);

        expect((new QuartoProbe(quartoRunner([]), $bin, []))->installed())->toBeTrue();
    });

    it('reports none when PATH has no quarto', function () {
        $bin = quartoBinDirectory(['python3']);

        expect((new QuartoProbe(quartoRunner([]), $bin, []))->installed())->toBeFalse();
    });

    it('ignores a quarto that is not executable', function () {
        $bin = quartoBinDirectory([]);
        file_put_contents($bin.'/quarto', "#!/bin/sh\n");
        chmod($bin.'/quarto', 0o644);

        expect((new QuartoProbe(quartoRunner([]), $bin, []))->installed())->toBeFalse();
    });
});

describe('resolving the interpreter', function () {
    it('takes the first python3 on PATH', function () {
        $first = quartoBinDirectory(['python3']);
        $second = quartoBinDirectory(['python3']);
        $probe = new QuartoProbe(
            quartoRunner([$first.'/python3' => ['stdout' => "/usr/bin/python3.12\n"]]),
            $first.PATH_SEPARATOR.$second,
            [],
        );

        $resolution = $probe->resolve();

        expect($resolution['interpreter'])->toBe('/usr/bin/python3.12')
            ->and($resolution['source'])->toBe('PATH');
    });

    it('takes QUARTO_PYTHON over the python3 on PATH', function () {
        $bin = quartoBinDirectory(['python3']);
        $probe = new QuartoProbe(
            quartoRunner(['/opt/venv/bin/python' => ['stdout' => "/opt/venv/bin/python\n"]]),
            $bin,
            ['QUARTO_PYTHON' => '/opt/venv/bin/python'],
        );

        $resolution = $probe->resolve();

        expect($resolution['interpreter'])->toBe('/opt/venv/bin/python')
            ->and($resolution['source'])->toBe('QUARTO_PYTHON');
    });

    it('ignores an empty QUARTO_PYTHON', function () {
        $bin = quartoBinDirectory(['python3']);
        $probe = new QuartoProbe(
            quartoRunner([$bin.'/python3' => ['stdout' => "/usr/bin/python3.12\n"]]),
            $bin,
            ['QUARTO_PYTHON' => '  '],
        );

        expect($probe->resolve()['source'])->toBe('PATH');
    });

    it('resolves the candidate to its own sys.executable', function () {
        // Quarto reports the real interpreter rather than the symlink or
        // wrapper that led to it, so the install report names the same file
        // that `quarto check jupyter` does.
        $bin = quartoBinDirectory(['python3']);
        $calls = [];
        $probe = new QuartoProbe(
            quartoRunner(
                [$bin.'/python3' => ['stdout' => "/opt/homebrew/opt/python@3.14/bin/python3.14\n"]],
                $calls,
            ),
            $bin,
            [],
        );

        expect($probe->resolve()['interpreter'])
            ->toBe('/opt/homebrew/opt/python@3.14/bin/python3.14')
            ->and($calls[0])->toBe([$bin.'/python3', '-c', 'import sys; print(sys.executable)']);
    });

    it('has nothing to resolve when PATH holds no python3', function () {
        $bin = quartoBinDirectory(['quarto', 'python3.12']);

        expect((new QuartoProbe(quartoRunner([]), $bin, []))->resolve())->toBeNull();
    });

    it('does not fall back to python', function () {
        // Measured on Quarto 1.10.18: with a working python on PATH and no
        // python3 anywhere on it, `quarto check jupyter` reports that it is
        // unable to locate an installed version of Python 3. There is no
        // fallback to replicate.
        $bin = quartoBinDirectory(['quarto', 'python']);

        expect((new QuartoProbe(quartoRunner([]), $bin, []))->resolve())->toBeNull();
    });
});

describe('a candidate that cannot be asked anything', function () {
    it('reports a QUARTO_PYTHON that is not there', function () {
        $bin = quartoBinDirectory(['python3']);
        $probe = new QuartoProbe(quartoRunner([]), $bin, ['QUARTO_PYTHON' => '/gone/bin/python']);

        $resolution = $probe->resolve();

        expect($resolution['interpreter'])->toBe('/gone/bin/python')
            ->and($resolution['source'])->toBe('QUARTO_PYTHON')
            ->and($resolution['problem'])->toBe('/gone/bin/python is not an executable file')
            ->and($resolution['jupyterClient'])->toBeNull();
    });

    it('reports an exit code when the interpreter said nothing', function () {
        $bin = quartoBinDirectory(['python3']);
        $probe = new QuartoProbe(
            quartoRunner([$bin.'/python3' => ['code' => 2]]),
            $bin,
            [],
        );

        expect($probe->resolve()['problem'])->toBe('it exited with code 2');
    });

    it('reports an interpreter that ran and printed no path', function () {
        $bin = quartoBinDirectory(['python3']);
        $probe = new QuartoProbe(
            quartoRunner([$bin.'/python3' => ['stdout' => "\n"]]),
            $bin,
            [],
        );

        $resolution = $probe->resolve();

        expect($resolution['problem'])->toBe('it ran and did not report its own path')
            ->and($resolution['interpreter'])->toBe($bin.'/python3');
    });
});

describe('the jupyter_client check', function () {
    it('reports the version when it imports', function () {
        $bin = quartoBinDirectory(['python3']);
        $probe = new QuartoProbe(
            quartoRunner([
                $bin.'/python3 -c import sys' => ['stdout' => "/usr/bin/python3.12\n"],
                'jupyter_client' => ['stdout' => "8.6.3\n"],
            ]),
            $bin,
            [],
        );

        expect($probe->resolve()['jupyterClient'])->toBe('8.6.3');
    });

    it('reports none when it does not import', function () {
        $bin = quartoBinDirectory(['python3']);
        $probe = new QuartoProbe(
            quartoRunner([
                $bin.'/python3 -c import sys' => ['stdout' => "/usr/bin/python3.12\n"],
                'jupyter_client' => ['code' => 1, 'stderr' => "No module named 'jupyter_client'\n"],
            ]),
            $bin,
            [],
        );

        expect($probe->resolve()['jupyterClient'])->toBeNull();
    });

    it('asks the resolved interpreter, and asks only about jupyter_client', function () {
        // ipykernel is the other interpreter's business. Quarto needs the
        // client that starts a kernel, and nothing here checks for more.
        $bin = quartoBinDirectory(['python3']);
        $calls = [];
        $probe = new QuartoProbe(
            quartoRunner([
                $bin.'/python3 -c import sys' => ['stdout' => "/usr/bin/python3.12\n"],
                'jupyter_client' => ['stdout' => "8.6.3\n"],
            ], $calls),
            $bin,
            [],
        );

        $probe->resolve();

        expect($calls[1])->toBe([
            '/usr/bin/python3.12',
            '-c',
            'import jupyter_client; print(jupyter_client.__version__)',
        ])->and($calls)->toHaveCount(2);
    });

    it('can be asked of any interpreter', function () {
        // The caller asks it of the recorded interpreter too, to decide
        // whether putting that one on PATH is worth suggesting.
        $probe = new QuartoProbe(
            quartoRunner(['/opt/venv/bin/python' => ['stdout' => "8.6.3\n"]]),
            '',
            [],
        );

        expect($probe->jupyterClientVersion('/opt/venv/bin/python'))->toBe('8.6.3')
            ->and($probe->jupyterClientVersion('/other/bin/python'))->toBeNull();
    });
});
