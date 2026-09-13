<?php

declare(strict_types=1);

namespace Phpanta;

use Phpanta\Controller\Controller;
use Phpanta\Controller\Layered;
use Phpanta\Controller\UnroutedController;
use Phpanta\Http\Allow;
use Phpanta\Http\Header;
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
                return $route->accepts($request->method())
                    ? Layered::around($route->layers(), $route->createController($params))->handle($request)
                    : self::refuse($request);
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
     * The `Allow` is always the read-only set, never the matched route's own. A site's routes have
     * no other set to name; the API has one it must not name, because `Allow: GET, HEAD, POST` on
     * `/api` announces the endpoint that exists to be unannounceable — so it never reaches here
     * at all, having {@link \Phpanta\Support\MethodPolicy::Delegated} instead.
     *
     * @param Request $request
     * @return PlainTextResponse
     */
    private static function refuse(Request $request): PlainTextResponse
    {
        return new PlainTextResponse(
            HttpStatusCode::MethodNotAllowed,
            UnroutedController::refusal($request->language()),
            new Collection(Header::class)->with(new Header(ResponseHeader::Allow, Allow::readOnly())),
        );
    }
}
