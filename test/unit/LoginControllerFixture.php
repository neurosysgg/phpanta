<?php

declare(strict_types=1);

namespace Phpanta\Test\Unit;

use Phpanta\Controller\Controller;
use Phpanta\Form\Form;
use Phpanta\Form\Submission;
use Phpanta\Http\CacheControl;
use Phpanta\Http\Header;
use Phpanta\Http\HttpStatusCode;
use Phpanta\Http\Location;
use Phpanta\Http\RedirectResponse;
use Phpanta\Http\Request;
use Phpanta\Http\Response;
use Phpanta\Http\ResponseHeader;
use Phpanta\Http\Session;
use Phpanta\Http\ViewResponse;
use Phpanta\Service\Login;
use Phpanta\Support\Collection;
use Phpanta\Text\FrameworkText;

/**
 * The login recipe's login page — see docs/login.md, which shows this controller as a site writes it.
 *
 * A read shows the form and hands the visitor a form token. A send that is not filled in is shown
 * again and costs no attempt; one that is asks {@link Login::attempt()}, and is signed in, refused
 * for trying too often, or shown again with the one refusal no rule could make.
 */
final readonly class LoginControllerFixture implements Controller
{
    /**
     * Constructs an instance of {@link self}.
     *
     * @param Login        $login
     * @param UsersFixture $users
     */
    public function __construct(private Login $login, private UsersFixture $users) {}

    /**
     * @param Request $request
     * @return Response
     */
    public function handle(Request $request): Response
    {
        $form    = new Form(LoginFieldFixture::class, LoginPathFixture::Login);
        $session = $request->session()->withToken();

        if ($request->isReadOnly()) {
            return self::page($form, $form->blank(), $session, HttpStatusCode::Ok);
        }

        $sent = $form->read($request);

        if (!$sent->isValid()) {
            return self::page($form, $sent, $session, HttpStatusCode::UnprocessableContent);
        }

        $name   = $sent->value(LoginFieldFixture::Name);
        $result = $this->login->attempt(
            $request,
            $session,
            $name,
            $sent->value(LoginFieldFixture::Password),
            $this->users->hash($name),
        );

        return match (true) {
            $result instanceof Session  => $result
                ->withMessage(LoginTextFixture::SignedIn)
                ->attachTo(new RedirectResponse(new Location(LoginPathFixture::Account->to()))),
            $result instanceof Response => $result,
            default                     => self::page(
                $form,
                $sent->withError(LoginFieldFixture::Password, FrameworkText::LoginRefused),
                $session,
                HttpStatusCode::UnprocessableContent,
            ),
        };
    }

    /**
     * The login page holding $sent, with the session attached and the messages it carried shown once.
     *
     * @param Form           $form
     * @param Submission     $sent
     * @param Session        $session
     * @param HttpStatusCode $status  A 200 for a first render, a 422 for a form sent back to be fixed.
     * @return Response
     */
    private static function page(Form $form, Submission $sent, Session $session, HttpStatusCode $status): Response
    {
        return $session->withoutMessages()->attachTo(new ViewResponse(
            new RecipePageFixture(
                LoginTextFixture::SignIn,
                $session->messages(),
                $form->render($sent, (string) $session->token(), LoginTextFixture::SignIn),
            ),
            $status,
            new Collection(Header::class)->with(new Header(ResponseHeader::CacheControl, CacheControl::doNotStore())),
        ));
    }
}
