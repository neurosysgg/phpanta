<?php

declare(strict_types=1);

namespace Phpanta\Test\Unit;

use Closure;
use Phpanta\Controller\Controller;
use Phpanta\Http\HttpStatusCode;
use Phpanta\Http\PlainTextResponse;
use Phpanta\Http\Request;
use Phpanta\Http\Response;

/**
 * A controller that answers every request with a 200 saying what it was built with, and what the
 * request's method was — for {@link RouteTest} and {@link RouterTest}, which need to see which route
 * answered and what its match handed the factory.
 */
final readonly class EchoController implements Controller
{
    /**
     * @param string $said What the body says, ahead of the method.
     */
    public function __construct(private string $said) {}

    /**
     * A route's factory: the captured values, joined with `|`, become what the controller says.
     *
     * @return Closure
     */
    public static function factory(): Closure
    {
        return static fn(string ...$values): self => new self(implode('|', $values));
    }

    /**
     * @param Request $request
     * @return Response
     */
    public function handle(Request $request): Response
    {
        return new PlainTextResponse(HttpStatusCode::Ok, "$this->said " . ($request->method()?->value ?? 'none'));
    }
}
