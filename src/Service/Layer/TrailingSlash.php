<?php

declare(strict_types=1);

namespace Phpanta\Service\Layer;

use Phpanta\Controller\Controller;
use Phpanta\Controller\Layer;
use Phpanta\Http\HttpStatusCode;
use Phpanta\Http\Location;
use Phpanta\Http\RedirectResponse;
use Phpanta\Http\Request;
use Phpanta\Http\Response;
use Phpanta\View\Html\Element;

/**
 * The TrailingSlash layer. One address per page: `/releases/` is sent to `/releases`.
 *
 * Without it both spellings reach the same route and are answered alike, which is forgiving and
 * which a search engine reads as two pages. A site that wants one lists this in
 * {@link \Phpanta\App::layers()}, and a read with a trailing slash is answered with a 308 to the
 * address without it, the query kept.
 *
 * **Only a read is sent on.** A write that carried a slash is answered where it was sent — a 308
 * would repeat the body to the new address, and a form should not post twice because of a slash.
 * **And only on this origin**: the address is put to {@link Element::staysOnThisOrigin()} first, so
 * a target crafted to trim into another host is answered as it would have been rather than sent
 * there.
 */
final readonly class TrailingSlash implements Layer
{
    /**
     * @param Request    $request
     * @param Controller $next
     * @return Response
     */
    public function handle(Request $request, Controller $next): Response
    {
        $target = $request->canonicalTarget();

        if (!$request->hasTrailingSlash() || !$request->isReadOnly() || !Element::staysOnThisOrigin($target)) {
            return $next->handle($request);
        }

        return new RedirectResponse(new Location($target), HttpStatusCode::PermanentRedirect);
    }
}
