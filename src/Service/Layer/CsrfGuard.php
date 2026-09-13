<?php

declare(strict_types=1);

namespace Phpanta\Service\Layer;

use Phpanta\App;
use Phpanta\Controller\Controller;
use Phpanta\Controller\Layer;
use Phpanta\Exception\InputException;
use Phpanta\Http\CacheControl;
use Phpanta\Http\CsrfField;
use Phpanta\Http\Header;
use Phpanta\Http\HttpStatusCode;
use Phpanta\Http\PlainTextResponse;
use Phpanta\Http\Request;
use Phpanta\Http\Response;
use Phpanta\Http\ResponseHeader;
use Phpanta\Http\Session;
use Phpanta\Http\SessionSeal;
use Phpanta\Support\Collection;
use Phpanta\Text\FrameworkText;

/**
 * The CsrfGuard layer. A write reaches its controller only if the form sent back the token this
 * visitor's session handed out.
 *
 * The session is the ambient credential a cross-site request would ride: the browser sends its
 * cookie with any request to this site, whoever made the page that sent it. A token is what another
 * site cannot have — it was written into this site's page, for this visitor, and is kept in their
 * sealed session. So a write whose `_csrf` field does not match, or which carries none, or whose
 * visitor has no token at all, is answered with a 403 and never reaches the controller. A read
 * passes untouched: a read changes nothing to forge.
 *
 * **List it on the routes that take a write, never on the app.** As an app layer it would stand in
 * front of every address, and answer one that does not exist differently from the API — which
 * answers every method itself, exactly as an absent address would — and that difference is the one
 * fact the API is built to keep. On a route it runs past the method gate, where only the writes that
 * route accepts ever reach it. The session cookie's `SameSite=Lax` is the second guard behind it.
 */
final readonly class CsrfGuard implements Layer
{
    /**
     * Constructs an instance of {@link self}.
     *
     * @param SessionSeal|null $seal The seal sessions are opened with; the app's by default. A test passes one.
     */
    public function __construct(private ?SessionSeal $seal = null) {}

    /**
     * @param Request    $request
     * @param Controller $next
     * @return Response
     */
    public function handle(Request $request, Controller $next): Response
    {
        if ($request->isReadOnly()) {
            return $next->handle($request);
        }

        $expected = Session::of($request, $this->seal ?? App::current()->sessionSeal())->token();

        try {
            $sent = $request->form()->text(CsrfField::Token);
        } catch (InputException) {
            $sent = null;
        }

        if ($expected === null || $sent === null || !hash_equals($expected, $sent)) {
            return new PlainTextResponse(
                HttpStatusCode::Forbidden,
                FrameworkText::CsrfRefused->in($request->language()) . "\n",
                new Collection(Header::class)->with(
                    new Header(ResponseHeader::CacheControl, CacheControl::doNotStore()),
                ),
            );
        }

        return $next->handle($request);
    }
}
