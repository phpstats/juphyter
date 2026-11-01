<?php

declare(strict_types=1);

/*
 * docs/reference.qmd is written by hand from the commands' help, so these tests
 * keep the two from drifting apart: every command and every option the help
 * prints has to appear on the reference page under the command's own anchor.
 */

/** What `juphyter <command> --help` prints. */
function commandHelp(string $command): string
{
    $output = [];
    exec(
        escapeshellarg(PHP_BINARY).' '.escapeshellarg(dirname(__DIR__, 3).'/bin/juphyter')
            .' '.escapeshellarg($command).' --help 2>&1',
        $output,
    );

    return implode("\n", $output);
}

/** The reference page's section for one command, from its heading to the next. */
function referenceSection(string $command): string
{
    $page = (string) file_get_contents(dirname(__DIR__, 3).'/docs/reference.qmd');
    $start = strpos($page, '{#juphyter-'.$command.'}');

    expect($start)->not->toBeFalse("docs/reference.qmd has no section for juphyter {$command}");

    $end = strpos($page, "\n### ", $start);

    return substr($page, $start, $end === false ? null : $end - $start);
}

dataset('commands', ['install', 'uninstall', 'doctor', 'engine']);

it('documents every option a command prints', function (string $command) {
    preg_match_all('/^\s+(--[a-z][a-z-]*)/m', commandHelp($command), $matches);
    $options = array_values(array_diff(array_unique($matches[1]), ['--help']));

    expect($options)->not->toBeEmpty();

    $section = referenceSection($command);

    foreach ($options as $option) {
        expect($section)->toContain('`'.$option);
    }
})->with('commands');

it('documents every command the application lists', function () {
    $output = [];
    exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg(dirname(__DIR__, 3).'/bin/juphyter').' 2>&1', $output);
    preg_match_all('/^\s{2}([a-z]+)\s{2,}/m', implode("\n", $output), $matches);

    expect($matches[1])->not->toBeEmpty();

    foreach ($matches[1] as $command) {
        expect(referenceSection($command))->toContain("juphyter {$command} [options]");
    }
});
