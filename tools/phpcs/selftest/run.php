<?php
declare(strict_types=1);

/**
 * Checks that the naming ruleset reports exactly what it is meant to report.
 *
 * The fixtures live outside every path phpcs.xml.dist scans, so an ordinary
 * lint run never sees them. This script points PHPCS at them directly and
 * compares the whole report against the expected list. A sniff that stops
 * firing, fires twice, or starts flagging a compliant name fails here.
 */

$root = dirname(__DIR__, 3);
$phpcs = $root.'/vendor/bin/phpcs';
if (!is_file($phpcs)) {
    fwrite(STDERR, "phpcs is not installed; run composer install\n");
    exit(1);
}

$cases = [
    'the package ruleset against every kind of violation' => [
        'standard' => $root.'/phpcs.xml.dist',
        'file' => __DIR__.'/fixtures/Naming.php',
        'expected' => [
            '4 Phpstats.NamingConventions.SnakeCaseFunctionName.NotSnakeCase',
            '6 PSR1.Methods.CamelCapsMethodName.NotCamelCaps',
            '6 Phpstats.NamingConventions.CamelCapsParameterAndProperty.ParameterNotCamelCaps',
            '9 Phpstats.NamingConventions.CamelCapsParameterAndProperty.PropertyNotCamelCaps',
            '11 Phpstats.NamingConventions.CamelCapsParameterAndProperty.PropertyNotCamelCaps',
            '12 Phpstats.NamingConventions.CamelCapsParameterAndProperty.ParameterNotCamelCaps',
            '13 PSR1.Methods.CamelCapsMethodName.NotCamelCaps',
            '13 Phpstats.NamingConventions.CamelCapsParameterAndProperty.ParameterNotCamelCaps',
            '20 Phpstats.NamingConventions.CamelCapsParameterAndProperty.ParameterNotCamelCaps',
            '20 Phpstats.NamingConventions.CamelCapsParameterAndProperty.ParameterNotCamelCaps',
        ],
    ],
    'the two rules PHPCS already ships for classes and class constants' => [
        'standard' => $root.'/phpcs.xml.dist',
        'file' => __DIR__.'/fixtures/ClassNames.php',
        'expected' => [
            '4 Squiz.Classes.ValidClassName.NotCamelCaps',
            '7 Generic.NamingConventions.UpperCaseConstantName.ClassConstantNotUpperCase',
        ],
    ],
    'the allowed property, which carries each package documented exceptions' => [
        'standard' => __DIR__.'/allowed.xml',
        'file' => __DIR__.'/fixtures/Allowed.php',
        'expected' => [
            '4 Phpstats.NamingConventions.CamelCapsParameterAndProperty.ParameterNotCamelCaps',
        ],
    ],
];

/** Every message PHPCS reports for one file, as "line sniff-code", in report order. */
function phpcs_report(string $phpcs, string $standard, string $file): array
{
    $command = escapeshellarg(PHP_BINARY).' '.escapeshellarg($phpcs)
        .' --standard='.escapeshellarg($standard)
        .' --report=json --no-colors -q '.escapeshellarg($file).' 2>/dev/null';
    $output = shell_exec($command);
    $decoded = json_decode((string) $output, true);
    if (!is_array($decoded) || !isset($decoded['files'])) {
        fwrite(STDERR, "phpcs produced no report for $file\n");
        exit(1);
    }
    $found = [];
    foreach ($decoded['files'] as $messages) {
        foreach ($messages['messages'] as $message) {
            $found[] = $message['line'].' '.$message['source'];
        }
    }
    return $found;
}

$failed = 0;
foreach ($cases as $name => $case) {
    $found = phpcs_report($phpcs, $case['standard'], $case['file']);
    if ($found === $case['expected']) {
        printf("ok   %s (%d reported)\n", $name, count($found));
        continue;
    }
    $failed++;
    printf("FAIL %s\n", $name);
    printf("     expected:\n       %s\n", implode("\n       ", $case['expected']));
    printf("     found:\n       %s\n", $found === [] ? '(nothing)' : implode("\n       ", $found));
}

exit($failed === 0 ? 0 : 1);
