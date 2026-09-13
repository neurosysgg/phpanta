<?php

declare(strict_types=1);

namespace Phpanta\Service\Layer;

use Phpanta\Controller\Controller;
use Phpanta\Controller\Layer;
use Phpanta\Http\CacheControl;
use Phpanta\Http\Header;
use Phpanta\Http\HttpStatusCode;
use Phpanta\Http\PlainTextResponse;
use Phpanta\Http\Request;
use Phpanta\Http\Response;
use Phpanta\Http\ResponseHeader;
use Phpanta\Http\RetryAfter;
use Phpanta\Support\Collection;
use Phpanta\Support\Throttle;
use Phpanta\Text\FrameworkText;

/**
 * The RateLimit layer. A 429 for an address that has asked more often than its {@link Throttle}
 * allows.
 *
 * ```php
 * $login->through(new RateLimit(new Throttle($app->data()->directory('throttle'), limit: 5, window: 900)));
 * ```
 *
 * **Keyed by the remote address, because that is the only identity an anonymous request has.** A
 * cookie is the client's to drop and a header is the client's to write, so a key read from either
 * is a fresh allowance for anybody who changes it; the address is the one thing the client does
 * not choose, short of having another one.
 *
 * **Behind a reverse proxy — a CDN, a load balancer — every request carries the proxy's address**,
 * and every visitor then shares one allowance: the limit becomes the whole site's. The layer does
 * not read `X-Forwarded-For` to undo that, because only a proxy the app trusts may be believed about
 * it, and without one the header is the client's to write — a new address, and a new allowance, on
 * every request. A request with no address at all, such as a synthetic one, is counted under the
 * empty key.
 *
 * **Every request through it is an attempt**, the refused ones not counted (see {@link Throttle}),
 * and a throttle that cannot count throws rather than letting everything through. Listed on a
 * route, it limits that route; listed in {@link \Phpanta\App::layers()}, every page.
 *
 * The 429 says how long to wait in `Retry-After` and `no-store`, so no cache hands the refusal to a
 * visitor who was never refused, and it is written in the request's language.
 */
final readonly class RateLimit implements Layer
{
    /**
     * Constructs an instance of {@link self}.
     *
     * @param Throttle $throttle What an address's requests are counted against.
     */
    public function __construct(private Throttle $throttle) {}

    /**
     * @param Request    $request
     * @param Controller $next
     * @return Response
     */
    public function handle(Request $request, Controller $next): Response
    {
        $verdict = $this->throttle->attempt($request->remoteAddress());

        if ($verdict->allowed()) {
            return $next->handle($request);
        }

        return new PlainTextResponse(
            HttpStatusCode::TooManyRequests,
            FrameworkText::TooManyRequests->in($request->language()) . "\n",
            new Collection(Header::class)->with(
                new Header(ResponseHeader::RetryAfter, new RetryAfter($verdict->retryAfter())),
                new Header(ResponseHeader::CacheControl, CacheControl::doNotStore()),
            ),
        );
    }
}
