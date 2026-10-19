<?php

declare(strict_types=1);

use Juphyter\Display\Formatters\Dumper;
use Juphyter\Tests\Unit\Display\Fixtures\FakeType;
use Juphyter\Tests\Unit\Display\Fixtures\Point;

describe('scalars', function () {
    it('renders null, booleans and integers', function () {
        expect(Dumper::plain(null))->toBe('null')
            ->and(Dumper::plain(true))->toBe('true')
            ->and(Dumper::plain(false))->toBe('false')
            ->and(Dumper::plain(0))->toBe('0')
            ->and(Dumper::plain(-42))->toBe('-42');
    });

    it('keeps a float reading as a float', function () {
        expect(Dumper::plain(1.0))->toBe('1.0')
            ->and(Dumper::plain(39.1))->toBe('39.1')
            ->and(Dumper::plain(-0.5))->toBe('-0.5');
    });

    it('names the floats that have no digits', function () {
        expect(Dumper::plain(NAN))->toBe('NAN')
            ->and(Dumper::plain(INF))->toBe('INF')
            ->and(Dumper::plain(-INF))->toBe('-INF');
    });

    it('quotes a string so it cannot be read as a number', function () {
        expect(Dumper::plain('1'))->toBe("'1'")
            ->and(Dumper::plain('Adelie'))->toBe("'Adelie'")
            ->and(Dumper::plain(''))->toBe("''");
    });

    it('escapes quotes, backslashes and newlines in a string', function () {
        expect(Dumper::plain("it's"))->toBe("'it\\'s'")
            ->and(Dumper::plain('a\\b'))->toBe("'a\\\\b'")
            ->and(Dumper::plain("one\ntwo"))->toBe("'one\\ntwo'");
    });

    it('clips a long string', function () {
        $plain = Dumper::plain(str_repeat('x', Dumper::STRING + 10));

        expect($plain)->toEndWith("…'")
            ->and(mb_strlen($plain))->toBe(Dumper::STRING + 3);
    });
});

describe('arrays', function () {
    it('renders a list on one line', function () {
        expect(Dumper::plain([1, 2, 3]))->toBe('[1, 2, 3]')
            ->and(Dumper::plain([]))->toBe('[]');
    });

    it('renders keys for a map', function () {
        expect(Dumper::plain(['a' => 1, 'b' => true]))->toBe("['a' => 1, 'b' => true]")
            ->and(Dumper::plain([3 => 'x', 7 => 'y']))->toBe("[3 => 'x', 7 => 'y']");
    });

    it('nests while it fits', function () {
        expect(Dumper::plain(['rows' => [['x' => 1]]]))->toBe("['rows' => [['x' => 1]]]");
    });

    it('stops at the depth limit', function () {
        $deep = [[[['too far']]]];

        expect(Dumper::plain($deep))->toBe('[[[[…]]]]');
    });

    it('counts the entries past the item limit', function () {
        $wide = range(1, Dumper::ITEMS + 5);

        expect(Dumper::plain($wide))->toContain('… 5 more');
    });

    it('wraps a long list of scalars instead of giving each a line', function () {
        $lines = explode("\n", Dumper::plain(range(1, 40)));

        expect($lines[0])->toBe('[')
            ->and($lines[1])->toStartWith('    1, 2, 3, 4,')
            ->and(end($lines))->toBe(']')
            ->and($lines)->toHaveCount(4);

        foreach (array_slice($lines, 1, -1) as $line) {
            expect(mb_strlen($line))->toBeLessThanOrEqual(Dumper::WIDTH);
        }
    });

    it('still gives a nested entry its own line', function () {
        $value = array_map(fn (int $i): array => ['n' => $i, 'label' => 'row '.$i], range(1, 8));
        $lines = explode("\n", Dumper::plain($value));

        expect($lines)->toHaveCount(10)
            ->and($lines[1])->toBe("    ['n' => 1, 'label' => 'row 1'],");
    });

    it('expands one entry per line when the line would be too long', function () {
        $value = ['label' => str_repeat('a', 60), 'other' => str_repeat('b', 60)];

        expect(Dumper::plain($value))->toBe(
            "[\n"
            ."    'label' => '".str_repeat('a', 60)."',\n"
            ."    'other' => '".str_repeat('b', 60)."',\n"
            .']'
        );
    });

    it('keeps an inner entry on one line while that one fits', function () {
        $value = ['first' => [1, 2, 3], 'second' => str_repeat('b', 90)];
        $lines = explode("\n", Dumper::plain($value));

        expect($lines[1])->toBe("    'first' => [1, 2, 3],");
    });

    it('never widens past the budget by indenting', function () {
        $value = ['outer' => ['inner' => array_fill(0, 12, 'aaaaaaaa')]];

        foreach (explode("\n", Dumper::plain($value)) as $line) {
            expect(mb_strlen($line))->toBeLessThanOrEqual(Dumper::WIDTH + 4);
        }
    });
});

describe('objects', function () {
    it('names an enum case', function () {
        expect(Dumper::plain(FakeType::Float))->toBe(FakeType::class.'::Float');
    });

    it('names a closure without trying to describe it', function () {
        expect(Dumper::plain(fn () => 1))->toBe('Closure');
    });

    it('renders a date as a date', function () {
        expect(Dumper::plain(new DateTimeImmutable('2024-03-04 05:06:07')))
            ->toBe(DateTimeImmutable::class.' 2024-03-04 05:06:07');
    });

    it('lists the public properties of an object', function () {
        expect(Dumper::plain(new Point(1, 2.5, 'north')))
            ->toBe(Point::class." {x: 1, y: 2.5, label: 'north'}");
    });

    it('says so when an object has no public properties', function () {
        expect(Dumper::plain(new stdClass))->toBe('stdClass {}');
    });

    it('stops at the depth limit', function () {
        $nested = new stdClass;
        $nested->a = new stdClass;
        $nested->a->b = new stdClass;
        $nested->a->b->c = new stdClass;
        $nested->a->b->c->d = 1;

        expect(Dumper::plain($nested))->toContain('stdClass {…}');
    });
});

describe('everything else', function () {
    it('names a resource by its type', function () {
        $handle = fopen('php://memory', 'r+');

        expect(Dumper::plain($handle))->toBe('resource(stream)');

        fclose($handle);
    });
});
