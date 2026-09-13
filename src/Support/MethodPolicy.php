<?php

declare(strict_types=1);

namespace Phpanta\Support;

use Phpanta\Http\HttpMethod;

/**
 * The MethodPolicy enum. Who decides which methods a route answers on — the router, or the route's
 * own controller.
 *
 * There are two cases because there are two kinds of route here, and the second kind has exactly
 * one member.
 *
 * **A policy rather than a `Collection<HttpMethod>` on {@link Route}, and the reason is worth keeping.**
 * The obvious design is for each route to carry its own set of methods and for the 405 to name that
 * set — which is what {@link \Phpanta\Http\Allow}'s docblock argues for, and it is right for nine
 * routes out of ten. It is wrong for {@link ApiPath::Api}, whose entire purpose is to be
 * indistinguishable from an address that does not exist: `PUT /api/update/v1/patch` would answer
 * `Allow: GET, HEAD, POST`, and the `POST` in that list is precisely the fact the endpoint exists
 * to hide. An unrecognised verb would be worse still — {@link \Phpanta\Http\Request::method()} is
 * null for one, null is in no set, and the refusal would name the whole set.
 *
 * So the choice is not "which methods" but "who answers", and written that way the router only
 * ever sends one `Allow` — the read-only one. See docs/history/api.md.
 */
enum MethodPolicy: string
{
    /**
     * The router refuses anything that is not a read, before the controller is built.
     *
     * Nine routes, and the default, so none of them says so.
     */
    case ReadOnly = 'read-only';

    /**
     * The router forms no opinion and the controller answers every method itself.
     *
     * One route. The controller then has to be trusted to refuse properly, which
     * {@link \Phpanta\Controller\ApiController} does by handing anything it will not verify to
     * {@link \Phpanta\Controller\UnroutedController} — the same object the router uses for a path
     * no route claimed at all.
     */
    case Delegated = 'delegated';

    /**
     * Whether a route under this policy answers $method at all.
     *
     * Takes a nullable method because {@link \Phpanta\Http\Request::method()} is nullable: an
     * unrecognised verb is null rather than a guess. Under {@link self::Delegated} even null is
     * accepted, and that is deliberate — the controller must see it, because the alternative is the
     * router answering differently for `BREW /api/update/v1/patch` than for `BREW /no-such-page`.
     *
     * @param HttpMethod|null $method
     * @return bool
     */
    public function accepts(?HttpMethod $method): bool
    {
        return match ($this) {
            self::ReadOnly  => $method?->isReadOnly() ?? false,
            self::Delegated => true,
        };
    }
}
