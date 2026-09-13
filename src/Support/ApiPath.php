<?php

declare(strict_types=1);

namespace Phpanta\Support;

/**
 * The ApiPath enum. The framework's own address: the signed API.
 *
 * A {@link Path} of its own rather than a case on a site's vocabulary, because the API is the
 * framework's — its controller, its gate, its handlers — and every site built on it answers on
 * this address whether or not it links to it. {@link \Phpanta\App::routeTable()} appends it to
 * the site's routes, last, so no site route can be shadowed by it and a site cannot forget it.
 */
enum ApiPath: string implements Path
{
    use FillsPlaceholders;

    /**
     * Every signed API address at once — the one path that is not a page, and the one family that
     * writes.
     *
     * **It is a case for the same reason every other address is**, even though no view links to it:
     * a case is what the router matches against, so an address that lived as a literal would be a
     * route the table did not have. That it is unreachable from the site is a property of there
     * being no `<a>` to it, not of it being spelled differently.
     *
     * **It is one case for a whole family**, and that is what makes adding a service cheap:
     * `{service}` and `{action}` are matched exactly as `{slug}` is, so a new service is an
     * {@link \Phpanta\Http\Api\ApiService} case and its handlers, with no route to register and
     * nothing to remember about method policy. It also means the depth is the pattern: `/api`,
     * `/api/update` and `/api/update/v1` match **nothing**, so they fall through to the same 404 as
     * any other address that is not there, without a check anywhere saying so.
     *
     * `{version}` is a segment rather than something the router understands, deliberately — see
     * {@link \Phpanta\Http\Api\ApiVersion} for why it sits after the service and not before it.
     *
     * Answering on it is another matter. {@link \Phpanta\Controller\ApiController} replies
     * exactly as the site replies for a path no route claims, unless the request carries a
     * signature `data/update.pub` verifies — and that file is absent by default, so on a fresh
     * clone every one of these addresses is a 404 and nothing else. See
     * {@link \Phpanta\CredentialFile::UpdateKey}.
     */
    case Api = '/api/{service}/{version}/{action}';
}
