<?php

declare(strict_types=1);

namespace Phpanta;

use Phpanta\Controller\Controller;
use Phpanta\Controller\Layered;
use Phpanta\Controller\UnroutedController;
use Phpanta\Http\EmptyResponse;
use Phpanta\Http\Header;
use Phpanta\Http\HttpMethod;
use Phpanta\Http\HttpStatusCode;
use Phpanta\Http\PlainTextResponse;
use Phpanta\Http\Request;
use Phpanta\Http\Response;
use Phpanta\Http\ResponseHeader;
use Phpanta\Support\Collection;
use Phpanta\Support\Route;

/**
 * Maps incoming requests to controllers using a registered Collection<Route>.
 *
 * A {@link Controller} itself, so the app's layers stand around it exactly as a route's stand around
 * that route's controller — see {@link \Phpanta\Controller\Layered}.
 */
readonly class Router implements Controller
{
    /**
     * Constructs an instance of {@link self}.
     * @param Collection<Route> $routes
     */
    public function __construct(private Collection $routes) {}

    /**
     * {@link self::dispatch()}, as a controller.
     *
     * @param Request $request
     * @return Response
     */
    public function handle(Request $request): Response
    {
        return $this->dispatch($request);
    }

    /**
     * Dispatches the given {@link Request} to the appropriate {@link \Phpanta\Controller\Controller}.
     * @param Request $request
     * @return Response
     */
    public function dispatch(Request $request): Response
    {
        // The path is asked first and the method second, because the method question belongs to
        // the route: each Route carries a MethodPolicy. A read-only one answers POST with a 405 and
        // `Allow: GET, HEAD` rather than handling it like a GET; ApiPath::Api delegates the question
        // to its own controller. See docs/security.md. The route's own layers stand around its
        // controller only, and only past the method gate: a refused POST never reaches them.
        foreach ($this->routes as $route) {
            if (($params = $route->matches($request->path())) !== false) {
                if ($route->accepts($request->method())) {
                    return Layered::around($route->layers(), $route->createController($params))->handle($request);
                }

                return $request->method() === HttpMethod::Options
                    ? self::options($route)
                    : self::refuse($request, $route);
            }
        }

        // No route claimed the path, so there is no route's opinion to ask. UnroutedController
        // owns that answer — a 404 for a read verb, a 405 for a write one — and owns it because
        // ApiController has to give the identical one for a request it will not verify.
        return new UnroutedController()->handle($request);
    }

    /**
     * The 405, naming the methods that would have worked.
     *
     * The `Allow` is the matched route's own gate's: the read-only set for every page, and the set a
     * {@link \Phpanta\Support\MethodSet} route names for itself. The API has a set it must not name,
     * because `Allow: GET, HEAD, POST` on `/api` announces the endpoint that exists to be
     * unannounceable — so it never reaches here at all, having
     * {@link \Phpanta\Support\MethodPolicy::Delegated} instead.
     *
     * @param Request $request
     * @param Route   $route
     * @return PlainTextResponse
     */
    private static function refuse(Request $request, Route $route): PlainTextResponse
    {
        return new PlainTextResponse(
            HttpStatusCode::MethodNotAllowed,
            UnroutedController::refusal($request->language()),
            new Collection(Header::class)->with(new Header(ResponseHeader::Allow, $route->allowed())),
        );
    }

    /**
     * What an `OPTIONS` to a route that does not take one itself is answered with: a 204, and the
     * route's methods with `OPTIONS` after them.
     *
     * An answer about the resource, so no body and no `Content-Type`. A route whose controller
     * decides — the API — is never asked, so an unsigned `OPTIONS` there is still the refusal an
     * address that does not exist gets.
     *
     * @param Route $route
     * @return EmptyResponse
     */
    private static function options(Route $route): EmptyResponse
    {
        return new EmptyResponse(
            HttpStatusCode::NoContent,
            new Collection(Header::class)->with(
                new Header(ResponseHeader::Allow, $route->allowed()->with(HttpMethod::Options)),
            ),
        );
    }
}
