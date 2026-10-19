<?php

declare(strict_types=1);

use Juphyter\Display\MimeBundle;

describe('construction', function () {
    it('starts empty', function () {
        $bundle = new MimeBundle;

        expect($bundle->isEmpty())->toBeTrue()
            ->and($bundle->count())->toBe(0)
            ->and($bundle->types())->toBe([])
            ->and($bundle->data())->toBe([])
            ->and($bundle->metadata())->toBe([]);
    });

    it('carries text/plain from plain()', function () {
        $bundle = MimeBundle::plain('hello');

        expect($bundle->has(MimeBundle::PLAIN))->toBeTrue()
            ->and($bundle->get(MimeBundle::PLAIN))->toBe('hello')
            ->and($bundle->count())->toBe(1);
    });

    it('answers null for a type it does not carry', function () {
        expect(MimeBundle::plain('hello')->get(MimeBundle::HTML))->toBeNull()
            ->and(MimeBundle::plain('hello')->has(MimeBundle::HTML))->toBeFalse();
    });
});

describe('immutability', function () {
    it('leaves the original alone when a type is added', function () {
        $plain = MimeBundle::plain('hello');
        $rich = $plain->with(MimeBundle::HTML, '<p>hello</p>');

        expect($plain->types())->toBe([MimeBundle::PLAIN])
            ->and($rich->types())->toBe([MimeBundle::PLAIN, MimeBundle::HTML]);
    });

    it('leaves the original alone when a type is removed', function () {
        $rich = MimeBundle::plain('hello')->with(MimeBundle::HTML, '<p>hello</p>');
        $thin = $rich->without(MimeBundle::HTML);

        expect($rich->has(MimeBundle::HTML))->toBeTrue()
            ->and($thin->has(MimeBundle::HTML))->toBeFalse();
    });
});

describe('order', function () {
    it('keeps the types in insertion order', function () {
        $bundle = (new MimeBundle)
            ->with(MimeBundle::VEGALITE, ['mark' => 'point'])
            ->with(MimeBundle::HTML, '<div></div>')
            ->with(MimeBundle::SVG, '<svg></svg>')
            ->with(MimeBundle::PLAIN, 'a chart');

        expect($bundle->types())->toBe([
            MimeBundle::VEGALITE,
            MimeBundle::HTML,
            MimeBundle::SVG,
            MimeBundle::PLAIN,
        ]);
    });

    it('keeps a type in place when it is set again', function () {
        $bundle = MimeBundle::plain('first')
            ->with(MimeBundle::HTML, '<p>first</p>')
            ->with(MimeBundle::PLAIN, 'second');

        expect($bundle->types())->toBe([MimeBundle::PLAIN, MimeBundle::HTML])
            ->and($bundle->get(MimeBundle::PLAIN))->toBe('second');
    });
});

describe('data', function () {
    it('carries a decoded structure, not only strings', function () {
        $spec = ['$schema' => 'https://vega.github.io/schema/vega-lite/v6.json', 'mark' => 'point'];

        expect((new MimeBundle)->with(MimeBundle::VEGALITE, $spec)->get(MimeBundle::VEGALITE))->toBe($spec);
    });

    it('keeps metadata per type and only when given', function () {
        $bundle = MimeBundle::plain('a chart')
            ->with(MimeBundle::SVG, '<svg></svg>', ['width' => 400])
            ->with(MimeBundle::HTML, '<div></div>');

        expect($bundle->metadata())->toBe([MimeBundle::SVG => ['width' => 400]]);
    });

    it('drops the metadata along with the type', function () {
        $bundle = MimeBundle::plain('a chart')->with(MimeBundle::SVG, '<svg></svg>', ['width' => 400]);

        expect($bundle->without(MimeBundle::SVG)->metadata())->toBe([]);
    });
});
