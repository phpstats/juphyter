<?php

declare(strict_types=1);

use Juphyter\Display\Formatters\ArrayFormatter;
use Juphyter\Display\Formatters\ObjectFormatter;
use Juphyter\Display\Formatters\ScalarFormatter;
use Juphyter\Display\Formatters\ThrowableFormatter;
use Juphyter\Display\MimeBundle;
use Juphyter\Tests\Unit\Display\Fixtures\Measurement;
use Juphyter\Tests\Unit\Display\Fixtures\Point;

describe('scalars', function () {
    it('supports null and every scalar, and nothing else', function () {
        $formatter = new ScalarFormatter;

        expect($formatter->supports(null))->toBeTrue()
            ->and($formatter->supports(1))->toBeTrue()
            ->and($formatter->supports(1.5))->toBeTrue()
            ->and($formatter->supports(true))->toBeTrue()
            ->and($formatter->supports('x'))->toBeTrue()
            ->and($formatter->supports([1]))->toBeFalse()
            ->and($formatter->supports(new stdClass))->toBeFalse();
    });

    it('carries text/plain and nothing else', function () {
        $bundle = (new ScalarFormatter)->format(42);

        expect($bundle->types())->toBe([MimeBundle::PLAIN])
            ->and($bundle->get(MimeBundle::PLAIN))->toBe('42');
    });
});

describe('arrays', function () {
    it('leaves a flat list as plain text alone', function () {
        $bundle = (new ArrayFormatter)->format([1, 2, 3]);

        expect($bundle->types())->toBe([MimeBundle::PLAIN])
            ->and($bundle->get(MimeBundle::PLAIN))->toBe('[1, 2, 3]');
    });

    it('offers application/json for a nested array', function () {
        $rows = [['x' => 1], ['x' => 2]];
        $bundle = (new ArrayFormatter)->format($rows);

        expect($bundle->types())->toBe([MimeBundle::PLAIN, MimeBundle::JSON])
            ->and($bundle->get(MimeBundle::JSON))->toBe($rows);
    });

    it('leaves out application/json when the array cannot be encoded', function () {
        $handle = fopen('php://memory', 'r+');
        $bundle = (new ArrayFormatter)->format([['handle' => $handle]]);

        expect($bundle->has(MimeBundle::JSON))->toBeFalse();

        fclose($handle);
    });
});

describe('objects', function () {
    it('dumps the public properties of an object as text', function () {
        $bundle = (new ObjectFormatter)->format(new Point(3, 4.5));

        expect($bundle->types())->toBe([MimeBundle::PLAIN])
            ->and($bundle->get(MimeBundle::PLAIN))->toBe(Point::class.' {x: 3, y: 4.5, label: null}');
    });

    it('offers application/json only when the class opted in', function () {
        $bundle = (new ObjectFormatter)->format(new Measurement);

        expect($bundle->get(MimeBundle::JSON))->toBe(['value' => 3.5, 'unit' => 'mm'])
            ->and((new ObjectFormatter)->format(new Point)->has(MimeBundle::JSON))->toBeFalse();
    });
});

describe('throwables', function () {
    it('prints the class, the message and where it came from', function () {
        $line = __LINE__ + 1;
        $error = new TypeError('a weak argument');
        $bundle = (new ThrowableFormatter)->format($error);

        expect($bundle->types())->toBe([MimeBundle::PLAIN])
            ->and($bundle->get(MimeBundle::PLAIN))->toBe(
                "TypeError: a weak argument\n  in ".__FILE__.':'.$line
            );
    });

    it('leaves out the colon when there is no message', function () {
        expect((new ThrowableFormatter)->format(new RuntimeException)->get(MimeBundle::PLAIN))
            ->toStartWith("RuntimeException\n  in ");
    });

    it('supports every throwable, errors included', function () {
        $formatter = new ThrowableFormatter;

        expect($formatter->supports(new RuntimeException))->toBeTrue()
            ->and($formatter->supports(new DivisionByZeroError))->toBeTrue()
            ->and($formatter->supports('RuntimeException'))->toBeFalse();
    });
});
