<?php

declare(strict_types=1);

namespace Phpanta\Support;

use Phpanta\Http\Allow;
use Phpanta\Http\HttpMethod;

/**
 * The MethodPolicy enum. Who decides which methods a route answers on — the router, or the route's
 * own controller.
 *
 * There are two cases because there are two kinds of route here, and the second kind is the
 * admin's alone.
 *
 * **A policy rather than a `Collection<HttpMethod>` on {@link Route}, and the reason is worth keeping.**
 * The obvious design is for each route to carry its own set of methods and for the 405 to name that
 * set — which is what {@link \Phpanta\Http\Allow}'s docblock argues for, and it is right for nearly
 * every route. It is wrong for {@link AdminPath}'s, whose controller answers a caller it cannot
 * verify the same way at every depth, whether the address exists or not: a router that refused a
 * `PUT` to one depth and let it through to another would say which depth is an action before the
 * caller had proved anything. An unrecognised verb would be worse still —
 * {@link \Phpanta\Http\Request::method()} is null for one, null is in no set, and the refusal would
 * name the whole set.
 *
 * So the choice is not "which methods" but "who answers", and written that way the router only
 * ever sends one `Allow` — the read-only one.
 */
enum MethodPolicy: string implements MethodGate
{
    /**
     * The router refuses anything that is not a read, before the controller is built.
     *
     * Every page, and the default, so none of them says so.
     */
    case ReadOnly = 'read-only';

    /**
     * The router forms no opinion and the controller answers every method itself.
     *
     * The admin's four routes. The controller then has to be trusted to refuse properly, which
     * {@link \Phpanta\Controller\ApiController} does by giving a caller it cannot verify one answer
     * at every depth, whatever the method.
     */
    case Delegated = 'delegated';

    /**
     * Whether a route under this policy answers $method at all.
     *
     * Takes a nullable method because {@link \Phpanta\Http\Request::method()} is nullable: an
     * unrecognised verb is null rather than a guess. Under {@link self::Delegated} even null is
     * accepted, and that is deliberate — the controller must see it, because the alternative is the
     * router answering `BREW /admin/update/v1/patch` before the controller could say who is asking.
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

    /**
     * The read-only set, whichever the policy.
     *
     * The router only asks this of a route that refused, and {@link self::Delegated} refuses
     * nothing — so it is only ever the read-only answer, and an admin action's own method is never
     * named to a caller who has not proved anything, which is the reason this enum exists.
     *
     * @return Allow
     */
    public function allow(): Allow
    {
        return Allow::readOnly();
    }
}
