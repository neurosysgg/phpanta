<?php

declare(strict_types=1);

namespace Phpanta;

use Phpanta\Controller\Controller;
use Phpanta\Controller\Layered;
use Phpanta\Controller\UnroutedController;
use Phpanta\Exception\InputException;
use Phpanta\Exception\TooLargeException;
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
use Phpanta\Text\FrameworkText;

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
        // `Allow: GET, HEAD` rather than handling it like a GET; the admin's routes delegate the
        // question to their controller. See docs/security.md. The route's own layers stand around its
        // controller only, and only past the method gate: a refused POST never reaches them.
        foreach ($this->routes as $route) {
            if (($params = $route->matches($request->path())) !== false) {
                if ($route->accepts($request->method())) {
                    // A controller that asks for input the request did not send readably is
                    // refused here, once, rather than by every page that reads a parameter.
                    try {
                        return Layered::around($route->layers(), $route->createController($params))->handle($request);
                    } catch (TooLargeException) {
                        return self::tooLarge($request);
                    } catch (InputException) {
                        return self::unreadable($request);
                    }
                }

                return $request->method() === HttpMethod::Options
                    ? self::options($route)
                    : self::refuse($request, $route);
            }
        }

        // No route claimed the path, so there is no route's opinion to ask. UnroutedController
        // owns that answer — a 404 for a read verb, a 405 for a write one.
        return new UnroutedController()->handle($request);
    }

    /**
     * The 405, naming the methods that would have worked.
     *
     * The `Allow` is the matched route's own gate's: the read-only set for every page, and the set a
     * {@link \Phpanta\Support\MethodSet} route names for itself. The admin's routes never reach here
     * at all: they are {@link \Phpanta\Support\MethodPolicy::Delegated}, and their controller
     * answers every method itself — a caller it cannot verify the same way at every depth.
     *
     * @param Request $request
     * @param Route   $route
     * @return PlainTextResponse
     */
    private static function refuse(Request $request, Route $route): PlainTextResponse
    {
        return PlainTextResponse::refusing(
            HttpStatusCode::MethodNotAllowed,
            UnroutedController::refusal($request->language()),
            new Header(ResponseHeader::Allow, $route->allowed()),
        );
    }

    /**
     * The 400 a route's controller or layers are answered with when they asked for input the
     * request did not send readably — see {@link \Phpanta\Http\Input}.
     *
     * It says only that the request could not be read. What was wrong, and with which parameter, is
     * the exception's message, and that is for a developer: a value a visitor sent is not echoed.
     *
     * @param Request $request
     * @return PlainTextResponse
     */
    private static function unreadable(Request $request): PlainTextResponse
    {
        return PlainTextResponse::refusing(
            HttpStatusCode::BadRequest,
            FrameworkText::BadRequest->in($request->language()) . "\n",
        );
    }

    /**
     * The 413 a route's controller or layers are answered with when what the request sent was
     * readable but larger than the host takes — see {@link TooLargeException}. Like the 400, it
     * says only that.
     *
     * @param Request $request
     * @return PlainTextResponse
     */
    private static function tooLarge(Request $request): PlainTextResponse
    {
        return PlainTextResponse::refusing(
            HttpStatusCode::ContentTooLarge,
            FrameworkText::ContentTooLarge->in($request->language()) . "\n",
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
