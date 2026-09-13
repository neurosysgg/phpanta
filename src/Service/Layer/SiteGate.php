<?php

declare(strict_types=1);

namespace Phpanta\Service\Layer;

use Phpanta\Controller\Controller;
use Phpanta\Controller\Layer;
use Phpanta\Http\Request;
use Phpanta\Http\Response;
use Phpanta\Service\Auth;
use Phpanta\Support\File;

/**
 * The SiteGate layer. The pre-launch gate, around every request: its 401, or the rest of the way
 * in.
 *
 * The first of {@link \Phpanta\App::layerTable()}, put there by the framework rather than listed by
 * a site, so no site can forget it or list it after something that should have been behind it. It
 * stands aside when `data/site_auth.php` is absent — see {@link Auth::siteGate()}.
 */
final readonly class SiteGate implements Layer
{
    /**
     * Constructs an instance of {@link self}.
     *
     * @param File|null $file The credentials file; `data/site_auth.php` by default. A test passes one.
     */
    public function __construct(private ?File $file = null) {}

    /**
     * @param Request    $request
     * @param Controller $next
     * @return Response
     */
    public function handle(Request $request, Controller $next): Response
    {
        return Auth::siteGate($request, $this->file) ?? $next->handle($request);
    }
}
