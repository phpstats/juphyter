<?php

declare(strict_types=1);

use Juphyter\Console\Application;
use Juphyter\Tests\Fixtures\Console\ForgotMakeCommand;
use Juphyter\Tests\Fixtures\Console\GreetCommand;
use Juphyter\Tests\Fixtures\Console\KernelSpecCommand;
use Juphyter\Tests\Fixtures\Console\NeedsSocketCommand;
use Juphyter\Version;

/** The dispatcher over the fixture commands, which stand in for the real ones. */
function fixtureApplication(): Application
{
    return new Application(
        __DIR__.'/../../Fixtures/Console',
        'Juphyter\\Tests\\Fixtures\\Console\\',
    );
}

/**
 * Run $fn with a dispatcher over a directory that holds no command.
 *
 * The real src/Console is deliberately not used for this, because the later
 * milestone-1 tasks fill it, and a test that asserted it was empty would start
 * failing the day the engine command lands. The directory is unique to the call
 * and removed afterwards, so the tests leave nothing in the temporary directory.
 */
function withEmptyApplication(Closure $fn): mixed
{
    $directory = sys_get_temp_dir().'/juphyter-no-commands-'.bin2hex(random_bytes(6));
    mkdir($directory, 0o777, true);

    try {
        return $fn(new Application($directory, 'Juphyter\\Nowhere\\'));
    } finally {
        rmdir($directory);
    }
}

beforeEach(function () {
    GreetCommand::$received = null;
    NeedsSocketCommand::$ran = null;
});

describe('discovery', function () {
    it('names a command from its class name', function () {
        expect(GreetCommand::name())->toBe('greet')
            ->and(KernelSpecCommand::name())->toBe('kernel-spec');
    });

    it('finds the concrete commands in its directory, sorted by name', function () {
        expect(array_keys(fixtureApplication()->commands()))
            ->toBe(['forgot-make', 'greet', 'kernel-spec', 'needs-socket']);
    });

    it('scans its directory once, however many times it is asked', function () {
        // An empty directory under the fixture namespace, so a file appearing
        // in it would be discovered on a rescan. The file's contents do not
        // matter: discovery reads the name and resolves the class through the
        // autoloader, which finds the real fixture.
        $directory = sys_get_temp_dir().'/juphyter-rescan-'.bin2hex(random_bytes(6));
        mkdir($directory, 0o777, true);
        $application = new Application($directory, 'Juphyter\\Tests\\Fixtures\\Console\\');

        expect($application->commands())->toBe([]);

        file_put_contents($directory.'/GreetCommand.php', '');

        try {
            expect($application->commands())->toBe([])
                ->and(array_keys((new Application($directory, 'Juphyter\\Tests\\Fixtures\\Console\\'))->commands()))
                ->toBe(['greet']);
        } finally {
            unlink($directory.'/GreetCommand.php');
            rmdir($directory);
        }
    });

    it('leaves out abstract classes and files that are not PHP', function () {
        $commands = fixtureApplication()->commands();

        expect($commands)->not->toHaveKey('scaffold')
            ->and($commands)->not->toHaveKey('notes');
    });

    it('reports an empty directory as no commands', function () {
        withEmptyApplication(fn (Application $application) => expect($application->commands())->toBe([]));
    });

    it('treats a missing directory as empty rather than an error', function () {
        $application = new Application(__DIR__.'/does-not-exist', 'Juphyter\\Nowhere\\');

        expect($application->commands())->toBe([]);
    });

    it('reads this package\'s own command directory without error', function () {
        $commands = Application::default()->commands();

        foreach ($commands as $name => $class) {
            expect($name)->toBeString()
                ->and($name)->toBe($class::name())
                ->and(is_subclass_of($class, Juphyter\Console\Command::class))->toBeTrue();
        }
    });
});

describe('dispatch', function () {
    it('runs a registered command and returns its exit code', function () {
        $run = captured(fn ($out, $err) => fixtureApplication()->run(
            ['juphyter', 'greet'], $out, $err
        ));

        expect($run['result'])->toBe(0)
            ->and(GreetCommand::$received)->toBe([])
            ->and($run['stdout'])->toBe('')
            ->and($run['stderr'])->toBe('');
    });

    it('passes on the arguments after the subcommand name', function () {
        $run = captured(fn ($out, $err) => fixtureApplication()->run(
            ['juphyter', 'greet', '--loudly', 'ada'], $out, $err
        ));

        expect(GreetCommand::$received)->toBe(['--loudly', 'ada'])
            ->and($run['result'])->toBe(7);
    });

    it('dispatches a kebab-cased name', function () {
        $run = captured(fn ($out, $err) => fixtureApplication()->run(
            ['juphyter', 'kernel-spec'], $out, $err
        ));

        expect($run['result'])->toBe(0);
    });
});

describe('construction', function () {
    it('lists a command with a required constructor argument', function () {
        $run = captured(fn ($out, $err) => fixtureApplication()->run(['juphyter'], $out, $err));

        expect($run['result'])->toBe(0)
            ->and($run['stdout'])->toContain('needs-socket')
            ->and($run['stdout'])->toContain('Needs a socket to build')
            ->and($run['stderr'])->toBe('');
    });

    it('dispatches a command with a required constructor argument through make()', function () {
        putenv('JUPHYTER_TEST_SOCKET=/tmp/from-the-environment.sock');

        try {
            $run = captured(fn ($out, $err) => fixtureApplication()->run(
                ['juphyter', 'needs-socket'], $out, $err
            ));
        } finally {
            putenv('JUPHYTER_TEST_SOCKET');
        }

        expect($run['result'])->toBe(0)
            ->and(NeedsSocketCommand::$ran)->toBe('/tmp/from-the-environment.sock');
    });

    it('does not construct a command to list it', function () {
        // The regression this pins: the listing used to build every command it
        // found, so one command with a constructor argument broke the
        // no-argument listing and the unknown-command path for every
        // invocation, not just its own.
        fixtureApplication()->usage();

        expect(NeedsSocketCommand::$ran)->toBeNull();
    });

    it('reports a command it cannot build, rather than dying', function () {
        $run = captured(fn ($out, $err) => fixtureApplication()->run(
            ['juphyter', 'forgot-make'], $out, $err
        ));

        expect($run['result'])->toBe(1)
            ->and($run['stderr'])->toContain('cannot build')
            ->and($run['stderr'])->toContain(ForgotMakeCommand::class)
            ->and($run['stderr'])->toContain('override make()');
    });

    it('still lists and dispatches the other commands when one cannot be built', function () {
        $listing = captured(fn ($out, $err) => fixtureApplication()->run(['juphyter'], $out, $err));
        $dispatch = captured(fn ($out, $err) => fixtureApplication()->run(
            ['juphyter', 'greet'], $out, $err
        ));

        expect($listing['result'])->toBe(0)
            ->and($listing['stdout'])->toContain('forgot-make')
            ->and($dispatch['result'])->toBe(0)
            ->and(GreetCommand::$received)->toBe([]);
    });

    it('keeps the unknown-command path working alongside such a command', function () {
        $run = captured(fn ($out, $err) => fixtureApplication()->run(
            ['juphyter', 'frobnicate'], $out, $err
        ));

        expect($run['result'])->toBe(1)
            ->and($run['stderr'])->toContain('needs-socket');
    });
});

describe('usage', function () {
    it('lists the commands on stdout and exits 0 with no arguments', function () {
        $run = captured(fn ($out, $err) => fixtureApplication()->run(['juphyter'], $out, $err));

        expect($run['result'])->toBe(0)
            ->and($run['stderr'])->toBe('')
            ->and($run['stdout'])->toContain('Usage: juphyter <command> [<args>]')
            ->and($run['stdout'])->toContain('greet')
            ->and($run['stdout'])->toContain('Greet somebody')
            ->and($run['stdout'])->toContain('kernel-spec')
            ->and($run['stdout'])->toContain('Write a kernelspec');
    });

    it('lists the commands for --help and -h too', function () {
        foreach (['--help', '-h'] as $flag) {
            $run = captured(fn ($out, $err) => fixtureApplication()->run(
                ['juphyter', $flag], $out, $err
            ));

            expect($run['result'])->toBe(0)
                ->and($run['stdout'])->toContain('Usage: juphyter');
        }
    });

    it('says so plainly when there is no command to list', function () {
        $run = withEmptyApplication(fn (Application $application) => captured(
            fn ($out, $err) => $application->run(['juphyter'], $out, $err)
        ));

        expect($run['result'])->toBe(0)
            ->and($run['stdout'])->toContain('No commands are available.');
    });

    it('still rejects an unknown command when nothing is registered', function () {
        $run = withEmptyApplication(fn (Application $application) => captured(
            fn ($out, $err) => $application->run(['juphyter', 'engine'], $out, $err)
        ));

        expect($run['result'])->toBe(1)
            ->and($run['stderr'])->toContain('unknown command "engine"');
    });

    it('prints usage to stderr and exits non-zero for an unknown command', function () {
        $run = captured(fn ($out, $err) => fixtureApplication()->run(
            ['juphyter', 'frobnicate'], $out, $err
        ));

        expect($run['result'])->toBe(1)
            ->and($run['stdout'])->toBe('')
            ->and($run['stderr'])->toContain('unknown command "frobnicate"')
            ->and($run['stderr'])->toContain('Usage: juphyter <command> [<args>]')
            ->and($run['stderr'])->toContain('greet');
    });

    it('does not run a command when the name is unknown', function () {
        captured(fn ($out, $err) => fixtureApplication()->run(['juphyter', 'greetings'], $out, $err));

        expect(GreetCommand::$received)->toBeNull();
    });
});

describe('version', function () {
    it('reports the version on stdout and exits 0 for --version and -V', function () {
        foreach (['--version', '-V'] as $flag) {
            $run = captured(fn ($out, $err) => fixtureApplication()->run(
                ['juphyter', $flag], $out, $err
            ));

            expect($run['result'])->toBe(0)
                ->and($run['stderr'])->toBe('')
                ->and($run['stdout'])->toBe(Version::line())
                ->and($run['stdout'])->toContain('juphyter ')
                ->and($run['stdout'])->not->toContain('Usage: juphyter');
        }
    });

    // The flag is answered before anything is discovered or constructed, which
    // is what makes it usable in a bug report about a command that will not run.
    it('answers even when no command can be listed', function () {
        $run = withEmptyApplication(fn (Application $application) => captured(
            fn ($out, $err) => $application->run(['juphyter', '--version'], $out, $err)
        ));

        expect($run['result'])->toBe(0)
            ->and($run['stdout'])->toContain('juphyter ')
            ->and($run['stdout'])->not->toContain('No commands are available.');
    });

    it('treats a lowercase -v as an unknown command rather than the version flag', function () {
        $run = captured(fn ($out, $err) => fixtureApplication()->run(
            ['juphyter', '-v'], $out, $err
        ));

        expect($run['result'])->toBe(1)
            ->and($run['stderr'])->toContain('unknown command "-v"');
    });
});
