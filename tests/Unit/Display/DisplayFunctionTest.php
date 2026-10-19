<?php

declare(strict_types=1);

use Juphyter\Display\Formatter;
use Juphyter\Display\MimeBundle;
use Juphyter\Display\Registry;
use Juphyter\Tests\Unit\Display\Fixtures\RendersHtml;

use function Juphyter\display;

/** @return list<MimeBundle> The bundles display() published while $fn ran. */
function published(Closure $fn): array
{
    $bundles = [];
    Registry::publishUsing(function (MimeBundle $bundle) use (&$bundles) {
        $bundles[] = $bundle;
    });

    try {
        $fn();
    } finally {
        Registry::publishUsing(null);
    }

    return $bundles;
}

afterEach(function () {
    Registry::setCurrent(null);
    Registry::publishUsing(null);
});

describe('the exported function', function () {
    it('is loaded by the autoloader, not by an include', function () {
        expect(function_exists('Juphyter\display'))->toBeTrue();
    });

    it('publishes one bundle for one value', function () {
        $bundles = published(fn () => display('hello'));

        expect($bundles)->toHaveCount(1)
            ->and($bundles[0]->get(MimeBundle::PLAIN))->toBe("'hello'");
    });

    it('publishes the whole bundle a formatter built, not only its text', function () {
        $bundles = published(fn () => display(new RendersHtml));

        expect($bundles[0]->get(MimeBundle::HTML))->toBe('<b>a & b</b>');
    });

    it('publishes several values in the order given', function () {
        $bundles = published(fn () => display(1, 'two', [3]));

        expect(array_map(fn (MimeBundle $b) => $b->get(MimeBundle::PLAIN), $bundles))
            ->toBe(['1', "'two'", '[3]']);
    });

    it('formats with the current registry', function () {
        Registry::setCurrent(new Registry([
            new class implements Formatter
            {
                public function supports(mixed $value): bool
                {
                    return true;
                }

                public function format(mixed $value): MimeBundle
                {
                    return MimeBundle::plain('from the replacement');
                }
            },
        ]));

        $bundles = published(fn () => display('hello'));

        expect($bundles[0]->get(MimeBundle::PLAIN))->toBe('from the replacement');
    });
});

describe('with no publisher installed', function () {
    it('writes text/plain to standard output', function () {
        Registry::publishUsing(null);

        ob_start();
        display([1, 2]);
        $written = (string) ob_get_clean();

        expect($written)->toBe("[1, 2]\n");
    });

    it('goes back to that after a publisher is removed', function () {
        published(fn () => display('captured'));

        ob_start();
        display('loose');
        $written = (string) ob_get_clean();

        expect($written)->toBe("'loose'\n");
    });
});
