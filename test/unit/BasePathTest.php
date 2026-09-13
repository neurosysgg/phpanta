<?php

declare(strict_types=1);

namespace Phpanta\Test\Unit;

use Phpanta\Tool\Cli\UsageException;
use Phpanta\Tool\Export\BasePath;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A static export moves every address under the path it is served at, and then checks, with a real
 * parser, that nothing was missed.
 *
 * No `#[CoversClass]`, like every other test of the tooling.
 */
final class BasePathTest extends TestCase
{
    /**
     * @return array<string, array{string}>
     */
    public static function malformed(): array
    {
        return [
            'no leading slash'  => ['phpanta/'],
            'no trailing slash' => ['/phpanta'],
            'another host'      => ['//phpanta/'],
            'a space'           => ['/php anta/'],
            'empty'             => [''],
        ];
    }

    /**
     * @param string $path
     * @return void
     */
    #[DataProvider('malformed')]
    public function testAPathThatIsNotPlainSegmentsIsRefused(string $path): void
    {
        $this->expectException(UsageException::class);

        (void) new BasePath($path);
    }

    /**
     * Every attribute value from the root gets the base; another host, a scheme, a fragment and a
     * relative path are left as they are.
     *
     * @return void
     */
    public function testTheBaseGoesInFrontOfEveryAddressFromTheRoot(): void
    {
        $base = new BasePath('/phpanta/');

        self::assertSame(
            '<a href="/phpanta/guide">g</a><a href="/phpanta/">home</a>'
            . '<script type="module" src="/phpanta/assets/js/v-0123abcd/main.js"></script>'
            . '<a href="//example.org/x">x</a><a href="https://example.org/">y</a>'
            . '<a href="#top">t</a><img src="cover.png">',
            $base->html(
                '<a href="/guide">g</a><a href="/">home</a>'
                . '<script type="module" src="/assets/js/v-0123abcd/main.js"></script>'
                . '<a href="//example.org/x">x</a><a href="https://example.org/">y</a>'
                . '<a href="#top">t</a><img src="cover.png">',
            ),
        );
    }

    /**
     * A stylesheet's `url()`s move too, however they are quoted.
     *
     * @return void
     */
    public function testTheBaseGoesInFrontOfEveryStylesheetUrlFromTheRoot(): void
    {
        self::assertSame(
            'a{background:url(/phpanta/a.svg)}b{src:url("/phpanta/b.woff2")}c{x:url(\'/phpanta/c\')}'
            . 'd{x:url(//cdn/d)}e{x:url(data:image/png;base64,AA)}',
            new BasePath('/phpanta/')->css(
                'a{background:url(/a.svg)}b{src:url("/b.woff2")}c{x:url(\'/c\')}'
                . 'd{x:url(//cdn/d)}e{x:url(data:image/png;base64,AA)}',
            ),
        );
    }

    /**
     * At the root there is nothing to move.
     *
     * @return void
     */
    public function testAtTheRootNothingMoves(): void
    {
        $base = new BasePath('/');

        self::assertSame('<a href="/guide">g</a>', $base->html('<a href="/guide">g</a>'));
        self::assertSame('a{b:url(/c)}', $base->css('a{b:url(/c)}'));
    }

    /**
     * The check reads what the rewrite's one shape cannot reach — a second `srcset` candidate, a
     * `url()` in a `style` attribute or a `<style>` element — and nothing from another host.
     *
     * @return void
     */
    public function testTheCheckFindsEveryAddressFromTheRoot(): void
    {
        $base      = new BasePath('/phpanta/');
        $addresses = $base->addresses(
            '<!DOCTYPE html><html><head><style>h1{background:url(/in-style.png)}</style></head><body>'
            . '<a href="/phpanta/guide">g</a><a href="//example.org/">x</a>'
            . '<img srcset="/phpanta/one.png 1x, /two.png 2x">'
            . '<div style="background:url(\'/in-attribute.png\')"></div>'
            . '</body></html>',
        )->toValues();

        sort($addresses);

        self::assertSame(
            ['/in-attribute.png', '/in-style.png', '/phpanta/guide', '/phpanta/one.png', '/two.png'],
            $addresses,
        );
        self::assertSame(['/a.svg'], $base->stylesheetAddresses('x{y:url(/a.svg)}z{w:url(//cdn/b)}')->toValues());
    }

    /**
     * An address is either under the base — and then a path within the export — or it was missed.
     *
     * @return void
     */
    public function testAnAddressIsUnderTheBaseOrMissed(): void
    {
        $base = new BasePath('/phpanta/');

        self::assertFalse($base->lacksBase('/phpanta/guide'));
        self::assertTrue($base->lacksBase('/guide'));
        self::assertFalse($base->lacksBase('//example.org/'));
        self::assertSame('guide', $base->withinExport('/phpanta/guide'));
        self::assertSame('', $base->withinExport('/phpanta/'));
    }
}
