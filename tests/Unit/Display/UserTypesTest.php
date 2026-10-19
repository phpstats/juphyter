<?php

declare(strict_types=1);

use Juphyter\Display\Formatters\HtmlFormatter;
use Juphyter\Display\Formatters\MimeBundleFormatter;
use Juphyter\Display\Formatters\Reflect;
use Juphyter\Display\MimeBundle;
use Juphyter\Display\Registry;
use Juphyter\Tests\Unit\Display\Fixtures\HidesItsHooks;
use Juphyter\Tests\Unit\Display\Fixtures\Point;
use Juphyter\Tests\Unit\Display\Fixtures\RendersHtml;
use Juphyter\Tests\Unit\Display\Fixtures\RendersHtmlAndText;
use Juphyter\Tests\Unit\Display\Fixtures\RendersItsOwnBundle;
use Juphyter\Tests\Unit\Display\Fixtures\ReturnsAMimeBundle;
use Juphyter\Tests\Unit\Display\Fixtures\ReturnsRubbish;

describe('toMimeBundle()', function () {
    it('takes precedence over every built-in formatter', function () {
        $bundle = Registry::default()->format(new RendersItsOwnBundle);

        expect($bundle->get(MimeBundle::HTML))->toBe('<p>from toMimeBundle</p>')
            ->and($bundle->get(MimeBundle::JSON))->toBe(['from' => 'toMimeBundle']);
    });

    it('is first in the default registry, ahead of toHtml()', function () {
        $formatters = array_map(get_class(...), Registry::default()->formatters());

        expect($formatters[0])->toBe(MimeBundleFormatter::class)
            ->and(array_search(MimeBundleFormatter::class, $formatters, true))
            ->toBeLessThan(array_search(HtmlFormatter::class, $formatters, true));
    });

    it('fills in text/plain from __toString() when the bundle left it out', function () {
        expect((new MimeBundleFormatter)->format(new RendersItsOwnBundle)->get(MimeBundle::PLAIN))
            ->toBe('a bundle of its own');
    });

    it('accepts a MimeBundle as well as a map', function () {
        $bundle = (new MimeBundleFormatter)->format(new ReturnsAMimeBundle);

        expect($bundle->get(MimeBundle::PLAIN))->toBe('built by hand')
            ->and($bundle->get(MimeBundle::MARKDOWN))->toBe('# built by hand');
    });

    it('falls back to the dump when the return is not a MIME map', function () {
        $bundle = (new MimeBundleFormatter)->format(new ReturnsRubbish);

        expect($bundle->types())->toBe([MimeBundle::PLAIN])
            ->and($bundle->get(MimeBundle::PLAIN))->toBe(ReturnsRubbish::class.' {}');
    });
});

describe('toHtml()', function () {
    it('becomes the text/html of the bundle', function () {
        $bundle = Registry::default()->format(new RendersHtml);

        expect($bundle->get(MimeBundle::HTML))->toBe('<b>a & b</b>')
            ->and($bundle->has(MimeBundle::PLAIN))->toBeTrue();
    });

    it('takes text/plain from __toString() when the class has one', function () {
        expect(Registry::default()->format(new RendersHtmlAndText)->get(MimeBundle::PLAIN))
            ->toBe('rendered');
    });

    it('takes text/plain from the dump when the class has no text form', function () {
        expect(Registry::default()->format(new RendersHtml)->get(MimeBundle::PLAIN))
            ->toBe(RendersHtml::class.' {}');
    });
});

describe('what does not count as a hook', function () {
    it('ignores a static toMimeBundle() and a toHtml() that needs an argument', function () {
        $bundle = Registry::default()->format(new HidesItsHooks);

        expect($bundle->types())->toBe([MimeBundle::PLAIN])
            ->and($bundle->get(MimeBundle::PLAIN))->toBe(HidesItsHooks::class.' {}');
    });

    it('asks for a public, static-free, argument-free method', function () {
        expect(Reflect::declares(new RendersHtml, 'toHtml'))->toBeTrue()
            ->and(Reflect::declares(new HidesItsHooks, 'toHtml'))->toBeFalse()
            ->and(Reflect::declares(new HidesItsHooks, 'toMimeBundle'))->toBeFalse()
            ->and(Reflect::declares(new Point, 'toHtml'))->toBeFalse()
            ->and(Reflect::declares('RendersHtml', 'toHtml'))->toBeFalse()
            ->and(Reflect::declares(null, 'toHtml'))->toBeFalse();
    });
});

describe('matching by name', function () {
    it('never reports a class name string as an instance', function () {
        expect(Reflect::isA(ArrayObject::class, 'ArrayAccess'))->toBeFalse()
            ->and(Reflect::isA(new ArrayObject, 'ArrayAccess'))->toBeTrue();
    });

    it('answers false for a class no installed package declares', function () {
        expect(Reflect::isA(new Point, 'Nothing\\At\\All'))->toBeFalse();
    });
});
