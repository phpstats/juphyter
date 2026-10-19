<?php

declare(strict_types=1);

use Juphyter\Install\PythonProbe;

/**
 * A runner that answers from a table of canned results and records the calls.
 *
 * The key is a substring of the code the probe asks the interpreter to run, so
 * a test can answer the `sys.executable` question and the `ipykernel` question
 * differently. An unmatched call comes back as a failure, which is what a
 * missing module or a broken interpreter looks like.
 *
 * @param  array<string, array{code?: int, stdout?: string, stderr?: string}>  $answers
 * @param  list<list<string>>  $calls  Filled with every argument list the probe ran.
 */
function probeRunner(array $answers, array &$calls = []): Closure
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

        return ['code' => 1, 'stdout' => '', 'stderr' => 'No module named it'];
    };
}

/**
 * A directory of fake executables, to stand in for a PATH entry.
 *
 * @param  array<string, string>  $scripts  File name to contents.
 */
function probeBinDirectory(array $scripts): string
{
    $directory = sys_get_temp_dir().'/juphyter-bin-'.bin2hex(random_bytes(6));
    mkdir($directory, 0o777, true);

    foreach ($scripts as $name => $contents) {
        file_put_contents($directory.'/'.$name, $contents);
        chmod($directory.'/'.$name, 0o755);
    }

    return $directory;
}

/** Delete a directory tree. */
function probeRemoveTree(string $path): void
{
    if (is_dir($path) && ! is_link($path)) {
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                probeRemoveTree($path.'/'.$entry);
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
    foreach (glob(sys_get_temp_dir().'/juphyter-bin-*') ?: [] as $directory) {
        probeRemoveTree($directory);
    }
});

describe('choosing an interpreter', function () {
    it('asks the interpreter --python names for its own path', function () {
        $calls = [];
        $probe = new PythonProbe(
            probeRunner(['sys.executable' => ['stdout' => "/opt/venv/bin/python3.12\n"]], $calls),
            '',
        );

        expect($probe->interpreter('/opt/venv/bin/python'))->toBe('/opt/venv/bin/python3.12')
            ->and($calls[0])->toBe([
                '/opt/venv/bin/python',
                '-c',
                'import sys; print(sys.executable)',
            ]);
    });

    it('reads the shebang of the jupyter on PATH', function () {
        // The interpreter is named python3.12, which the python3 and python
        // fallback would not find, so only the shebang can have chosen it.
        $bin = probeBinDirectory([
            'python3.12' => "#!/bin/sh\n",
        ]);
        file_put_contents($bin.'/jupyter', "#!".$bin."/python3.12\nprint('jupyter')\n");
        chmod($bin.'/jupyter', 0o755);

        $calls = [];
        $probe = new PythonProbe(
            probeRunner(['sys.executable' => ['stdout' => $bin."/python3.12\n"]], $calls),
            $bin,
        );

        expect($probe->interpreter())->toBe($bin.'/python3.12')
            ->and($calls[0][0])->toBe($bin.'/python3.12');
    });

    it('follows a /usr/bin/env shebang back to PATH', function () {
        $bin = probeBinDirectory([
            'jupyter' => "#!/usr/bin/env python3\n",
            'python3' => "#!/bin/sh\n",
        ]);
        $calls = [];
        $probe = new PythonProbe(
            probeRunner(['sys.executable' => ['stdout' => $bin."/python3\n"]], $calls),
            $bin,
        );

        expect($probe->interpreter())->toBe($bin.'/python3')
            ->and($calls[0][0])->toBe($bin.'/python3');
    });

    it('skips env options and assignments in a shebang', function () {
        $bin = probeBinDirectory([
            'jupyter' => "#!/usr/bin/env -S PYTHONUNBUFFERED=1 python3\n",
            'python3' => "#!/bin/sh\n",
        ]);
        $probe = new PythonProbe(
            probeRunner(['sys.executable' => ['stdout' => $bin."/python3\n"]]),
            $bin,
        );

        expect($probe->interpreter())->toBe($bin.'/python3');
    });

    it('reads a quoted shebang path, which pip writes when the path holds a space', function () {
        $bin = probeBinDirectory([]);
        mkdir($bin.'/py 3.12/bin', 0o777, true);
        file_put_contents($bin.'/py 3.12/bin/python', "#!/bin/sh\n");
        chmod($bin.'/py 3.12/bin/python', 0o755);

        $interpreter = $bin.'/py 3.12/bin/python';
        file_put_contents($bin.'/jupyter', '#!"'.$interpreter.'"'."\n");
        chmod($bin.'/jupyter', 0o755);

        $calls = [];
        $probe = new PythonProbe(
            probeRunner(['sys.executable' => ['stdout' => $interpreter."\n"]], $calls),
            $bin,
        );

        expect($probe->interpreter())->toBe($interpreter)
            ->and($calls[0][0])->toBe($interpreter);
    });

    it('falls back to python3 when the shebang names something else', function () {
        $bin = probeBinDirectory([
            'jupyter' => "#!/bin/sh\nexec python -m jupyter\n",
            'python3' => "#!/bin/sh\n",
        ]);
        $probe = new PythonProbe(
            probeRunner(['sys.executable' => ['stdout' => $bin."/python3\n"]]),
            $bin,
        );

        expect($probe->interpreter())->toBe($bin.'/python3');
    });

    it('falls back to python3 when the shebang points at a missing interpreter', function () {
        $bin = probeBinDirectory([
            'jupyter' => "#!/gone/bin/python\n",
            'python3' => "#!/bin/sh\n",
        ]);
        $probe = new PythonProbe(
            probeRunner(['sys.executable' => ['stdout' => $bin."/python3\n"]]),
            $bin,
        );

        expect($probe->interpreter())->toBe($bin.'/python3');
    });

    it('falls back to python when there is no python3', function () {
        $bin = probeBinDirectory(['python' => "#!/bin/sh\n"]);
        $probe = new PythonProbe(
            probeRunner(['sys.executable' => ['stdout' => $bin."/python\n"]]),
            $bin,
        );

        expect($probe->interpreter())->toBe($bin.'/python');
    });

    it('skips a jupyter that is not executable', function () {
        $bin = probeBinDirectory(['python3' => "#!/bin/sh\n"]);
        file_put_contents($bin.'/jupyter', "#!/opt/venv/bin/python\n");
        chmod($bin.'/jupyter', 0o644);

        $probe = new PythonProbe(
            probeRunner(['sys.executable' => ['stdout' => $bin."/python3\n"]]),
            $bin,
        );

        expect($probe->interpreter())->toBe($bin.'/python3');
    });

    it('says what is missing when PATH holds no jupyter and no python', function () {
        $probe = new PythonProbe(probeRunner([]), probeBinDirectory([]));

        expect(fn () => $probe->interpreter())
            ->toThrow(RuntimeException::class, 'no jupyter and no python3 on PATH');
    });

    it('reports an interpreter that does not run, with its own message', function () {
        $probe = new PythonProbe(
            probeRunner(['sys.executable' => ['code' => 127, 'stderr' => "no such file\n"]]),
            '',
        );

        expect(fn () => $probe->interpreter('/gone/python'))
            ->toThrow(RuntimeException::class, 'is not a working Python interpreter');

        expect(fn () => $probe->interpreter('/gone/python'))
            ->toThrow(RuntimeException::class, 'no such file');
    });

    it('reports an interpreter that exits cleanly and says nothing', function () {
        $probe = new PythonProbe(probeRunner(['sys.executable' => ['stdout' => "  \n"]]), '');

        expect(fn () => $probe->interpreter('/odd/python'))
            ->toThrow(RuntimeException::class, 'did not report its own path');
    });
});

describe('requiring ipykernel', function () {
    it('returns the version it finds', function () {
        $probe = new PythonProbe(probeRunner(['ipykernel' => ['stdout' => "7.4.0\n"]]));

        expect($probe->ipykernelVersion('/opt/venv/bin/python'))->toBe('7.4.0')
            ->and($probe->requireIpykernel('/opt/venv/bin/python'))->toBe('7.4.0');
    });

    it('asks the interpreter to import it', function () {
        $calls = [];
        $probe = new PythonProbe(probeRunner(['ipykernel' => ['stdout' => "7.4.0\n"]], $calls));
        $probe->requireIpykernel('/opt/venv/bin/python');

        expect($calls[0])->toBe([
            '/opt/venv/bin/python',
            '-c',
            'import ipykernel; print(ipykernel.__version__)',
        ]);
    });

    it('reports no version when the import fails', function () {
        $probe = new PythonProbe(probeRunner([]));

        expect($probe->ipykernelVersion('/opt/venv/bin/python'))->toBeNull();
    });

    it('prints the exact install command when ipykernel is missing', function () {
        $probe = new PythonProbe(probeRunner([]));

        try {
            $probe->requireIpykernel('/opt/venv/bin/python');
            $this->fail('requireIpykernel accepted an interpreter without ipykernel.');
        } catch (RuntimeException $e) {
            expect($e->getMessage())
                ->toContain('ipykernel is not installed in /opt/venv/bin/python')
                ->toContain('/opt/venv/bin/python -m pip install "ipykernel>=7.0"');
        }
    });

    it('refuses an ipykernel below the floor the wrapper needs', function () {
        $probe = new PythonProbe(probeRunner(['ipykernel' => ['stdout' => "6.29.5\n"]]));

        try {
            $probe->requireIpykernel('/opt/venv/bin/python');
            $this->fail('requireIpykernel accepted ipykernel 6.29.5.');
        } catch (RuntimeException $e) {
            expect($e->getMessage())
                ->toContain('has ipykernel 6.29.5')
                ->toContain('needs 7.0 or newer')
                ->toContain('-m pip install "ipykernel>=7.0"');
        }
    });

    it('accepts the floor itself and anything above it', function () {
        foreach (['7.0', '7.0.0', '7.4.0', '8.0.0a1'] as $version) {
            $probe = new PythonProbe(probeRunner(['ipykernel' => ['stdout' => $version]]));

            expect($probe->requireIpykernel('/python'))->toBe($version);
        }
    });

    it('names the floor in one place', function () {
        expect(PythonProbe::IPYKERNEL_FLOOR)->toBe('7.0')
            ->and(PythonProbe::installCommand('/python'))
            ->toBe('/python -m pip install "ipykernel>=7.0"');
    });
});

describe('the default runner', function () {
    it('runs a process and returns its code and both streams', function () {
        $runner = PythonProbe::processRunner();
        $result = $runner([
            PHP_BINARY, '-r', 'fwrite(STDOUT, "out"); fwrite(STDERR, "err"); exit(3);',
        ]);

        expect($result)->toBe(['code' => 3, 'stdout' => 'out', 'stderr' => 'err']);
    });

    it('reports a path that is not there rather than throwing', function () {
        $runner = PythonProbe::processRunner();
        $result = $runner(['/definitely/not/here', '-c', 'print(1)']);

        expect($result['code'])->toBe(127)
            ->and($result['stderr'])->toContain('is not an executable file');
    });

    it('reports an empty command', function () {
        expect(PythonProbe::processRunner()([])['code'])->toBe(127);
    });

    it('passes arguments without a shell, so nothing needs quoting', function () {
        $runner = PythonProbe::processRunner();
        $result = $runner([PHP_BINARY, '-r', 'echo $argv[1];', 'a b; echo c']);

        expect($result['stdout'])->toBe('a b; echo c');
    });
});
