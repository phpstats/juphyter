<?php

declare(strict_types=1);

namespace Juphyter\Install;

use RuntimeException;

/**
 * The `kernel.json` juphyter writes.
 *
 * A kernelspec tells Jupyter how to start a kernel. juphyter's names the Python
 * interpreter that runs the wrapper, because the wrapper owns the Jupyter
 * protocol, and passes the PHP side through the environment:
 *
 * ```json
 * {
 *     "argv": ["/opt/homebrew/bin/python3", "-m", "juphyter_kernel", "-f", "{connection_file}"],
 *     "display_name": "PHP 8.5",
 *     "language": "php",
 *     "interrupt_mode": "signal",
 *     "env": {
 *         "JUPHYTER_PHP": "/path/to/php85",
 *         "JUPHYTER_ENGINE": "/path/to/vendor/phpstats/juphyter/bin/juphyter",
 *         "PYTHONPATH": "/path/to/vendor/phpstats/juphyter/kernel"
 *     }
 * }
 * ```
 *
 * `{connection_file}` is a placeholder that Jupyter substitutes when it starts
 * the kernel, so it is written literally. `interrupt_mode` is `signal` because
 * the wrapper interrupts a running cell by forwarding SIGINT to the engine
 * process rather than by sending a protocol message.
 *
 * `PYTHONPATH` points at the package's `kernel/` directory, which is where the
 * wrapper module ships. Shipping it on `PYTHONPATH` rather than through PyPI
 * keeps Python release management out of the first milestone.
 *
 * This class holds no filesystem behavior. It validates the kernel name,
 * assembles the document, and recognizes a document juphyter wrote.
 * {@see InstallCommand} resolves the paths and writes the file.
 */
final class KernelSpec
{
    /**
     * The characters Jupyter allows in a kernel name.
     *
     * jupyter_client lowercases kernel names and matches them against this set,
     * so a name outside it either cannot be selected or collides with another.
     * Rejecting it here also keeps `--name` from reaching outside the kernels
     * directory, because the name becomes a path segment.
     */
    private const NAME_PATTERN = '/^[a-z0-9][a-z0-9._-]*$/';

    /**
     * @param  string  $name  The kernel name, already normalized by {@see normalizeName()}.
     * @param  string  $displayName  What the launcher shows.
     * @param  string  $python  The interpreter that runs the wrapper module.
     * @param  string  $php  The PHP binary that runs the engine.
     * @param  string  $engine  The `bin/juphyter` script, or the PHAR.
     * @param  string  $pythonPath  The directory holding the `juphyter_kernel` module.
     * @param  string|null  $autoload  A `vendor/autoload.php` to use instead of the engine's search.
     */
    public function __construct(
        private readonly string $name,
        private readonly string $displayName,
        private readonly string $python,
        private readonly string $php,
        private readonly string $engine,
        private readonly string $pythonPath,
        private readonly ?string $autoload = null,
    ) {}

    /**
     * Lowercase a kernel name and reject one Jupyter cannot use.
     *
     * @throws RuntimeException When the name is empty or holds a character outside the allowed set.
     */
    public static function normalizeName(string $name): string
    {
        $normalized = strtolower(trim($name));

        if (preg_match(self::NAME_PATTERN, $normalized) !== 1) {
            throw new RuntimeException(sprintf(
                'juphyter: "%s" is not a usable kernel name. A name starts with a letter or digit '
                .'and holds only lowercase letters, digits, dots, dashes, and underscores.',
                $name,
            ));
        }

        return $normalized;
    }

    /**
     * Whether a decoded `kernel.json` is one juphyter wrote.
     *
     * `uninstall` asks before removing anything. The signature is the
     * `JUPHYTER_ENGINE` entry, which only juphyter writes, together with the
     * `php` language. Checking the language alone would match a kernelspec from
     * another PHP kernel, and checking the directory name would match anything a
     * user named `php`.
     *
     * @param  mixed  $spec  A decoded `kernel.json`, of whatever shape the file held.
     */
    public static function isJuphyter(mixed $spec): bool
    {
        if (! is_array($spec)) {
            return false;
        }

        $language = $spec['language'] ?? null;
        $engine = is_array($spec['env'] ?? null) ? ($spec['env']['JUPHYTER_ENGINE'] ?? null) : null;

        return $language === 'php' && is_string($engine) && $engine !== '';
    }

    /** The kernel name, which is also the kernelspec's directory name. */
    public function name(): string
    {
        return $this->name;
    }

    /**
     * The kernelspec, in the key order of the example above.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $environment = [
            'JUPHYTER_PHP' => $this->php,
            'JUPHYTER_ENGINE' => $this->engine,
            'PYTHONPATH' => $this->pythonPath,
        ];

        if ($this->autoload !== null && $this->autoload !== '') {
            $environment['JUPHYTER_AUTOLOAD'] = $this->autoload;
        }

        return [
            'argv' => [$this->python, '-m', 'juphyter_kernel', '-f', '{connection_file}'],
            'display_name' => $this->displayName,
            'language' => 'php',
            'interrupt_mode' => 'signal',
            'env' => $environment,
        ];
    }

    /**
     * The file contents, pretty-printed with a closing newline.
     *
     * Slashes stay unescaped so that a reader can check the paths.
     */
    public function toJson(): string
    {
        $json = json_encode(
            $this->toArray(),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        );

        return $json."\n";
    }
}
