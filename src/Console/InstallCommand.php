<?php

declare(strict_types=1);

namespace Juphyter\Console;

use Closure;
use Juphyter\Engine\Interrupt;
use Juphyter\Install\JupyterPaths;
use Juphyter\Install\KernelSpec;
use Juphyter\Install\PythonProbe;
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
    private const OPTIONS = ['name', 'display-name', 'php', 'python', 'prefix', 'autoload'];

    /** @var resource */
    private $stdout;

    /** @var resource */
    private $stderr;

    /**
     * @param  JupyterPaths  $paths  Where kernelspecs live.
     * @param  PythonProbe  $probe  Which interpreter to name, and whether it can host the kernel.
     * @param  Closure(list<string>): array{code: int, stdout: string, stderr: string}  $runner  How to run a subprocess, used to validate `--php`.
     * @param  string  $packageRoot  This package's root, which is a `phar://` path inside a PHAR.
     * @param  resource|null  $stdout
     * @param  resource|null  $stderr
     */
    public function __construct(
        private readonly JupyterPaths $paths,
        private readonly PythonProbe $probe,
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
        return 'Register the PHP kernel with Jupyter';
    }

    public static function make(): static
    {
        $runner = PythonProbe::processRunner();

        return new self(
            JupyterPaths::fromEnvironment(),
            new PythonProbe($runner),
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

        $python = $this->probe->interpreter($options['python'] ?? null);
        $ipykernel = $this->probe->requireIpykernel($python);

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
        ));

        return 0;
    }

    /**
     * The PHP binary and its version.
     *
     * With no `--php`, that is the interpreter running this command, which it
     * can answer from its own constants. With `--php`, it is whatever the given
     * binary reports about itself, which both validates it and turns a relative
     * name into the absolute path the kernelspec needs.
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

        return [$binary !== '' ? $binary : $php, $version, $signals];
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
              -h, --help            Print this text.

            Several PHP versions can be installed side by side by giving each one its own
            --name, such as --name php83 --php /path/to/php83.

            TEXT;
    }
}
