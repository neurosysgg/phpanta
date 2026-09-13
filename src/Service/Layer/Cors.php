<?php

declare(strict_types=1);

namespace Phpanta\Service\Layer;

use Phpanta\Controller\Controller;
use Phpanta\Controller\Layer;
use Phpanta\Http\Allow;
use Phpanta\Http\EmptyResponse;
use Phpanta\Http\Header;
use Phpanta\Http\HttpStatusCode;
use Phpanta\Http\Origin;
use Phpanta\Http\Request;
use Phpanta\Http\RequestHeader;
use Phpanta\Http\Response;
use Phpanta\Http\ResponseHeader;
use Phpanta\Http\Vary;
use Phpanta\Http\WithHeaders;
use Phpanta\Support\Collection;

/**
 * The Cors layer. Which other origins may read what this app answers, and with which methods.
 *
 * ```php
 * protected function layers(): Collection
 * {
 *     return new Collection(Layer::class)->with(
 *         new Cors(Allow::readOnly(), Origin::of('https://app.example.org')),
 *     );
 * }
 * ```
 *
 * Three answers, and the listed origins are the whole of the decision:
 *
 * - **a request from a listed origin** is answered as it would be, with
 *   `Access-Control-Allow-Origin` naming that one origin — never `*`, so the list is what it says;
 * - **a preflight from a listed origin** — an `OPTIONS` asking for a method — is a 204 naming the
 *   methods allowed, answered here rather than by any route, since it is a question about this
 *   policy and not about the resource;
 * - **anything else** is answered as it would be, with nothing added but the `Vary`.
 *
 * **Every answer through it says `Vary: Origin`**, the allowed and the refused alike, because the
 * header it adds depends on who asked: a cache that kept the copy one origin was allowed would hand
 * it to the next.
 *
 * **No credentials.** There is no `Access-Control-Allow-Credentials`, so a browser sends no cookie
 * and no Basic credential cross-origin, and nothing a gate protects is readable from another origin
 * by way of this layer.
 */
final readonly class Cors implements Layer
{
    /** @var Collection<Origin> */
    private Collection $origins;

    /**
     * Constructs an instance of {@link self}.
     *
     * @param Allow  $methods    What a preflight is told another origin may send.
     * @param Origin ...$origins The origins that may read this app's answers.
     */
    public function __construct(private Allow $methods, Origin ...$origins)
    {
        $this->origins = new Collection(Origin::class)->with(...$origins);
    }

    /**
     * @param Request    $request
     * @param Controller $next
     * @return Response
     */
    public function handle(Request $request, Controller $next): Response
    {
        $origin  = $request->origin();
        $vary    = new Header(ResponseHeader::Vary, Vary::on(RequestHeader::Origin));
        $allowed = $origin !== null
            && $this->origins->first(static fn(Origin $listed): bool => $listed->equals($origin)) !== null;

        if (!$allowed) {
            return new WithHeaders($next->handle($request), new Collection(Header::class)->with($vary));
        }

        $allowOrigin = new Header(ResponseHeader::AccessControlAllowOrigin, $origin);

        if ($request->isPreflight()) {
            return new EmptyResponse(
                HttpStatusCode::NoContent,
                new Collection(Header::class)->with(
                    $allowOrigin,
                    new Header(ResponseHeader::AccessControlAllowMethods, $this->methods),
                    $vary,
                ),
            );
        }

        return new WithHeaders($next->handle($request), new Collection(Header::class)->with($allowOrigin, $vary));
    }
}
