<?php

declare(strict_types=1);

namespace Phpanta\Service\Layer;

use Phpanta\App;
use Phpanta\Controller\Controller;
use Phpanta\Controller\Layer;
use Phpanta\Http\CacheControl;
use Phpanta\Http\Header;
use Phpanta\Http\HttpStatusCode;
use Phpanta\Http\PlainTextResponse;
use Phpanta\Http\Request;
use Phpanta\Http\Response;
use Phpanta\Http\ResponseHeader;
use Phpanta\Support\Collection;
use Phpanta\Support\File;
use Phpanta\Support\Route;
use Phpanta\Text\FrameworkText;

/**
 * The Maintenance layer. A 503 for every page while a switch file exists — and never for the admin.
 *
 * **The switch is a file, and absent means off**, the same polarity as the site gate's
 * `data/site_auth.php`: a deployment that has never heard of maintenance is not in it. A site lists
 * the layer in {@link App::layers()} with the file it chooses, and switching maintenance on is
 * uploading that file.
 *
 * **The admin is let through**, because a push is how maintenance usually ends: a site that answered
 * its own update with a 503 could only leave maintenance by a full deploy. The test is the admin
 * routes' own match, not a prefix — see {@link App::adminRoutes()}.
 *
 * The 503 says `no-store`, so no cache keeps the maintenance notice after the site is back.
 */
final readonly class Maintenance implements Layer
{
    /**
     * Constructs an instance of {@link self}.
     *
     * @param File $switch The file whose presence means maintenance.
     */
    public function __construct(private File $switch) {}

    /**
     * @param Request    $request
     * @param Controller $next
     * @return Response
     */
    public function handle(Request $request, Controller $next): Response
    {
        $path  = $request->path();
        $admin = App::current()->adminRoutes()
            ->first(static fn(Route $route): bool => $route->matches($path) !== false);

        if (!$this->switch->exists() || $admin !== null) {
            return $next->handle($request);
        }

        return new PlainTextResponse(
            HttpStatusCode::ServiceUnavailable,
            FrameworkText::Maintenance->in($request->language()) . "\n",
            new Collection(Header::class)->with(new Header(ResponseHeader::CacheControl, CacheControl::doNotStore())),
        );
    }
}
