<?php

declare(strict_types=1);

namespace Juphyter\Console;

use Closure;
use Juphyter\Engine\Interrupt;
use Juphyter\Install\JupyterPaths;
use Juphyter\Install\KernelSpec;
use Juphyter\Install\PythonProbe;
use Juphyter\Install\QuartoProbe;
use RuntimeException;

/**
 * Checking that a kernel installed earlier can still run.
 *
 * `juphyter install` reports what it recorded at the moment it recorded it, and
 * everything it names can move afterwards. A `brew upgrade` replaces the PHP
 * binary a kernelspec points at, a deleted project takes its `vendor` directory
 * and the engine inside it, a removed virtual environment takes the interpreter,
 * and a new terminal can have a `PATH` that no longer reaches the Jupyter the
 * kernel was installed against. Each of those leaves a kernelspec that a front
 * end lists and cannot start, and none of them changes the kernelspec itself.
 *
 * This asks the questions a reader would otherwise have to ask by hand with
 * `jupyter kernelspec list`, `quarto check jupyter`, and a reading of the
 * install output, and says which front ends work right now.
 *
 * ## What it does not do
 *
 * It changes nothing. A check that repaired what it found would be a second
 * installer, and the repair for the most common finding is a `PATH` entry,
 * which a child process cannot set in the shell that started it.
 *
 * ## Injection
 *
 * The probes and the subprocess runner are constructor arguments, as
 * {@see InstallCommand} explains, so the tests drive every outcome with canned
 * results and read no real Jupyter directory.
 */
final class DoctorCommand extends Command
{
    /** @var list<string> */
    private const OPTIONS = ['prefix'];

    /** The oldest PHP this package runs on, as composer.json requires it. */
    private const PHP_FLOOR = '8.3';

    /** A finding's status, widest first so the column lines up. */
    private const OK = 'ok';

    private const WARN = 'warn';

    private const FAIL = 'fail';

    private const NONE = '--';

    /** Quarto's three states, which the verdict words differently. */
    private const QUARTO_READY = 'ready';

    private const QUARTO_BROKEN = 'broken';

    private const QUARTO_ABSENT = 'absent';

    /** @var resource */
    private $stdout;

    /** @var resource */
    private $stderr;

    /**
     * @param  Closure(list<string>): array{code: int, stdout: string, stderr: string}  $runner
     * @param  resource|null  $stdout
     * @param  resource|null  $stderr
     */
    public function __construct(
        private readonly JupyterPaths $paths,
        private readonly PythonProbe $probe,
        private readonly QuartoProbe $quarto,
        private readonly Closure $runner,
        private readonly string $packageRoot,
        $stdout = null,
        $stderr = null,
    ) {
        $this->stdout = $stdout ?? STDOUT;
        $this->stderr = $stderr ?? STDERR;
    }

    public static function description(): string
    {
        return 'Check that an installed PHP kernel can still run';
    }

    public static function make(): static
    {
        $runner = PythonProbe::processRunner();

        return new self(
            JupyterPaths::fromEnvironment(),
            new PythonProbe($runner),
            new QuartoProbe($runner),
            $runner,
            InstallCommand::packageRoot(),
        );
    }

    public function run(array $arguments): int
    {
        if (in_array('--help', $arguments, true) || in_array('-h', $arguments, true)) {
            fwrite($this->stdout, self::usage());

            return 0;
        }

        try {
            $options = Options::parse($arguments, self::OPTIONS, self::usage());
        } catch (RuntimeException $e) {
            fwrite($this->stderr, rtrim($e->getMessage(), "\n")."\n");

            return 1;
        }

        return $this->report($options['prefix'] ?? null);
    }

    /**
     * @param  string|null  $prefix  A `--prefix` to look under, as `install` takes.
     */
    private function report(?string $prefix): int
    {
        $lines = [];
        $failed = false;

        [$phpLines, $phpOk] = $this->checkPhp();
        [$pythonLines, $python] = $this->checkPython();
        [$quartoLines, $quartoState, $quartoFix] = $this->checkQuarto($python);
        [$kernelLines, $usableKernels, $kernelFailed] = $this->checkKernels($prefix);

        // The Python section answers whether a new kernel could be installed
        // here. Once a kernel exists it names its own interpreter and runs in
        // it, so a default python3 that cannot host one is worth reporting and
        // is not a fault of this machine.
        if ($usableKernels !== []) {
            $pythonLines = self::advisory($pythonLines);
        }

        $lines[] = ['This package', $this->checkPackage()];
        $lines[] = ['PHP', $phpLines];
        $lines[] = ['A Python for the kernel', $pythonLines];
        $lines[] = ['Quarto', $quartoLines];
        $lines[] = [$this->kernelHeading($prefix), $kernelLines];

        // A working kernel names an interpreter that has ipykernel, so it is the
        // one to put in front of Quarto when PATH no longer reaches any.
        $fixable = $python ?? (reset($usableKernels) ?: null);

        if ($quartoFix === '' && $quartoState === self::QUARTO_BROKEN) {
            $quartoFix = $this->pathFix($fixable);
        }

        $out = "juphyter doctor\n";

        foreach ($lines as [$heading, $findings]) {
            $out .= "\n".$heading."\n";

            foreach ($findings as [$status, $text]) {
                $out .= sprintf("  %-4s  %s\n", $status, $text);

                if ($status === self::FAIL) {
                    $failed = true;
                }
            }
        }

        $failed = $failed || $kernelFailed || ! $phpOk;

        $out .= "\n".$this->verdict(
            array_keys($usableKernels),
            $quartoState,
            $quartoFix,
            $fixable,
        );

        fwrite($this->stdout, $out);

        return $failed ? 1 : 0;
    }

    /**
     * The wrapper this package ships, which the kernelspec's `argv` imports.
     *
     * @return list<array{string, string}>
     */
    private function checkPackage(): array
    {
        $module = $this->packageRoot.'/kernel/juphyter_kernel';

        return is_file($module.'/kernel.py')
            ? [[self::OK, 'the Python wrapper is at '.$module]]
            : [[self::FAIL, 'the Python wrapper is missing from '.$module]];
    }

    /**
     * @return array{list<array{string, string}>, bool}
     */
    private function checkPhp(): array
    {
        $findings = [];
        $ok = true;

        if (version_compare(PHP_VERSION, self::PHP_FLOOR, '<')) {
            $findings[] = [self::FAIL, sprintf(
                'this PHP is %s and juphyter needs %s or newer',
                PHP_VERSION,
                self::PHP_FLOOR,
            )];
            $ok = false;
        } else {
            $findings[] = [self::OK, sprintf('PHP %s at %s', PHP_VERSION, PHP_BINARY)];
        }

        $findings[] = Interrupt::available()
            ? [self::OK, 'pcntl is there, so the stop button interrupts a cell']
            : [self::WARN, 'pcntl is missing, so the stop button will not interrupt a cell'];

        return [$findings, $ok];
    }

    /**
     * The interpreter the installer would pick, and what is wrong with it.
     *
     * @return array{list<array{string, string}>, string|null} The findings, and
     *                                                         the interpreter when it is usable.
     */
    private function checkPython(): array
    {
        try {
            $interpreter = $this->probe->interpreter(null);
        } catch (RuntimeException $e) {
            return [[[self::FAIL, self::firstLine($e->getMessage())]], null];
        }

        $version = $this->probe->ipykernelVersion($interpreter);

        if ($version === null) {
            return [[
                [self::FAIL, sprintf('%s has no ipykernel', $interpreter)],
                [self::NONE, 'juphyter install builds an environment for it, or: '
                    .PythonProbe::installCommand($interpreter)],
            ], null];
        }

        if (version_compare($version, PythonProbe::IPYKERNEL_FLOOR, '<')) {
            return [[
                [self::FAIL, sprintf(
                    '%s has ipykernel %s and the kernel needs %s or newer',
                    $interpreter,
                    $version,
                    PythonProbe::IPYKERNEL_FLOOR,
                )],
                [self::NONE, 'juphyter install builds an environment for it, or: '
                    .PythonProbe::installCommand($interpreter)],
            ], null];
        }

        return [[[self::OK, sprintf('%s has ipykernel %s', $interpreter, $version)]], $interpreter];
    }

    /**
     * @param  string|null  $python  The interpreter the installer would record.
     * @return array{list<array{string, string}>, string, string} The findings, one
     *         of the QUARTO_* states, and the `PATH` entry that fixes a broken one.
     */
    private function checkQuarto(?string $python): array
    {
        if (! $this->quarto->installed()) {
            return [
                [[self::NONE, 'quarto is not on PATH, so there is nothing to check']],
                self::QUARTO_ABSENT,
                '',
            ];
        }

        $resolution = $this->quarto->resolve();

        if ($resolution === null) {
            return [
                [[self::FAIL, 'quarto is installed and has no python3 on PATH to run']],
                self::QUARTO_BROKEN,
                $this->pathFix($python),
            ];
        }

        $interpreter = $resolution['interpreter'];

        if ($resolution['problem'] !== null) {
            return [
                [[self::FAIL, sprintf(
                    'quarto will run %s, which did not run: %s',
                    $interpreter,
                    $resolution['problem'],
                )]],
                self::QUARTO_BROKEN,
                $this->pathFix($python),
            ];
        }

        if ($resolution['jupyterClient'] === null) {
            return [
                [[self::FAIL, sprintf(
                    'quarto will run %s, which has no Jupyter in it',
                    $interpreter,
                )]],
                self::QUARTO_BROKEN,
                $this->pathFix($python),
            ];
        }

        return [
            [[self::OK, sprintf(
                'quarto will run %s, with jupyter_client %s',
                $interpreter,
                $resolution['jupyterClient'],
            )]],
            self::QUARTO_READY,
            '',
        ];
    }

    /**
     * Every juphyter kernelspec, and whether what it names is still there.
     *
     * @return array{list<array{string, string}>, array<string, string>, bool} The
     *         findings, each usable kernel's own interpreter keyed by kernel name,
     *         and whether any kernel was broken.
     */
    private function checkKernels(?string $prefix): array
    {
        $directory = $this->paths->kernelsDirectory($prefix);
        $entries = is_dir($directory) ? (scandir($directory) ?: []) : [];
        $findings = [];
        $usable = [];
        $failed = false;

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $file = $directory.'/'.$entry.'/kernel.json';

            if (! is_file($file)) {
                continue;
            }

            $decoded = json_decode((string) @file_get_contents($file), true);

            // Somebody else's kernel of the same name is not this command's
            // business, the way `uninstall` will not remove one.
            if (! KernelSpec::isJuphyter($decoded)) {
                continue;
            }

            $problem = $this->kernelProblem($decoded);

            if ($problem === null) {
                $findings[] = [self::OK, sprintf('%s is complete', $entry)];

                /** @var list<string> $argv */
                $argv = is_array($decoded['argv'] ?? null) ? $decoded['argv'] : [];
                $usable[$entry] = (string) ($argv[0] ?? '');

                continue;
            }

            $findings[] = [self::FAIL, sprintf('%s: %s', $entry, $problem)];
            $failed = true;
        }

        if ($findings === []) {
            $findings[] = [self::NONE, 'no juphyter kernel is installed here'];
        }

        return [$findings, $usable, $failed];
    }

    /**
     * The first thing wrong with a kernelspec, or null when it is whole.
     *
     * @param  array<string, mixed>  $spec
     */
    private function kernelProblem(array $spec): ?string
    {
        /** @var list<string> $argv */
        $argv = is_array($spec['argv'] ?? null) ? $spec['argv'] : [];
        /** @var array<string, string> $env */
        $env = is_array($spec['env'] ?? null) ? $spec['env'] : [];

        $python = $argv[0] ?? '';

        if ($python === '' || $this->probe->ipykernelVersion($python) === null) {
            return sprintf('its Python %s is gone or has no ipykernel', $python);
        }

        $php = $env['JUPHYTER_PHP'] ?? '';

        if ($php === '' || ! $this->runs([$php, '-v'])) {
            return sprintf('its PHP %s does not run', $php);
        }

        $engine = $env['JUPHYTER_ENGINE'] ?? '';

        if ($engine === '' || ! is_file($engine)) {
            return sprintf('its engine %s is gone', $engine);
        }

        $module = $env['PYTHONPATH'] ?? '';

        if ($module === '' || ! is_dir($module)) {
            return sprintf('its Python wrapper %s is gone', $module);
        }

        return null;
    }

    /** Whether a command runs at all. */
    private function runs(array $argv): bool
    {
        return ($this->runner)($argv)['code'] === 0;
    }

    /** The `PATH` entry that would put a working Jupyter in front of Quarto. */
    private function pathFix(?string $python): string
    {
        if ($python === null) {
            return '';
        }

        return sprintf('export PATH="%s:$PATH"', dirname($python));
    }

    private function kernelHeading(?string $prefix): string
    {
        return 'Kernels in '.$this->paths->kernelsDirectory($prefix);
    }

    /**
     * What works, and the one command that fixes what does not.
     *
     * @param  list<string>  $kernels
     */
    private function verdict(array $kernels, string $quartoState, string $fix, ?string $python): string
    {
        if ($kernels === []) {
            return "JupyterLab: no usable kernel.\nQuarto: no usable kernel.\n"
                .($python === null
                    ? "\nInstall a Python that can host the kernel, then run juphyter install.\n"
                    : "\nRun juphyter install to write one.\n");
        }

        $out = sprintf("JupyterLab: ready, with %s.\n", implode(', ', $kernels));
        $out .= match ($quartoState) {
            self::QUARTO_READY => "Quarto: ready.\n",
            self::QUARTO_ABSENT => "Quarto: not installed.\n",
            default => "Quarto: not ready.\n",
        };

        if ($quartoState === self::QUARTO_BROKEN && $fix !== '') {
            // Editor selection first: it is scoped to one workspace and is
            // the step a reader who already runs Python notebooks has taken.
            // The export is offered for one shell and is deliberately not
            // persisted, because a startup file would change python3 for every
            // Quarto project on the machine.
            $out .= "\nQuarto runs an interpreter of its own choosing, and that one needs Jupyter\n"
                ."in it. Point Quarto at one that has it.\n\n"
                ."In Positron, VS Code, or Cursor, select it for the workspace with \"Python:\n"
                ."Select Interpreter\". The Quarto extension hands the workspace interpreter to\n"
                ."Quarto, so no other project is affected.\n\n"
                ."For a single terminal session:\n\n"
                ."    ".$fix."\n";
        }

        return $out;
    }

    /**
     * Downgrade the failures in a section to warnings.
     *
     * @param  list<array{string, string}>  $lines
     * @return list<array{string, string}>
     */
    private static function advisory(array $lines): array
    {
        return array_map(
            static fn (array $line): array => [
                $line[0] === self::FAIL ? self::WARN : $line[0],
                $line[1],
            ],
            $lines,
        );
    }

    /** The first line of a multi-line probe message. */
    private static function firstLine(string $message): string
    {
        return trim(explode("\n", trim($message))[0]);
    }

    public static function usage(): string
    {
        return <<<'TEXT'
            Usage: juphyter doctor [options]

            Checks that an installed PHP kernel can still run, and says which front ends
            it works in. Changes nothing.

            Options:
              --prefix PATH         Look under PATH/share/jupyter instead of the per-user
                                    Jupyter data directory.
              -h, --help            Print this text.

            The exit code is 1 when something is broken, so a script can ask.

            TEXT;
    }
}
