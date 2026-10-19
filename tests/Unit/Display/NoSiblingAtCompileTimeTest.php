<?php

declare(strict_types=1);

use Juphyter\Display\Formatters\ChartFormatter;
use Juphyter\Display\Formatters\TableFormatter;

/**
 * juphyter installs and runs with any subset of the phpstats family present,
 * including none of it, so nothing in the display layer may name a class from
 * phibble, phplyr or phiz at compile time: a `use` import, an `instanceof`, a
 * `::class`, a parameter type or a `new` would all break loading for anyone who
 * has not installed that package.
 *
 * The suite passing with none of the three installed is the real proof. This
 * test is the one that says *why* it broke when someone adds an import out of
 * habit, and it keeps working whether or not the packages are there.
 */
const DISPLAY_FAMILY = ['Phibble', 'Phplyr', 'Phiz', 'Phit', 'Phpolars', 'Cmdstanphp'];

/** @return array<string, string> Every PHP file this task owns under src/, by name. */
function displaySources(): array
{
    $root = dirname(__DIR__, 3).'/';
    $files = [$root.'src/functions.php'];
    $directory = new RecursiveDirectoryIterator($root.'src/Display', FilesystemIterator::SKIP_DOTS);

    foreach (new RecursiveIteratorIterator($directory) as $file) {
        if ($file->getExtension() === 'php') {
            $files[] = $file->getPathname();
        }
    }

    sort($files);

    // Keyed by the path relative to the package, so a failure names the file.
    return array_combine(
        array_map(fn (string $file): string => str_replace($root, '', $file), $files),
        $files,
    );
}

/**
 * The qualified names a file resolves at compile time.
 *
 * Tokenising rather than grepping is the point: a fully-qualified name written
 * in a string literal is exactly what the formatters are supposed to use, and
 * the tokeniser tells the two apart where a regular expression cannot.
 *
 * @return list<string>
 */
function compileTimeNames(string $path): array
{
    $names = [];

    foreach (token_get_all((string) file_get_contents($path)) as $token) {
        if (! is_array($token)) {
            continue;
        }

        if (in_array($token[0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NAME_RELATIVE], true)) {
            $names[] = ltrim($token[1], '\\');
        }
    }

    return $names;
}

it('names no package of the family at compile time', function (string $path) {
    $offenders = array_values(array_filter(
        compileTimeNames($path),
        fn (string $name): bool => in_array(explode('\\', $name)[0], DISPLAY_FAMILY, true),
    ));

    expect($offenders)->toBe([], sprintf(
        '%s resolves %s at compile time. Match it by name instead, through Reflect::isA().',
        $path,
        implode(', ', $offenders),
    ));
})->with(displaySources());

it('has the names it matches on as plain strings', function () {
    expect(TableFormatter::TABLE)->toBe('Phibble\Contracts\Table')
        ->and(ChartFormatter::CHART)->toBe('Phiz\Chart')
        ->and(ChartFormatter::EXPORT)->toBe('Phiz\Export');
});

it('loads every class in the display layer with none of the family installed', function () {
    foreach (displaySources() as $path) {
        $source = (string) file_get_contents($path);

        if (preg_match('/^namespace (.+);$/m', $source, $namespace) !== 1) {
            continue;
        }

        if (preg_match('/^(?:final |abstract )*(?:readonly )?(class|interface|enum) (\w+)/m', $source, $class) !== 1) {
            continue;
        }

        expect(class_exists($namespace[1].'\\'.$class[2]) || interface_exists($namespace[1].'\\'.$class[2]))
            ->toBeTrue($path.' did not load.');
    }
});
