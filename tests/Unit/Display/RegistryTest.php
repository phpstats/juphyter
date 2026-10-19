<?php

declare(strict_types=1);

use Juphyter\Display\Formatters\ArrayFormatter;
use Juphyter\Display\Formatters\ChartFormatter;
use Juphyter\Display\Formatters\HtmlFormatter;
use Juphyter\Display\Formatters\MimeBundleFormatter;
use Juphyter\Display\Formatters\ObjectFormatter;
use Juphyter\Display\Formatters\ScalarFormatter;
use Juphyter\Display\Formatters\TableFormatter;
use Juphyter\Display\Formatters\ThrowableFormatter;
use Juphyter\Display\MimeBundle;
use Juphyter\Display\Registry;
use Juphyter\Tests\Unit\Display\Fixtures\HtmlOnly;
use Juphyter\Tests\Unit\Display\Fixtures\Point;
use Juphyter\Tests\Unit\Display\Fixtures\Spy;

beforeEach(function () {
    Spy::reset();
});

afterEach(function () {
    Registry::setCurrent(null);
    Registry::publishUsing(null);
});

describe('dispatch order', function () {
    it('ships the formatters in the documented order', function () {
        expect(array_map(get_class(...), Registry::default()->formatters()))->toBe([
            MimeBundleFormatter::class,
            TableFormatter::class,
            ChartFormatter::class,
            HtmlFormatter::class,
            ThrowableFormatter::class,
            ScalarFormatter::class,
            ArrayFormatter::class,
            ObjectFormatter::class,
        ]);
    });

    it('takes the first formatter that supports the value', function () {
        $registry = new Registry([new Spy(1, 'first'), new Spy(1, 'second')]);

        expect($registry->format(1)->get(MimeBundle::PLAIN))->toBe('first');
    });

    it('stops asking once one has claimed the value', function () {
        (new Registry([new Spy('x', 'first'), new Spy('x', 'second')]))->format('x');

        expect(Spy::$asked)->toBe(['first']);
    });

    it('asks them in order until one claims the value', function () {
        (new Registry([new Spy('a', 'first'), new Spy('b', 'second')]))->format('b');

        expect(Spy::$asked)->toBe(['first', 'second']);
    });

    it('does not merge the bundles of two formatters', function () {
        $registry = new Registry([new Spy(1, 'claims'), new HtmlOnly]);

        expect($registry->format(1)->types())->toBe([MimeBundle::PLAIN]);
    });
});

describe('composition', function () {
    it('puts prepended formatters first', function () {
        $extra = new Spy('x', 'extra');

        expect(Registry::default()->prepend($extra)->formatters()[0])->toBe($extra);
    });

    it('puts appended formatters last', function () {
        $extra = new Spy('x', 'extra');
        $formatters = Registry::default()->append($extra)->formatters();

        expect(end($formatters))->toBe($extra);
    });

    it('leaves the registry it was built from alone', function () {
        $registry = Registry::default();
        $registry->prepend(new Spy('x', 'extra'));

        expect($registry->formatters()[0])->toBeInstanceOf(MimeBundleFormatter::class);
    });
});

describe('text/plain', function () {
    it('is on every bundle the registry returns', function (mixed $value) {
        expect(Registry::default()->format($value)->has(MimeBundle::PLAIN))->toBeTrue();
    })->with([
        [null],
        [false],
        [0],
        [1.5],
        [''],
        [[]],
        [[1, 2, 3]],
        [['a' => ['b' => 'c']]],
        fn () => new stdClass,
        fn () => new Point,
        fn () => new RuntimeException('boom'),
        fn () => fn () => 1,
    ]);

    it('is filled in when a formatter left it out', function () {
        $bundle = (new Registry([new HtmlOnly]))->format('a value');

        expect($bundle->types())->toBe([MimeBundle::HTML, MimeBundle::PLAIN])
            ->and($bundle->get(MimeBundle::PLAIN))->toBe("'a value'");
    });

    it('is the whole bundle for a value nothing claimed', function () {
        $handle = fopen('php://memory', 'r+');
        $bundle = (new Registry([]))->format($handle);

        expect($bundle->types())->toBe([MimeBundle::PLAIN])
            ->and($bundle->get(MimeBundle::PLAIN))->toBe('resource(stream)');

        fclose($handle);
    });
});

describe('the current registry', function () {
    it('defaults to the shipped formatters', function () {
        expect(array_map(get_class(...), Registry::current()->formatters()))
            ->toBe(array_map(get_class(...), Registry::default()->formatters()));
    });

    it('can be replaced, and restored with null', function () {
        $replacement = new Registry([new ScalarFormatter]);
        Registry::setCurrent($replacement);

        expect(Registry::current())->toBe($replacement);

        Registry::setCurrent(null);

        expect(Registry::current())->not->toBe($replacement)
            ->and(Registry::current()->formatters())->toHaveCount(8);
    });
});
