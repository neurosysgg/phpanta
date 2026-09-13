<?php

declare(strict_types=1);

namespace Phpanta\Test\Unit;

use Phpanta\App;
use Phpanta\Exception\RouteException;
use Phpanta\Support\ApiPath;
use Phpanta\Support\Route;
use Phpanta\Test\TestRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A route's pattern, compiled: what its static parts match, and what a match hands back — and the
 * other direction, `to()`, which fills a pattern in.
 */
final class RouteTest extends TestCase
{
    // ───────────────────────── matching ─────────────────────────

    /**
     * @return void
     */
    public function testAStaticPatternMatchesExactlyAndCapturesNothing(): void
    {
        $guide = self::route(ExportFixturePath::Guide);

        self::assertSame([], $guide->matches('/guide'));
        self::assertFalse($guide->matches('/guide/more'));
        self::assertFalse($guide->matches('/guid'));
        self::assertFalse($guide->matches('/'));
    }

    /**
     * @return void
     */
    public function testAPlaceholderCapturesOneSegment(): void
    {
        $page = self::route(ExportFixturePath::Page);

        self::assertSame(['one'], $page->matches('/pages/one'));
        self::assertSame(['hello-world'], $page->matches('/pages/hello-world'));
    }

    /**
     * @return void
     */
    public function testAPlaceholderDoesNotSpanASlash(): void
    {
        self::assertFalse(self::route(ExportFixturePath::Page)->matches('/pages/one/two'));
    }

    /**
     * @return void
     */
    public function testAnEmptySegmentDoesNotMatchAPlaceholder(): void
    {
        self::assertFalse(self::route(ExportFixturePath::Page)->matches('/pages/'));
    }

    /**
     * @return void
     */
    public function testSeveralPlaceholdersCaptureInOrder(): void
    {
        self::assertSame(['left', 'right'], self::route(ExportFixturePath::Pair)->matches('/pairs/left/right'));
    }

    /**
     * The factory is handed what the match captured, in order.
     *
     * @return void
     */
    public function testTheFactoryReceivesTheCapturedValues(): void
    {
        $route      = new Route(ExportFixturePath::Pair, EchoController::factory());
        $request    = TestRequest::get('/pairs/left/right')->request();
        $controller = $route->createController($route->matches('/pairs/left/right') ?: []);

        self::assertInstanceOf(EchoController::class, $controller);
        self::assertSame('left|right GET', $controller->handle($request)->answer($request)->body());
    }

    /**
     * A placeholder matches anything at all between two slashes, a malformed target included.
     *
     * {@link \Phpanta\Http\Request::normalisePath()} hands a target it cannot parse through
     * verbatim, and a docblock there once argued that was safe because no pattern would match it.
     * `{slug}` compiles to `([^/]+)`, so a raw `"` matches like any other byte — and with the whole
     * target as the fallback, a query string arrives inside the captured value. A value that
     * reaches a header has to be made safe where it is written; the pattern does not do it.
     *
     * @return void
     */
    public function testAMalformedTargetStillMatchesAPlaceholderRoute(): void
    {
        $page = self::route(ExportFixturePath::Page);

        self::assertSame(['a"b'], $page->matches('/pages/a"b'));
        self::assertSame(['x?a=1'], $page->matches('/pages/x?a=1'));
    }

    // ───────────────────────── the API's pattern ─────────────────────────

    /**
     * The API's pattern is plain segments and placeholders, like every address a site should name.
     *
     * @return void
     */
    public function testTheApiPatternIsPlainSegmentsAndPlaceholders(): void
    {
        foreach (ApiPath::cases() as $path) {
            self::assertMatchesRegularExpression(
                '#^(/|(/[\w-]+|/\{\w+\})+)$#',
                $path->value,
                "{$path->name} contains something that is not a plain segment or a {placeholder}.",
            );
        }
    }

    /**
     * Every depth short of `/api`'s four segments, and one past it, matches nothing at all, which
     * is what makes `/api` and everything under it fall through to the same 404 as any other
     * address that is not there — a property of the pattern rather than of a check anywhere.
     *
     * @param string $path
     * @return void
     */
    #[DataProvider('apiDepthProvider')]
    public function testAnAddressUnderApiOfAnyOtherDepthMatchesNoRoute(string $path): void
    {
        foreach (App::current()->routeTable() as $route) {
            self::assertFalse($route->matches($path), "$path unexpectedly matched a route");
        }
    }

    /**
     * The same depths under a second service cost nothing to keep in line: the pattern is four
     * segments whatever the first of them says.
     *
     * @return iterable<array{string}>
     */
    public static function apiDepthProvider(): iterable
    {
        yield ['/api'];
        yield ['/api/update'];
        yield ['/api/update/v1'];
        yield ['/api/update/v1/patch/extra'];
        yield ['/api/health'];
        yield ['/api/health/v1'];
        yield ['/api/health/v1/report/extra'];
    }

    // ───────────────────────── to() ─────────────────────────

    /**
     * @return void
     */
    public function testToFillsPlaceholdersInOrder(): void
    {
        self::assertSame('/', ExportFixturePath::Home->to());
        self::assertSame('/guide', ExportFixturePath::Guide->to());
        self::assertSame('/pages/one', ExportFixturePath::Page->to('one'));
        self::assertSame('/pairs/left/right', ExportFixturePath::Pair->to('left', 'right'));
    }

    /**
     * The mistake an arrow function makes here: `fn()` captures by value, so a shift inside it
     * leaves the outer list untouched and every placeholder is filled with the first value — an
     * address that is well formed, matches a route, and is the wrong page.
     *
     * @return void
     */
    public function testToDoesNotRepeatTheFirstValue(): void
    {
        self::assertSame('/pairs/one/two', ExportFixturePath::Pair->to('one', 'two'));
    }

    /**
     * @return void
     */
    public function testToEncodesEachValueAsOneSegment(): void
    {
        self::assertSame('/pages/hello%20world', ExportFixturePath::Page->to('hello world'));
        self::assertSame('/pages/a%2Fb', ExportFixturePath::Page->to('a/b'));
    }

    /**
     * @return void
     */
    public function testToRefusesTooFewValues(): void
    {
        $this->expectException(RouteException::class);
        $this->expectExceptionMessage('ExportFixturePath::Pair takes 2 value(s)');

        (void) ExportFixturePath::Pair->to('one');
    }

    /**
     * @return void
     */
    public function testToRefusesTooManyValues(): void
    {
        $this->expectException(RouteException::class);
        $this->expectExceptionMessage('ExportFixturePath::Home takes 0 value(s)');

        (void) ExportFixturePath::Home->to('one');
    }

    // ───────────────────────── what a static part reads ─────────────────────────

    /**
     * A static part is quoted, so a `.` in it is a dot and not any character at all.
     *
     * @return void
     */
    public function testAStaticPartMatchesItselfAndNothingElse(): void
    {
        $feed = self::route(RoutePatternFixture::Feed);

        self::assertSame([], $feed->matches('/feed.xml'));
        self::assertFalse($feed->matches('/feedxxml'));

        $item = self::route(RoutePatternFixture::Item);

        self::assertSame(['note'], $item->matches('/items/note.json'));
        self::assertFalse($item->matches('/items/note-json'));
    }

    /**
     * The expression's own delimiter is quoted with the rest, so a pattern holding it cannot end
     * the expression early.
     *
     * @return void
     */
    public function testThePatternsDelimiterIsQuotedToo(): void
    {
        self::assertSame(['x'], self::route(RoutePatternFixture::Delimited)->matches('/a#b/x'));
    }

    /**
     * Whatever `to()` encodes into a segment, a match decodes back out — a space, a slash, a
     * percent sign, and a slug that reads as a number all included.
     *
     * @return void
     */
    public function testAMatchIsTheInverseOfTo(): void
    {
        $item = self::route(RoutePatternFixture::Item);

        foreach (['note', 'a b', 'x/y', '100%', '2024'] as $slug) {
            self::assertSame([$slug], $item->matches(RoutePatternFixture::Item->to($slug)), $slug);
        }
    }

    /**
     * A route on $path whose factory nothing here calls.
     *
     * @param RoutePatternFixture|ExportFixturePath $path
     * @return Route
     */
    private static function route(RoutePatternFixture|ExportFixturePath $path): Route
    {
        return new Route($path, static fn(): null => null);
    }
}
