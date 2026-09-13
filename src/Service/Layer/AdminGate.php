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
 * The AdminGate layer. The admin gate around one route: its 401, or the controller.
 *
 * Listed on the route — `new Route(…)->through(new AdminGate())` — rather than called by the
 * controller, so the gate is written where the address is, and a controller behind it is only the
 * page. The controller then has no gate of its own to test around, and the route table is where a
 * test asks whether an admin page is gated at all. See {@link Auth::adminGate()}.
 */
final readonly class AdminGate implements Layer
{
    /**
     * Constructs an instance of {@link self}.
     *
     * @param File|null $file The credentials file; `data/admin.php` by default. A test passes one.
     */
    public function __construct(private ?File $file = null) {}

    /**
     * @param Request    $request
     * @param Controller $next
     * @return Response
     */
    public function handle(Request $request, Controller $next): Response
    {
        return Auth::adminGate($request, $this->file) ?? $next->handle($request);
    }
}
