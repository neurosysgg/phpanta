<?php

declare(strict_types=1);

namespace Phpanta\Test\Unit;

use Closure;
use Phpanta\Controller\UnroutedController;
use Phpanta\Support\MethodPolicy;
use Phpanta\Support\Route;
use PHPUnit\Framework\TestCase;

/**
 * Which pages a static export writes for a route — the route's to say, and nothing by guesswork.
 */
final class RouteExportTest extends TestCase
{
    /**
     * A read-only route that is one address exports that address.
     *
     * @return void
     */
    public function testARouteWithoutPlaceholdersExportsItsOwnPath(): void
    {
        self::assertSame(['/'], self::route(ExportFixturePath::Home)->exportedPaths()->toValues());
        self::assertSame(['/guide'], self::route(ExportFixturePath::Guide)->exportedPaths()->toValues());
    }

    /**
     * Which values a placeholder takes is the site's knowledge, so without being told there are none.
     *
     * @return void
     */
    public function testARouteWithPlaceholdersExportsNothingUntilTold(): void
    {
        self::assertTrue(self::route(ExportFixturePath::Page)->exportedPaths()->isEmpty());
    }

    /**
     * Told, it exports one page per value — a string for one placeholder, a list for several —
     * filled the way a view fills a link.
     *
     * @return void
     */
    public function testThePagesOfAPlaceholderRouteAreTheValuesItIsGiven(): void
    {
        $pages = self::route(ExportFixturePath::Page, exports: static fn(): array => ['intro', 'rules']);
        $pairs = self::route(ExportFixturePath::Pair, exports: static fn(): array => [['a', 'b'], ['c', 'd']]);

        self::assertSame(['/pages/intro', '/pages/rules'], $pages->exportedPaths()->toValues());
        self::assertSame(['/pairs/a/b', '/pairs/c/d'], $pairs->exportedPaths()->toValues());
    }

    /**
     * A route that is one address can still be kept out of an export.
     *
     * @return void
     */
    public function testAnEmptyAnswerKeepsARouteOut(): void
    {
        $route = self::route(ExportFixturePath::Guide, exports: static fn(): array => []);

        self::assertTrue($route->exportedPaths()->isEmpty());
    }

    /**
     * A route the router forms no opinion about is never a page, whatever it is told.
     *
     * @return void
     */
    public function testADelegatedRouteIsNeverAPage(): void
    {
        $route = self::route(ExportFixturePath::Guide, MethodPolicy::Delegated, static fn(): array => ['x']);

        self::assertTrue($route->exportedPaths()->isEmpty());
    }

    /**
     * The export asks a route for its pattern, to say which route gave it a path it cannot match.
     *
     * @return void
     */
    public function testARouteAnswersItsPath(): void
    {
        self::assertSame(ExportFixturePath::Pair, self::route(ExportFixturePath::Pair)->path());
    }

    /**
     * @param ExportFixturePath $path
     * @param MethodPolicy      $methods
     * @param Closure|null     $exports
     * @return Route
     */
    private static function route(
        ExportFixturePath $path,
        MethodPolicy $methods = MethodPolicy::ReadOnly,
        ?Closure $exports = null,
    ): Route {
        return new Route($path, static fn(): UnroutedController => new UnroutedController(), $methods, $exports);
    }
}
