<?php

declare(strict_types=1);

namespace Phpanta\Test\Unit;

use Phpanta\App;
use Phpanta\Exception\RouteException;
use Phpanta\Support\AdminPath;
use Phpanta\Support\Path;
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

    // ───────────────────────── the admin's patterns ─────────────────────────

    /**
     * The admin's patterns are plain segments and placeholders, like every address a site should
     * name.
     *
     * @return void
     */
    public function testTheAdminPatternsArePlainSegmentsAndPlaceholders(): void
    {
        foreach (AdminPath::cases() as $path) {
            self::assertMatchesRegularExpression(
                '#^(/|(/[\w-]+|/\{\w+(?::\w+)?\})+)$#',
                $path->value,
                "{$path->name} contains something that is not a plain segment or a {placeholder}.",
            );
        }
    }

    /**
     * Nothing at all matches under `/api`, which the admin used to be at — it falls through to the
     * same 404 as any other address that is not there.
     *
     * @param string $path
     * @return void
     */
    #[DataProvider('unadministeredProvider')]
    public function testAnAddressUnderTheOldApiMatchesNoRoute(string $path): void
    {
        foreach (App::current()->routeTable() as $route) {
            self::assertFalse($route->matches($path), "$path unexpectedly matched a route");
        }
    }

    /**
     * Past an action, however deep, is the fifth depth: the action and a path it acts on. Only that
     * route matches, so an address there is the admin's to answer — a verified caller is told the
     * action takes no path, a stranger gets the one answer — and never the site's.
     *
     * @return void
     */
    public function testPastAnActionIsTheFifthDepthAlone(): void
    {
        $subject = App::current()->adminRoutes()->toValues()[4];

        self::assertSame(['update', 'v1', 'patch', 'extra'], $subject->matches('/admin/update/v1/patch/extra'));
        self::assertSame(
            ['machine', 'v1', 'files', 'home/a b/c.flac'],
            $subject->matches('/admin/machine/v1/files/home/a%20b/c.flac'),
        );

        foreach (['/admin/update/v1/patch/extra', '/admin/health/v1/report/a/b/c'] as $path) {
            $matched = App::current()->routeTable()
                ->where(static fn(Route $route): bool => $route->matches($path) !== false)
                ->map(static fn(Route $route): Path => $route->path());

            self::assertSame([$subject->path()], $matched->toValues(), $path);
        }

        self::assertFalse($subject->matches('/admin/machine/v1/files/a//b'), 'an empty segment is no path');
    }

    /**
     * @return iterable<array{string}>
     */
    public static function unadministeredProvider(): iterable
    {
        yield ['/api'];
        yield ['/api/update'];
        yield ['/api/update/v1'];
        yield ['/api/update/v1/patch'];
        yield ['/api/health/v1/report'];
    }

    /**
     * Every depth of the admin is an address, each matched by its own pattern.
     *
     * @return void
     */
    public function testEveryDepthOfTheAdminMatches(): void
    {
        $routes = App::current()->adminRoutes()->toValues();

        self::assertSame([], $routes[0]->matches('/admin'));
        self::assertSame(['update'], $routes[1]->matches('/admin/update'));
        self::assertSame(['update', 'v1'], $routes[2]->matches('/admin/update/v1'));
        self::assertSame(['update', 'v1', 'patch'], $routes[3]->matches('/admin/update/v1/patch'));
        self::assertSame(['machine', 'v1', 'files', 'etc'], $routes[4]->matches('/admin/machine/v1/files/etc'));
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
