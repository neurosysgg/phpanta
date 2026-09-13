<?php

declare(strict_types=1);

namespace Phpanta\Service\Layer;

use Phpanta\App;
use Phpanta\Controller\Controller;
use Phpanta\Controller\Layer;
use Phpanta\Http\CacheControl;
use Phpanta\Http\Header;
use Phpanta\Http\HttpStatusCode;
use Phpanta\Http\Location;
use Phpanta\Http\PlainTextResponse;
use Phpanta\Http\RedirectResponse;
use Phpanta\Http\Request;
use Phpanta\Http\Response;
use Phpanta\Http\ResponseHeader;
use Phpanta\Http\Session;
use Phpanta\Http\SessionSeal;
use Phpanta\Support\Collection;
use Phpanta\Support\Path;
use Phpanta\Text\FrameworkText;

/**
 * The LoginGate layer. A route only a logged-in visitor reaches: anyone else is sent to the login
 * page, or — for a write — refused.
 *
 * The session-backed counterpart of {@link AdminGate}, listed the same way — on the route, with
 * {@link \Phpanta\Support\Route::through()} — and for the same reasons, plus the one
 * {@link CsrfGuard} gives: as an app layer it would answer an address that does not exist
 * differently from the API.
 *
 * A read without a login is a 303 to the login page, which is what a visitor who followed a link
 * wants; a write without one is a 403, since there is nothing sensible to redirect a `POST` to. Both
 * say `no-store`, so no cache hands the next visitor the answer the last one got.
 */
final readonly class LoginGate implements Layer
{
    /**
     * Constructs an instance of {@link self}.
     *
     * @param Path             $login Where a visitor who is not logged in is sent. Its pattern has no placeholders.
     * @param SessionSeal|null $seal  The seal sessions are opened with; the app's by default. A test passes one.
     */
    public function __construct(
        private Path         $login,
        private ?SessionSeal $seal = null,
    ) {}

    /**
     * @param Request    $request
     * @param Controller $next
     * @return Response
     */
    public function handle(Request $request, Controller $next): Response
    {
        if (Session::of($request, $this->seal ?? App::current()->sessionSeal())->user() !== null) {
            return $next->handle($request);
        }

        $noStore = new Collection(Header::class)->with(
            new Header(ResponseHeader::CacheControl, CacheControl::doNotStore()),
        );

        return $request->isReadOnly()
            ? new RedirectResponse(new Location($this->login->to()), HttpStatusCode::SeeOther, $noStore)
            : new PlainTextResponse(
                HttpStatusCode::Forbidden,
                FrameworkText::LoginRequired->in($request->language()) . "\n",
                $noStore,
            );
    }
}
