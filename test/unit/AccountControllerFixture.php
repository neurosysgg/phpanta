<?php

declare(strict_types=1);

namespace Phpanta\Test\Unit;

use Phpanta\Controller\Controller;
use Phpanta\Form\Form;
use Phpanta\Http\CacheControl;
use Phpanta\Http\Header;
use Phpanta\Http\HttpStatusCode;
use Phpanta\Http\Request;
use Phpanta\Http\Response;
use Phpanta\Http\ResponseHeader;
use Phpanta\Http\ViewResponse;
use Phpanta\Support\Collection;
use Phpanta\View\Html\Element;
use Phpanta\View\Html\HtmlTag;

/**
 * The login recipe's page behind the login — see docs/login.md. Its route's gate has already sent
 * anyone else away, so the session here always has a user; it shows who, the messages it carried
 * once, and the logout form.
 */
final readonly class AccountControllerFixture implements Controller
{
    /**
     * @param Request $request
     * @return Response
     */
    public function handle(Request $request): Response
    {
        $session = $request->session()->withToken();
        $logout  = new Form(LogoutFieldFixture::class, LoginPathFixture::Logout);
        $token   = (string) $session->token();

        return $session->withoutMessages()->attachTo(new ViewResponse(
            new RecipePageFixture(
                LoginTextFixture::Account,
                $session->messages(),
                new Element(HtmlTag::Div)
                    ->containing(new Element(HtmlTag::P)->containing(
                        LoginTextFixture::SignedInAs->with(user: (string) $session->user()),
                    ))
                    ->containing($logout->render($logout->blank(), $token, LoginTextFixture::SignOut)),
            ),
            HttpStatusCode::Ok,
            new Collection(Header::class)->with(new Header(ResponseHeader::CacheControl, CacheControl::doNotStore())),
        ));
    }
}
