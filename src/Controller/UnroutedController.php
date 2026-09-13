<?php

declare(strict_types=1);

namespace Phpanta\Controller;

use Phpanta\App;
use Phpanta\Http\Allow;
use Phpanta\Http\Header;
use Phpanta\Http\HttpStatusCode;
use Phpanta\Http\PlainTextResponse;
use Phpanta\Http\Request;
use Phpanta\Http\Response;
use Phpanta\Http\ResponseHeader;
use Phpanta\Support\Collection;
use Phpanta\Text\FrameworkText;
use Phpanta\Text\Language;

/**
 * The UnroutedController class. What the app says about an address it does not have: the rendered
 * 404 for a method that reads, the `text/plain` 405 for one that writes.
 *
 * **It is the router's answer, and only the router's**: {@link \Phpanta\Router} hands it every path
 * no route claims. The admin answers for itself — {@link ApiController} gives a caller it cannot
 * verify one answer of its own at every depth — so nothing here is about hiding an address. This is
 * what an address that is not there is, and it is a class of its own so that there is one place
 * that answer is written.
 *
 * **The 405 is always the read-only `Allow`.** No route claimed the path, so there is no set of
 * methods to name but the one every page answers on: what this sends is what the site sends for
 * `/no-such-page`, because that is what the address is.
 */
final readonly class UnroutedController implements Controller
{
    /**
     * The body of every 405 the app sends, in $language.
     *
     * One method because {@link \Phpanta\Router} sends one too, for the other 405 — a path a
     * route *did* claim with a method it does not accept — and those two bodies being the same
     * sentence is the whole reason a caller cannot tell the two situations apart. Both put it into
     * the request's language, so for any one caller it is still one sentence. The `Allow`
     * headers differ, correctly; the body must not.
     *
     * @param Language $language
     * @return string
     */
    public static function refusal(Language $language): string
    {
        return FrameworkText::ReadOnly->in($language) . "\n";
    }

    /**
     * @param Request $request
     * @return Response
     */
    public function handle(Request $request): Response
    {
        if ($request->isReadOnly()) {
            return App::current()->notFound($request);
        }

        return new PlainTextResponse(
            HttpStatusCode::MethodNotAllowed,
            self::refusal($request->language()),
            new Collection(Header::class)->with(new Header(ResponseHeader::Allow, Allow::readOnly())),
        );
    }
}
