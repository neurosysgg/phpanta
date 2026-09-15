<?php

declare(strict_types=1);

namespace Phpanta\Support;

/**
 * The AdminPath enum. The framework's own addresses: the admin, at each of its depths.
 *
 * {@link Path}s of their own rather than cases on a site's vocabulary, because the admin is the
 * framework's — its controller, its gate, its handlers — and every site built on it answers on these
 * addresses whether or not it links to them. {@link \Phpanta\App::routeTable()} appends them to the
 * site's routes, last, so no site route can be shadowed by one and a site cannot forget them.
 *
 * **One case per depth, and every depth answers.** The entrance is a page for anyone; below it, a
 * service lists its versions, a version lists its actions, the fourth depth is an action, and the
 * fifth is an action and the path it acts on, for the few that act on one. That is
 * what makes the admin discoverable: a caller who can open it at all can walk it from the top, and
 * a new service is an {@link \Phpanta\Http\Api\ApiService} case and its handlers — it appears in the
 * listings, and answers, with no route to register.
 *
 * `{version}` is a segment rather than something the router understands, deliberately — see
 * {@link \Phpanta\Http\Api\ApiVersion} for why it sits after the service and not before it.
 *
 * Answering is {@link \Phpanta\Controller\ApiController}'s. A caller it cannot verify gets one
 * answer at every depth below the entrance, whether the address exists or not — so what a stranger
 * learns is that there is an admin, which the site may say anyway, and nothing about what is in it.
 */
enum AdminPath: string implements Path
{
    use FillsPlaceholders;

    /** The entrance: a page for anyone, and the list of services for a verified caller. */
    case Index = '/admin';

    /** One service: the versions it offers. */
    case Service = '/admin/{service}';

    /** One version of one service: the actions it offers. */
    case Version = '/admin/{service}/{version}';

    /** One action — the address a verified call is made to. */
    case Action = '/admin/{service}/{version}/{action}';

    /**
     * One action, and the path it acts on — a file or a directory, for an action that takes one
     * ({@link \Phpanta\Http\Api\ApiAction::takesPath()}). The path is part of the address, so a
     * signed call's envelope binds it and a browser's write is tapped for it, with nothing read
     * from a query. An action that takes none has no address here: a verified caller asking for one
     * is told there is no such action, and a stranger gets the one answer, as at every depth.
     */
    case Subject = '/admin/{service}/{version}/{action}/{subject:path}';
}
