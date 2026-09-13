<?php

declare(strict_types=1);

namespace Phpanta\Test\Unit;

use Phpanta\Controller\Controller;
use Phpanta\Http\Location;
use Phpanta\Http\RedirectResponse;
use Phpanta\Http\Request;
use Phpanta\Http\Response;

/**
 * The login recipe's logout — see docs/login.md. A POST behind the form-token guard: forget the
 * visitor, and send them to the login page with a word to say so.
 */
final readonly class LogoutControllerFixture implements Controller
{
    /**
     * @param Request $request
     * @return Response
     */
    public function handle(Request $request): Response
    {
        return $request->session()
            ->withoutUser()
            ->withMessage(LoginTextFixture::SignedOut)
            ->attachTo(new RedirectResponse(new Location(LoginPathFixture::Login->to())));
    }
}
