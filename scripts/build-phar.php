#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Build `juphyter.phar`.
 *
 * The PHAR covers people who want a PHP kernel without creating a Composer
 * project. `juphyter install` from the PHAR writes a kernelspec whose
 * `JUPHYTER_ENGINE` is the PHAR itself, and the engine then finds the
 * notebook's own dependencies at runtime.
 *
 * ## Usage
 *
 *     php scripts/build-phar.php [--output=PATH] [--quiet]
 *
 * Writing a PHAR needs `phar.readonly=0`, which is off in a default PHP build,
 * so the script re-runs itself with the setting when it has to.
 *
 * ## Configuration
 *
 * The file list comes from `phar.json`, so there is one place to change it.
 * This script is the only thing that reads that file. The keys are `main`,
 * `output`, `chmod`, `compression`, `directories`, and `files`.
 *
 * A directory named in `directories` that does not exist is skipped with a
 * note, which is how `kernel/` can be listed before the Python wrapper lands.
 *
 * The Composer dependencies are not listed in `phar.json`. The script copies
 * the listed paths into a staging directory and runs
 * `composer install --no-dev` there, so the archive holds exactly the `require`
 * tree and none of the dev tools. That needs `composer` on `PATH`.
 *
 * ## The archive
 *
 * No PHAR alias is set, so `__FILE__` and `Phar::running()` inside the archive
 * carry its real path on disk. The install command needs that path for
 * `JUPHYTER_ENGINE`, and an alias would give it `phar://juphyter.phar` instead.
 *
 * The shebang line of `bin/juphyter` is dropped on the way in. It sits before
 * the opening PHP tag, so a `require` of the file would print it.
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "build-phar: run this from the command line.\n");
    exit(1);
}

$arguments = array_slice($argv, 1);
$quiet = in_array('--quiet', $arguments, true);
$output = null;

foreach ($arguments as $argument) {
    if (str_starts_with($argument, '--output=')) {
        $output = substr($argument, strlen('--output='));
    } elseif ($argument !== '--quiet') {
        fwrite(STDERR, sprintf("build-phar: unknown argument \"%s\".\n", $argument));
        exit(1);
    }
}

/** Print a progress line unless `--quiet` was given. */
$say = function (string $message) use ($quiet): void {
    if (! $quiet) {
        fwrite(STDOUT, $message."\n");
    }
};

/** Stop with a message on standard error. */
$fail = function (string $message): never {
    fwrite(STDERR, 'build-phar: '.$message."\n");
    exit(1);
};

// Writing a PHAR is refused outright when phar.readonly is on, so re-run with
// it off rather than asking the caller to remember the flag.
if (! Phar::canWrite()) {
    $command = array_merge([PHP_BINARY, '-d', 'phar.readonly=0', __FILE__], $arguments);
    $process = proc_open($command, [], $pipes);

    if (! is_resource($process)) {
        $fail('could not re-run itself with phar.readonly=0.');
    }

    exit(proc_close($process));
}

$root = dirname(__DIR__);

$configFile = $root.'/phar.json';

if (! is_file($configFile)) {
    $fail(sprintf('%s is missing.', $configFile));
}

$config = json_decode((string) file_get_contents($configFile), true);

if (! is_array($config)) {
    $fail(sprintf('%s is not a JSON object.', $configFile));
}

$main = is_string($config['main'] ?? null) ? $config['main'] : 'bin/juphyter';
$output ??= is_string($config['output'] ?? null) ? $config['output'] : 'build/juphyter.phar';
$output = str_starts_with($output, '/') ? $output : $root.'/'.$output;
$directories = array_values(array_filter((array) ($config['directories'] ?? []), 'is_string'));
$files = array_values(array_filter((array) ($config['files'] ?? []), 'is_string'));
$chmod = is_string($config['chmod'] ?? null) ? intval($config['chmod'], 8) : 0o755;
$compress = strtoupper((string) ($config['compression'] ?? 'NONE')) === 'GZ';

if (! is_file($root.'/'.$main)) {
    $fail(sprintf('%s is missing, and phar.json names it as the entry point.', $main));
}

$composer = null;

foreach (explode(PATH_SEPARATOR, (string) getenv('PATH')) as $directory) {
    $candidate = rtrim($directory, '/').'/composer';

    if ($directory !== '' && is_file($candidate) && is_executable($candidate)) {
        $composer = $candidate;
        break;
    }
}

if ($composer === null) {
    $fail(
        'composer is not on PATH, and the build needs it to install the production '
        .'dependencies into the archive. Install Composer and run this again.'
    );
}

/** Copy a file or a directory tree, skipping version-control and cache clutter. */
$copy = function (string $from, string $to) use (&$copy, $fail): void {
    $name = basename($from);

    if (in_array($name, ['.git', '.DS_Store', '__pycache__'], true) || str_ends_with($name, '.pyc')) {
        return;
    }

    if (is_dir($from)) {
        if (! is_dir($to) && ! mkdir($to, 0o755, true) && ! is_dir($to)) {
            $fail(sprintf('could not create %s.', $to));
        }

        foreach (scandir($from) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                $copy($from.'/'.$entry, $to.'/'.$entry);
            }
        }

        return;
    }

    if (! is_dir(dirname($to)) && ! mkdir(dirname($to), 0o755, true) && ! is_dir(dirname($to))) {
        $fail(sprintf('could not create %s.', dirname($to)));
    }

    if (! copy($from, $to)) {
        $fail(sprintf('could not copy %s.', $from));
    }
};

/** Delete a directory tree, unlinking a symlink rather than following it. */
$remove = function (string $path) use (&$remove): void {
    if (is_dir($path) && ! is_link($path)) {
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                $remove($path.'/'.$entry);
            }
        }

        @rmdir($path);

        return;
    }

    if (file_exists($path) || is_link($path)) {
        @unlink($path);
    }
};

$staging = sys_get_temp_dir().'/juphyter-phar-'.bin2hex(random_bytes(6));

if (! mkdir($staging, 0o755, true)) {
    $fail(sprintf('could not create the staging directory %s.', $staging));
}

try {
    $say('Staging in '.$staging);

    $copy($root.'/'.$main, $staging.'/'.$main);
    $copy($root.'/composer.json', $staging.'/composer.json');

    // The lock file is not committed, so it is used when it happens to be there.
    // With it, the staging install is deterministic and can run from Composer's
    // cache; without it, Composer resolves the require tree itself.
    if (is_file($root.'/composer.lock')) {
        $copy($root.'/composer.lock', $staging.'/composer.lock');
    }

    foreach ($files as $file) {
        if (is_file($root.'/'.$file)) {
            $copy($root.'/'.$file, $staging.'/'.$file);
        }
    }

    foreach ($directories as $directory) {
        if (! is_dir($root.'/'.$directory)) {
            $say(sprintf('  skipping %s/, which does not exist yet', $directory));

            continue;
        }

        $copy($root.'/'.$directory, $staging.'/'.$directory);
    }

    $say('Installing the production dependencies');

    $install = proc_open(
        [
            $composer, 'install',
            '--no-dev', '--no-interaction', '--no-progress', '--no-scripts', '--no-plugins',
            '--classmap-authoritative', '--prefer-dist',
            '--working-dir', $staging,
        ],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
    );

    if (! is_resource($install)) {
        $fail('could not run composer.');
    }

    $composerOut = (string) stream_get_contents($pipes[1]);
    $composerErr = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    if (proc_close($install) !== 0) {
        $fail("composer install failed in the staging directory:\n".trim($composerOut."\n".$composerErr));
    }

    if (! is_file($staging.'/vendor/autoload.php')) {
        $fail('composer install produced no autoloader in the staging directory.');
    }

    $say('Building the archive');

    if (! is_dir(dirname($output)) && ! mkdir(dirname($output), 0o755, true) && ! is_dir(dirname($output))) {
        $fail(sprintf('could not create %s.', dirname($output)));
    }

    // Build next to the destination and move it into place, so a failed build
    // never leaves a half-written PHAR where the finished one belongs.
    $temporary = $output.'.building';
    $remove($temporary);

    $phar = new Phar($temporary);
    $phar->startBuffering();

    $entry = (string) file_get_contents($staging.'/'.$main);
    $phar->addFromString($main, (string) preg_replace('/^#![^\r\n]*\R/', '', $entry, 1));

    // Everything the staging directory holds goes in, which is the configured
    // file list plus the production vendor tree Composer just wrote. The entry
    // point is already in, without its shebang, and the lock file has no use at
    // runtime.
    $staged = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($staging, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST,
    );

    $count = 1;

    foreach ($staged as $path => $info) {
        /** @var SplFileInfo $info */
        if (! $info->isFile()) {
            continue;
        }

        $relative = substr((string) $path, strlen($staging) + 1);

        if ($relative === $main || $relative === 'composer.lock') {
            continue;
        }

        $phar->addFile((string) $path, $relative);
        $count++;
    }

    $phar->setStub(<<<'STUB'
        #!/usr/bin/env php
        <?php

        // No Phar::mapPhar() and no alias: the install command needs the archive's
        // real path on disk for JUPHYTER_ENGINE, and an alias would hide it.
        require 'phar://'.__FILE__.'/bin/juphyter';

        __HALT_COMPILER(); ?>
        STUB);

    $phar->stopBuffering();

    if ($compress) {
        $phar->compressFiles(Phar::GZ);
    }

    unset($phar);

    $remove($output);

    if (! rename($temporary, $output)) {
        $fail(sprintf('could not move the archive to %s.', $output));
    }

    chmod($output, $chmod);
} finally {
    $remove($staging);
}

// A PHAR that cannot list its own commands is not worth shipping, so the build
// runs it once before reporting success.
$check = proc_open([PHP_BINARY, $output], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);

if (! is_resource($check)) {
    $fail('built the archive but could not run it.');
}

$checkOut = (string) stream_get_contents($pipes[1]);
$checkErr = (string) stream_get_contents($pipes[2]);
fclose($pipes[1]);
fclose($pipes[2]);

if (proc_close($check) !== 0 || ! str_contains($checkOut, 'Usage: juphyter')) {
    $fail("the built archive does not run:\n".trim($checkOut."\n".$checkErr));
}

$say(sprintf(
    'Built %s from %d files (%s KB)',
    $output,
    $count,
    number_format((int) filesize($output) / 1024, 0),
));

exit(0);
