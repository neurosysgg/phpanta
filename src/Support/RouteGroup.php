<?php

declare(strict_types=1);

namespace Phpanta\Support;

use NoDiscard;
use Phpanta\Controller\Layer;

/**
 * The RouteGroup class. Layers written once for several routes.
 *
 * ```php
 * ->with(...RouteGroup::through(new AdminGate())->routes(
 *     new Route(SitePath::Stats, fn() => new StatsController()),
 *     new Route(SitePath::Logs, fn() => new LogsController()),
 * ))
 * ```
 *
 * **A group has no prefix, deliberately.** Every address is a {@link Path} case, and a link is
 * `Path::X->to(…)` — the case's value is the whole pattern, and it is what both the router and every
 * view read. A prefix held by a group would make a case's value only the tail of its address, and
 * every link built from it wrong in a way that still resolves somewhere. What a group can share
 * without that cost is what stands around its routes.
 */
final readonly class RouteGroup
{
    /**
     * @param Collection<Layer> $layers
     */
    private function __construct(private Collection $layers) {}

    /**
     * A group whose routes all stand behind $layers, outermost first.
     *
     * @param Layer ...$layers
     * @return self
     */
    public static function through(Layer ...$layers): self
    {
        return new self(new Collection(Layer::class)->with(...$layers));
    }

    /**
     * $routes, each with this group's layers after any of its own.
     *
     * @param Route ...$routes
     * @return Collection<Route>
     */
    #[NoDiscard('routes() builds the group and registers nothing; a call whose result goes nowhere routed nothing')]
    public function routes(Route ...$routes): Collection
    {
        $grouped = new Collection(Route::class);

        foreach ($routes as $route) {
            $grouped = $grouped->with($route->through(...$this->layers));
        }

        return $grouped;
    }
}
