<?php

declare(strict_types=1);

namespace Phpanta\Test\Unit;

use Phpanta\Support\Route;
use PHPUnit\Framework\TestCase;

/**
 * A route's pattern, compiled: what its static parts match, and what a match hands back.
 */
final class RouteTest extends TestCase
{
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

        self::assertSame(['ill'], $item->matches('/items/ill.json'));
        self::assertFalse($item->matches('/items/ill-json'));
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

        foreach (['ill', 'a b', 'x/y', '100%', '2024'] as $slug) {
            self::assertSame([$slug], $item->matches(RoutePatternFixture::Item->to($slug)), $slug);
        }
    }

    /**
     * A route on $path whose factory nothing here calls.
     *
     * @param RoutePatternFixture $path
     * @return Route
     */
    private static function route(RoutePatternFixture $path): Route
    {
        return new Route($path, static fn(): null => null);
    }
}
