<?php

declare(strict_types=1);

namespace Juphyter\Console;

use Closure;
use Juphyter\Engine\Interrupt;
use Juphyter\Install\JupyterPaths;
use Juphyter\Install\KernelSpec;
use Juphyter\Install\PythonProbe;
use Juphyter\Install\QuartoProbe;
use Juphyter\Install\VenvBuilder;
use Phar;
use RuntimeException;

/**
 * `juphyter install`: register the PHP kernel with Jupyter.
 *
 * The command writes one file, `<kernels>/<name>/kernel.json`, and prints where
 * it went. Everything else it does is decide what belongs in that file:
 *
 * - The Python interpreter comes from {@see PythonProbe}, which starts from the
 *   `jupyter` on `PATH`. `ipykernel` has to import there, because the wrapper
 *   kernel runs inside it. When it does not, the command prints the `pip`
 *   command that fixes it and writes nothing.
 * - The PHP binary defaults to the one running this command and is otherwise
 *   whatever `--php` names, validated by running it. Its version also supplies
 *   the default display name, so `--name php85 --php .../php85` produces a
 *   kernel called `PHP 8.5` without a second flag.
 * - The kernels directory comes from {@see JupyterPaths}, which honors
 *   `JUPYTER_DATA_DIR` and `--prefix`.
 * - The engine path is this package's `bin/juphyter`, or the PHAR itself when
 *   the command is running from one.
 * - {@see QuartoProbe} resolves the interpreter Quarto will run, which is a
 *   different question from the one above and is reported beside it. That
 *   check is advisory: see {@see quarto()} for what it does and does not
 *   change.
 *
 * ## Running from a PHAR
 *
 * Python cannot read a `phar://` path, so a PHAR install cannot point
 * `PYTHONPATH` inside the archive. The command copies the `kernel/` directory
 * out of the PHAR into the kernelspec's own directory and points `PYTHONPATH`
 * there. `uninstall` removes the directory, which takes the copy with it.
 *
 * A package with no `kernel/juphyter_kernel` in it is a damaged install: the
 * kernelspec it would write names `python -m juphyter_kernel`, and nothing
 * would start. The command refuses before it writes anything and says where
 * the module should have been.
 *
 * ## Construction
 *
 * The collaborators and both output streams are constructor arguments, so the
 * tests drive the command with fakes and a temporary prefix and never touch the
 * real Jupyter data directory. {@see make()} is the production wiring.
 */
final class InstallCommand extends Command
{
    /** The kernel name used when `--name` is absent. */
    public const DEFAULT_NAME = 'php';

    /** @var list<string> */
    private const OPTIONS = [
        'name', 'display-name', 'php', 'python', 'prefix', 'autoload', 'create-venv',
        'path-file',
    ];

    /** @var resource */
    private $stdout;

    /** @var resource */
    private $stderr;

    /** @var Closure(string): bool */
    private Closure $confirm;

    /**
     * @param  JupyterPaths  $paths  Where kernelspecs live.
     * @param  PythonProbe  $probe  Which interpreter to name, and whether it can host the kernel.
     * @param  QuartoProbe  $quarto  Which interpreter Quarto will run, for the advisory report.
     * @param  VenvBuilder  $venv  How a virtual environment is built when the machine has no usable Python.
     * @param  Closure(list<string>): array{code: int, stdout: string, stderr: string}  $runner  How to run a subprocess, used to validate `--php`.
     * @param  string  $packageRoot  This package's root, which is a `phar://` path inside a PHAR.
     * @param  resource|null  $stdout
     * @param  resource|null  $stderr
     * @param  (Closure(string): bool)|null  $confirm  How a yes-or-no question is asked; defaults to {@see terminalConfirm()}.
     */
    public function __construct(
        private readonly JupyterPaths $paths,
        private readonly PythonProbe $probe,
        private readonly QuartoProbe $quarto,
        private readonly VenvBuilder $venv,
        private readonly Closure $runner,
        private readonly string $packageRoot,
        $stdout = null,
        $stderr = null,
        ?Closure $confirm = null,
    ) {
        $this->stdout = $stdout ?? STDOUT;
        $this->stderr = $stderr ?? STDERR;
        $this->confirm = $confirm ?? self::terminalConfirm();
    }

    public static function description(): string
    {
        return 'Register the PHP kernel with Jupyter';
    }

    public static function make(): static
    {
        $runner = PythonProbe::processRunner();

        return new self(
            JupyterPaths::fromEnvironment(),
            new PythonProbe($runner),
            new QuartoProbe($runner),
            new VenvBuilder($runner),
            $runner,
            self::packageRoot(),
        );
    }

    /**
     * This package's root directory.
     *
     * Inside a PHAR, `__DIR__` is `phar:///path/to/juphyter.phar/src/Console`,
     * so the same two levels up give the archive root and the `phar://` prefix
     * marks the case for {@see enginePath()} and {@see kernelModulePath()}.
     */
    public static function packageRoot(): string
    {
        return dirname(__DIR__, 2);
    }

    public function run(array $arguments): int
    {
        if (in_array('--help', $arguments, true) || in_array('-h', $arguments, true)) {
            fwrite($this->stdout, self::usage());

            return 0;
        }

        try {
            return $this->install(self::parseOptions($arguments));
        } catch (RuntimeException $e) {
            fwrite($this->stderr, rtrim($e->getMessage(), "\n")."\n");

            return 1;
        }
    }

    /**
     * @param  array<string, string>  $options
     * @throws RuntimeException On any bad input, before anything is written.
     */
    private function install(array $options): int
    {
        $name = KernelSpec::normalizeName($options['name'] ?? self::DEFAULT_NAME);
        $prefix = $options['prefix'] ?? null;

        // First, because it is a property of this copy of juphyter rather than
        // of the machine, and there is no point asking Python anything when the
        // kernel it would be asked to host is not here.
        $this->requireKernelModule();

        [$php, $phpVersion, $canSignal] = $this->php($options['php'] ?? null);

        [$python, $ipykernel] = $this->python($options);

        $directory = $this->paths->kernelDirectory($name, $prefix);
        $file = $directory.'/kernel.json';

        $this->refuseForeignSpec($file);

        $existed = is_file($file);
        $module = $this->kernelModulePath($directory);

        $spec = new KernelSpec(
            name: $name,
            displayName: ($options['display-name'] ?? '') !== ''
                ? $options['display-name']
                : 'PHP '.self::shortVersion($phpVersion),
            python: $python,
            php: $php,
            engine: $this->enginePath(),
            pythonPath: $module,
            autoload: $options['autoload'] ?? null,
        );

        if (! is_dir($directory) && ! @mkdir($directory, 0o755, true) && ! is_dir($directory)) {
            throw new RuntimeException(sprintf('juphyter: could not create %s.', $directory));
        }

        $this->copyKernelModule($module);

        if (@file_put_contents($file, $spec->toJson()) === false) {
            throw new RuntimeException(sprintf('juphyter: could not write %s.', $file));
        }

        // A warning rather than a refusal: everything else about the kernel
        // works, and a notebook nobody interrupts is still worth having.
        if (! $canSignal) {
            fwrite($this->stderr, sprintf(
                "juphyter: %s cannot handle signals, so the front end's stop button will not\n"
                ."  interrupt a running cell with this kernel. The kernelspec asks for\n"
                ."  interrupt_mode \"signal\", which needs pcntl_async_signals(), pcntl_signal()\n"
                ."  and SIGINT. Check that the pcntl extension is enabled for that binary and\n"
                ."  that its functions are not in disable_functions.\n",
                $php,
            ));
        }

        // Last, and advisory: the kernelspec above is written whatever Quarto
        // can or cannot do with it.
        [$quartoLine, $quartoWarning] = $this->quarto($python);

        if ($quartoWarning !== '') {
            fwrite($this->stderr, $quartoWarning);
        }

        fwrite($this->stdout, sprintf(
            "%s the juphyter kernel \"%s\" for PHP %s.\n"
            ."  kernel.json  %s\n"
            ."  python       %s (ipykernel %s)\n"
            ."  php          %s\n"
            ."  engine       %s\n",
            $existed ? 'Replaced' : 'Installed',
            $spec->name(),
            $phpVersion,
            $file,
            $python,
            $ipykernel,
            $php,
            $this->enginePath(),
        ).$quartoLine.$this->pathFile($options['path-file'] ?? null, $python));

        return 0;
    }

    /**
     * The PHP binary and its version.
     *
     * With no `--php`, that is the interpreter running this command, which it
     * can answer from its own constants. With `--php`, the binary is run: that
     * validates it, reports its version for the default display name, and
     * answers whether it can be interrupted.
     *
     * ## Which path the kernelspec names
     *
     * An absolute path to an executable is recorded exactly as it was given.
     * `PHP_BINARY` is the path with every symlink resolved, so taking it would
     * turn `--php /opt/homebrew/bin/php` into
     * `/opt/homebrew/Cellar/php/8.5.10/bin/php`, which Homebrew deletes on the
     * next `brew upgrade php` and the kernel then stops starting. The same
     * applies to a wrapper script: run it and `PHP_BINARY` names whatever PHP
     * it chose, which is not the thing to record.
     *
     * Anything else is resolved through `PHP_BINARY`, because a kernelspec
     * cannot name `php8.4` or `./php`. Jupyter starts the kernel from the
     * notebook's directory with whatever `PATH` the front end had, and neither
     * is the `PATH` or the directory this command was run in.
     *
     * @return array{string, string, bool} The binary, its version, and whether
     *                                      it can handle signals.
     * @throws RuntimeException When `--php` does not run.
     */
    private function php(?string $php): array
    {
        if ($php === null || $php === '') {
            return [PHP_BINARY, PHP_VERSION, Interrupt::available()];
        }

        // The third line asks the target PHP the same question
        // Interrupt::available() asks at run time. It has to be asked of that
        // binary and not of this one: the kernelspec names whatever --php gave
        // it, and `extension_loaded('pcntl')` is no answer either, because a
        // hardened ini file can leave the extension loaded with its functions
        // in disable_functions, where the extension is present and the stop
        // button is still dead.
        $result = ($this->runner)([$php, '-r', 'echo PHP_BINARY, PHP_EOL, PHP_VERSION, PHP_EOL, '
            .'(int) (function_exists("pcntl_async_signals") && function_exists("pcntl_signal") '
            .'&& defined("SIGINT"));']);

        if ($result['code'] !== 0) {
            throw new RuntimeException(sprintf(
                "juphyter: --php %s is not a working PHP binary.\n%s",
                $php,
                trim($result['stderr']) !== ''
                    ? trim($result['stderr'])
                    : 'It exited with code '.$result['code'].'.',
            ));
        }

        $lines = preg_split('/\R/', trim($result['stdout'])) ?: [];
        $binary = trim($lines[0] ?? '');
        $version = trim($lines[1] ?? '');

        if ($version === '') {
            throw new RuntimeException(sprintf(
                'juphyter: --php %s ran but did not report its version.',
                $php,
            ));
        }

        // An older juphyter's probe printed two lines. Absent a third, say
        // nothing rather than warn about a binary that never answered.
        $signals = trim($lines[2] ?? '1') === '1';

        if (self::isAbsoluteExecutable($php)) {
            return [$php, $version, $signals];
        }

        return [$binary !== '' ? $binary : $php, $version, $signals];
    }

    /** Whether a `--php` value is already a path the kernelspec can name. */
    private static function isAbsoluteExecutable(string $php): bool
    {
        return str_starts_with($php, '/') && is_file($php) && is_executable($php);
    }

    /**
     * The interpreter to name in the kernelspec, and its `ipykernel` version.
     *
     * The probe fails when the machine has no interpreter that can host the
     * kernel. On a machine whose only Python is a system or Homebrew build that
     * is the ordinary case rather than a broken one, because those builds are
     * marked externally managed and refuse `pip install`. Rather than describe
     * a virtual environment, `--create-venv` builds one and the install
     * continues into it.
     *
     * An explicit `--python` is never second-guessed. The reader named an
     * interpreter, and building a different one would answer a question they did
     * not ask.
     *
     * @param  array<string, string>  $options
     * @return array{string, string} The interpreter and its `ipykernel` version.
     * @throws RuntimeException When there is no usable interpreter and none is to be built.
     */
    private function python(array $options): array
    {
        $explicit = $options['python'] ?? null;

        try {
            $interpreter = $this->probe->interpreter($explicit);

            return [$interpreter, $this->probe->requireIpykernel($interpreter)];
        } catch (RuntimeException $e) {
            $directory = $this->venvDirectory($options, $explicit, $e);

            fwrite($this->stderr, sprintf(
                "juphyter: building a virtual environment at %s and installing %s into\n"
                ."  it. This takes a minute.\n",
                $directory,
                implode(' ', VenvBuilder::PACKAGES),
            ));

            $interpreter = $this->venv->build($directory);

            return [$interpreter, $this->probe->requireIpykernel($interpreter)];
        }
    }

    /**
     * Where to build an environment, having found no interpreter that works.
     *
     * `--create-venv` answers it outright. Without it there is a question to
     * ask, and a reader who declines gets the probe's own message with the
     * option appended, so the way forward is in the failure itself.
     *
     * @param  array<string, string>  $options
     * @param  string|null  $explicit  A `--python` value, which suppresses the offer.
     * @param  RuntimeException  $failure  Why no interpreter was usable.
     * @throws RuntimeException When no environment is to be built.
     */
    private function venvDirectory(
        array $options,
        ?string $explicit,
        RuntimeException $failure,
    ): string {
        $named = trim($options['create-venv'] ?? '');

        if ($named !== '') {
            return $named;
        }

        if ($explicit !== null && $explicit !== '') {
            throw $failure;
        }

        $default = $this->venv->defaultDirectory();

        if ($default === null) {
            throw new RuntimeException(sprintf(
                "%s\n\nOr let juphyter build a virtual environment with %s in it:\n\n"
                ."    juphyter install --create-venv PATH\n\n"
                .'HOME is unset, so there is no default path to suggest.',
                rtrim($failure->getMessage(), "\n"),
                implode(' ', VenvBuilder::PACKAGES),
            ));
        }

        $question = sprintf(
            'Build a virtual environment with %s at %s and install the kernel into it?',
            implode(' ', VenvBuilder::PACKAGES),
            $default,
        );

        if (($this->confirm)($question)) {
            return $default;
        }

        throw new RuntimeException(sprintf(
            "%s\n\nOr let juphyter build a virtual environment with %s in it and install\n"
            ."the kernel into that:\n\n    juphyter install --create-venv %s",
            rtrim($failure->getMessage(), "\n"),
            implode(' ', VenvBuilder::PACKAGES),
            $default,
        ));
    }

    /**
     * A yes-or-no question put to the terminal.
     *
     * Answers no without asking when stdin is not a terminal, so a scripted or
     * CI install fails with the message the probe wrote instead of blocking on
     * a question nobody is there to answer.
     *
     * @return Closure(string): bool
     */
    private static function terminalConfirm(): Closure
    {
        return function (string $question): bool {
            if (! stream_isatty(STDIN)) {
                return false;
            }

            fwrite(STDERR, $question.' [y/N] ');
            $answer = fgets(STDIN);

            return $answer !== false
                && in_array(strtolower(trim($answer)), ['y', 'yes'], true);
        };
    }

    /**
     * The `quarto` line for the install report, and the warning that goes with it.
     *
     * Quarto runs an interpreter of its own choosing, and that one is the one
     * that needs Jupyter in it, so a kernelspec can be correct and complete and
     * still be unreachable from `quarto render`. {@see QuartoProbe} resolves
     * the interpreter and documents the order it replicates.
     *
     * Nothing here changes which interpreter the kernelspec names. The two are
     * allowed to differ, the recorded one is right for JupyterLab, and moving
     * it to Quarto's choice would rearrange the kernel of a JupyterLab user who
     * has no Quarto problem to solve. The exit code stays 0 and the kernelspec
     * is still written for the same reason: a kernel Quarto cannot reach is
     * still a working JupyterLab kernel.
     *
     * The whole check is skipped when Quarto is not installed, so a user
     * without it reads nothing about it.
     *
     * @param  string  $python  The interpreter the kernelspec names.
     * @return array{string, string} The report line and the warning, either of
     *                               which is empty when there is none.
     */
    private function quarto(string $python): array
    {
        if (! $this->quarto->installed()) {
            return ['', ''];
        }

        $resolution = $this->quarto->resolve();

        if ($resolution === null) {
            return [
                "  quarto       no python3 on PATH\n",
                $this->quartoWarning(
                    "Quarto is installed and has no python3 on PATH to run, so `quarto render`\n"
                    .'  will not start this kernel, or any other.',
                    $python,
                ),
            ];
        }

        $interpreter = $resolution['interpreter'];

        // Naming the variable matters when it is the thing that is wrong: a
        // QUARTO_PYTHON left over from another project explains an interpreter
        // the reader cannot otherwise account for.
        $where = $resolution['source'] === QuartoProbe::OVERRIDE
            ? sprintf('%s names %s', QuartoProbe::OVERRIDE, $interpreter)
            : sprintf('Quarto appears to use %s', $interpreter);

        if ($resolution['problem'] !== null) {
            return [
                sprintf("  quarto       %s (did not run)\n", $interpreter),
                $this->quartoWarning(sprintf(
                    "%s, which did not run: %s.\n"
                    ."  Quarto starts a kernel through the Jupyter in that interpreter, so\n"
                    .'  `quarto render` will not start this kernel, or any other, until that is'
                    ."\n  fixed.",
                    $where,
                    $resolution['problem'],
                ), $python),
            ];
        }

        if ($resolution['jupyterClient'] !== null) {
            return [
                sprintf(
                    "  quarto       %s (jupyter_client %s)\n",
                    $interpreter,
                    $resolution['jupyterClient'],
                ),
                '',
            ];
        }

        return [
            sprintf("  quarto       %s (no Jupyter)\n", $interpreter),
            $this->quartoWarning(sprintf(
                "%s, which has no Jupyter in it.\n"
                ."  Jupyter is what starts a kernel and collects its output, so `quarto render`\n"
                .'  will not start this kernel, or any other, through that interpreter.',
                $where,
            ), $python),
        ];
    }

    /**
     * A warning about Quarto's interpreter, with the way out of it.
     *
     * The remedy is a `PATH` entry built from the recorded interpreter, which
     * is worth suggesting only when that interpreter has Jupyter itself.
     * Otherwise there is no interpreter here to point at and the choice of one
     * belongs to the reader. `quarto check jupyter` is the authority in both
     * cases, because the resolution order replicated here is Quarto's and could
     * change under it.
     *
     * @param  string  $problem  The opening paragraph, already wrapped.
     * @param  string  $python  The interpreter the kernelspec names.
     */
    private function quartoWarning(string $problem, string $python): string
    {
        $remedy = $this->quarto->jupyterClientVersion($python) !== null
            ? sprintf(
                "  Put the interpreter this kernelspec names first on your PATH, where Quarto\n"
                ."  finds it as python3:\n\n    %s\n",
                self::exportPathCommand($python),
            )
            : sprintf(
                "  Quarto needs a Python with Jupyter in it. The interpreter this kernelspec\n"
                ."  names, %s, has none either, so there is no PATH entry to suggest here.\n",
                $python,
            );

        return sprintf(
            "juphyter: %s\n%s\n  `quarto check jupyter` reports the interpreter Quarto chose.\n",
            $problem,
            $remedy,
        );
    }

    /**
     * Add the `PATH` entry to a shell startup file, when asked by name.
     *
     * The command will not touch a startup file on its own, because changing a
     * reader's environment behind their back is the harm that kept
     * `QUARTO_PYTHON` out of one. Naming the file is the consent, and it is the
     * one thing the reader cannot have the command do any other way: a child
     * process cannot put a directory on its parent's `PATH`, so without this
     * the line has to be copied by hand.
     *
     * The write is idempotent by the directory rather than by the whole line,
     * so a file that already reaches this interpreter is left alone however the
     * entry there was spelled. A failure is reported and does not fail the
     * install, which has already written the kernelspec by this point.
     *
     * @param  string|null  $file  A `--path-file` value.
     * @param  string  $python  The interpreter the kernelspec names.
     * @return string The line for the install report, empty when not asked.
     */
    private function pathFile(?string $file, string $python): string
    {
        $file = trim($file ?? '');

        if ($file === '') {
            return '';
        }

        $bin = dirname($python);
        $existing = is_file($file) ? (string) @file_get_contents($file) : '';
        $line = self::exportPathCommand($python);

        if (str_contains($existing, $bin)) {
            return sprintf("  path-file    %s already reaches %s\n", $file, $bin);
        }

        $refused = sprintf(
            "  path-file    could not write %s, so add this line yourself: %s\n",
            $file,
            $line,
        );

        // Asked before writing rather than after, because a failed write raises
        // a diagnostic of its own and the caller wants the report, not the
        // notice.
        if (! self::appendable($file)) {
            return $refused;
        }

        $separator = $existing !== '' && ! str_ends_with($existing, "\n") ? "\n" : '';

        if (@file_put_contents($file, $separator.$line."\n", FILE_APPEND) === false) {
            return $refused;
        }

        return sprintf("  path-file    added to %s: %s\n", $file, $line);
    }

    /** Whether a path is a file that can be appended to, or can become one. */
    private static function appendable(string $file): bool
    {
        if (is_dir($file)) {
            return false;
        }

        if (file_exists($file)) {
            return is_writable($file);
        }

        $directory = dirname($file);

        return is_dir($directory) && is_writable($directory);
    }

    /** The `PATH` entry that puts an interpreter's own `bin` directory first. */
    private static function exportPathCommand(string $python): string
    {
        return sprintf('export PATH="%s:$PATH"', dirname($python));
    }

    /** `8.4` from `8.4.25`, for the default display name. */
    private static function shortVersion(string $version): string
    {
        $parts = explode('.', $version);

        return isset($parts[1]) ? $parts[0].'.'.$parts[1] : $version;
    }

    /**
     * The engine the wrapper will spawn: the PHAR, or this package's `bin/juphyter`.
     *
     * The wrapper runs this with the PHP binary, so the path has to be one the
     * filesystem has. {@see Phar::running()} is asked for it rather than derived
     * from the package root, because a PHAR built with an alias has a root of
     * `phar://some-alias.phar` and only the archive itself knows where it is.
     * Outside a PHAR, `Phar::running()` is empty and the prefix is stripped,
     * which is the path a test's fake root describes.
     */
    private function enginePath(): string
    {
        if (! str_starts_with($this->packageRoot, 'phar://')) {
            return $this->packageRoot.'/bin/juphyter';
        }

        $running = class_exists(Phar::class) ? Phar::running(false) : '';

        return $running !== '' ? $running : substr($this->packageRoot, strlen('phar://'));
    }

    /**
     * Where `PYTHONPATH` will point.
     *
     * A PHAR install gets its own copy beside the kernelspec, because Python
     * cannot import from inside the archive.
     */
    private function kernelModulePath(string $kernelDirectory): string
    {
        if (str_starts_with($this->packageRoot, 'phar://')) {
            return $kernelDirectory.'/kernel';
        }

        return $this->packageRoot.'/kernel';
    }

    /**
     * Refuse an install whose Python wrapper is missing.
     *
     * The kernelspec's `argv` runs `python -m juphyter_kernel`, and the module
     * ships inside this package, so a package without it can only produce a
     * kernelspec that a front end lists and cannot start. The `phar://` case
     * reads inside the archive, which the stream wrapper does for `is_file()`
     * as it would for a directory.
     *
     * @throws RuntimeException When the module is not where it ships.
     */
    private function requireKernelModule(): void
    {
        $module = $this->packageRoot.'/kernel/juphyter_kernel';

        if (is_file($module.'/kernel.py')) {
            return;
        }

        throw new RuntimeException(sprintf(
            "juphyter: the Python wrapper is not at %s, so there is nothing for a kernelspec to\n"
            ."start. The kernel runs as `python -m juphyter_kernel`, and that module ships inside\n"
            ."this package. Install juphyter again, or download the PHAR again, and run this\n"
            .'command once more.',
            $module,
        ));
    }

    /** Copy the wrapper module out of the PHAR, when that is where it lives. */
    private function copyKernelModule(string $destination): void
    {
        if (! str_starts_with($this->packageRoot, 'phar://')) {
            return;
        }

        $source = $this->packageRoot.'/kernel';

        if (! is_dir($source)) {
            return;
        }

        self::copyDirectory($source, $destination);
    }

    /** @throws RuntimeException When a file cannot be copied. */
    private static function copyDirectory(string $source, string $destination): void
    {
        if (! is_dir($destination) && ! @mkdir($destination, 0o755, true) && ! is_dir($destination)) {
            throw new RuntimeException(sprintf('juphyter: could not create %s.', $destination));
        }

        $entries = scandir($source) ?: [];

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $from = $source.'/'.$entry;
            $to = $destination.'/'.$entry;

            if (is_dir($from)) {
                self::copyDirectory($from, $to);

                continue;
            }

            if (! @copy($from, $to)) {
                throw new RuntimeException(sprintf('juphyter: could not copy %s to %s.', $from, $to));
            }
        }
    }

    /**
     * Refuse to overwrite a kernelspec juphyter did not write.
     *
     * Replacing juphyter's own kernelspec is how reinstalling works. Replacing
     * somebody else's kernel of the same name is not, so the command stops and
     * suggests a different `--name`.
     *
     * @throws RuntimeException When the file exists and is not juphyter's.
     */
    private function refuseForeignSpec(string $file): void
    {
        if (! is_file($file)) {
            return;
        }

        $contents = (string) @file_get_contents($file);
        $decoded = json_decode($contents, true);

        if (KernelSpec::isJuphyter($decoded)) {
            return;
        }

        throw new RuntimeException(sprintf(
            'juphyter: %s already exists and juphyter did not write it. Install under a '
            .'different --name, or remove that kernelspec first.',
            $file,
        ));
    }

    /**
     * Read `--option value` and `--option=value` pairs.
     *
     * The grammar itself lives in {@see Options}, which every command shares.
     *
     * @param  list<string>  $arguments
     * @return array<string, string>
     * @throws RuntimeException On an unknown option, a missing value, or a stray argument.
     */
    private static function parseOptions(array $arguments): array
    {
        return Options::parse($arguments, self::OPTIONS, self::usage());
    }

    /** The command's own help. */
    public static function usage(): string
    {
        return <<<'TEXT'
            Usage: juphyter install [options]

            Writes a Jupyter kernelspec so that JupyterLab and Quarto can run PHP cells.

            Options:
              --name NAME           The kernel's name and directory. Default: php.
              --display-name TEXT   What the launcher shows. Default: the PHP version.
              --php PATH            The PHP binary that runs cells. Default: this one.
              --python PATH         The interpreter that runs the wrapper kernel.
                                    Default: the one that owns the jupyter on PATH.
              --prefix PATH         Install under PATH/share/jupyter instead of the
                                    per-user Jupyter data directory.
              --autoload PATH       A vendor/autoload.php for the engine to load instead
                                    of searching upward from the notebook.
              --create-venv PATH    Build a virtual environment with JupyterLab at PATH
                                    and install the kernel into it. For a machine whose
                                    only Python cannot host the kernel, which is asked
                                    about when this is absent.
              --path-file PATH      Append the kernel interpreter's PATH entry to this
                                    shell startup file, such as ~/.zshrc. Nothing is
                                    written to a startup file without this.
              -h, --help            Print this text.

            Several PHP versions can be installed side by side by giving each one its own
            --name, such as --name php83 --php /path/to/php83.

            TEXT;
    }
}
