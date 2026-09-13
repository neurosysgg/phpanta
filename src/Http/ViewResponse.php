<?php

declare(strict_types=1);

namespace Phpanta\Http;

use Phpanta\App;
use Phpanta\Support\Collection;
use Phpanta\View\Html\Element;
use Phpanta\View\Html\Fragment;
use Phpanta\View\Html\HtmlTag;
use Phpanta\View\View;

/**
 * The ViewResponse class. Renders a {@link View} as an HTTP response.
 *
 * On AJAX requests, emits only the content fragment prefixed by a title tag.
 * On full-page requests, wraps the content in the app's {@link \Phpanta\View\Shell}, which
 * {@link App::shell()} names.
 *
 * Sends its own `Content-Type` rather than leaving PHP's `default_mimetype` to supply one — see
 * {@link MimeType}. It matters most for the fragment, which declares no encoding of its own.
 *
 * **It also decides whether a document may be reused**, which is the one thing here that is not
 * simply "render and echo". See {@link self::cacheHeaders()} for why the answer is an `ETag` and
 * `no-cache` rather than a `max-age`.
 */
readonly class ViewResponse implements Response
{
    /**
     * Constructs an instance of {@link self}.
     *
     * @param View           $view    The view to render.
     * @param HttpStatusCode $status  The HTTP status code.
     * @param Collection<Header> $headers Extra headers, e.g. `Cache-Control:` on an authenticated
     *                                page. Same parameter {@link PlainTextResponse} takes, in the
     *                                same position, so the two responses are shaped alike.
     *
     *                                A collection rather than the `list<Header>` it was: the
     *                                annotation was the only thing saying what the array held, on
     *                                a constructor four call sites reach from outside this
     *                                namespace. It is the same move the outbound
     *                                {@link \Phpanta\Tool\Http\Request} already made for the
     *                                headers it sends.
     */
    public function __construct(
        private View           $view,
        private HttpStatusCode $status = HttpStatusCode::Ok,
        private Collection     $headers = new Collection(Header::class),
    ) {}

    /**
     * Sends the response; emits headers and rendered HTML.
     *
     * The body is rendered **before** any header goes out, which is what makes an `ETag` possible
     * at all: the validator is a hash of the bytes, so the bytes have to exist first. Nothing is
     * echoed until every header is sent, so that order costs one string held in memory and nothing
     * else.
     *
     * @param Request $request
     * @return void
     */
    public function send(Request $request): void
    {
        $language = $request->language();
        $markup   = $this->render($request);

        // Hashed once and passed down, rather than built here and built again inside
        // cacheHeaders(): the validator sent and the validator compared have to be the same value,
        // and two calls to a pure function are a way of saying so that costs a second hash of the
        // whole page on every request.
        $etag  = ETag::forBody($markup);
        $cache = $this->cacheHeaders($etag);

        // A validator the browser already holds means the copy it already holds is current. 304 and
        // nothing else — no Content-Type, because there is no content to describe.
        if ($this->validates() && $etag->matches($request->ifNoneMatch())) {
            http_response_code(HttpStatusCode::NotModified->value);
            self::sendAll($cache);

            return;
        }

        http_response_code($this->status->value);
        header(new Header(ResponseHeader::ContentType, MimeType::html())->line());
        header(new Header(ResponseHeader::ContentLanguage, new ContentLanguage($language))->line());

        self::sendAll($cache);
        self::sendAll($this->headers);

        echo $markup;
    }

    /**
     * The markup this response answers $request with, without sending it — {@link self::send()}'s
     * public twin.
     *
     * A fragment led by its `<title>` for a request {@link RequestedWith} marks as Navigation's, the
     * whole document in the app's shell otherwise, and in the language the request is answered in
     * either way. It is what a static export writes to disk, and what a test asserts on without
     * catching output.
     *
     * @param Request $request
     * @return string
     */
    public function render(Request $request): string
    {
        $language = $request->language();

        // The fragment leads with a <title> so Navigation can read the new page title out of it —
        // an element like any other, so the title is escaped by the same rule as everything else.
        $body = $request->isAjax()
            ? new Fragment(
                new Element(HtmlTag::Title)->containing($this->view->pageTitle()),
                $this->view->content(),
            )
            : App::current()->shell()->document($this->view, $language);

        // The language is passed in as well as stated on <html lang>, because a fragment has no
        // <html>: without it, the first translated text in the fragment would have no scope.
        return $body->render(0, $language);
    }

    /**
     * The status this response is sent with — which a static export asks, because a file on a
     * static host is always a 200.
     *
     * @return HttpStatusCode
     */
    public function status(): HttpStatusCode
    {
        return $this->status;
    }

    /**
     * The headers that say whether this document may be reused, and what a reused copy depends on.
     *
     * **`no-cache` is not `no-store`.** It means keep the copy and ask before reusing it, so a
     * return visit costs a round trip and no bytes — the 304 above. What it buys over a `max-age`
     * is that there is no window at all in which a visitor holds a stale document, and that matters
     * here more than it would elsewhere:
     *
     * - A document embeds every versioned asset URL — the stylesheet, the entry script and whatever
     *   preloads there are, straight out of the build's asset manifest. That is a couple of URLs
     *   on a bundled tree and dozens on a debug tree, and the argument is the same either way: it
     *   takes one. A stale document
     *   therefore names *last build's* URLs, and `public/.htaccess` marked those `immutable` for a
     *   year, so the browser would serve the old JS out of its own cache against the new HTML.
     *   That is the mirror drift the parity tests exist to catch, arriving by the one route no test
     *   can see. A `max-age` of any size opens exactly that window.
     * - Hashing the body needs no coupling to the build stamp, because the stamp is already *in*
     *   the body. A rebuild changes the asset URLs, which changes the markup, which changes the
     *   validator. Nothing had to be wired together for that; it falls out.
     * - A site's data files are read on every request and contribute nothing to the build stamp.
     *   Under `no-cache` an edit to one is live immediately: there is no cache to bust and no
     *   rebuild needed.
     *
     * `Vary` names `X-Requested-With` because one URL has two bodies here — see
     * {@link ResponseHeader::Vary}. The `ETag` is a second guard on the same hazard: the document
     * and the fragment are different bytes, so they cannot validate against each other even where
     * `Vary` is ignored.
     *
     * It names `Accept-Language` and `Cookie` too, on every page, because every page is written in
     * the language {@link Request::language()} reads from those two; the `ETag` is a second guard
     * there as well, since two languages are different bytes. **Anything beyond those three comes
     * from the view**, through {@link View::varyOn()}, because the page is what knows which other
     * headers it read.
     *
     * **`Vary` goes on every response this class sends**, whatever else is left out, because it is
     * a statement about the body rather than about caching it. A caller that says how its response
     * may be kept has said how long a copy may live, not what the copy depends on, and a `max-age`
     * without a `Vary` is exactly the cache that hands one visitor another's language.
     *
     * **A caller that supplied its own `Cache-Control` gets no validator**, and no 304 either.
     * That is a page behind a password, which says `no-store, private` —
     * {@link CacheControl::doNotStore()} — because nothing about it may be kept; adding a validator
     * to a response we just asked not to be stored would be arguing with ourselves.
     *
     * **Neither does anything but a success** — see {@link self::validates()}. A 404 still says
     * `no-cache`, because a 404 is cacheable by default: a browser left to its heuristics could
     * keep one for a page that has since been published.
     *
     * The other responses are not this class's to answer for and deliberately carry nothing: a
     * {@link RedirectResponse} is re-asked every time, the 401
     * {@link \Phpanta\Service\Auth} exits with never becomes a `Response` at all, and the 405 and
     * 503 are {@link PlainTextResponse}.
     *
     * @param ETag $etag The validator for this body, hashed by the caller — which is also what the
     *                   caller compares against `If-None-Match`, so the two cannot be a hash apart.
     * @return Collection<Header>
     */
    private function cacheHeaders(ETag $etag): Collection
    {
        $headers = new Collection(Header::class);

        if (!$this->saysHowToKeep()) {
            $headers = $headers->with(new Header(ResponseHeader::CacheControl, CacheControl::revalidate()));
        }

        if ($this->validates()) {
            $headers = $headers->with(new Header(ResponseHeader::ETag, $etag));
        }

        return $headers->with(new Header(
            ResponseHeader::Vary,
            Vary::on(
                RequestHeader::RequestedWith,
                RequestHeader::AcceptLanguage,
                RequestHeader::Cookie,
                ...$this->view->varyOn(),
            ),
        ));
    }

    /**
     * True if this response carries a validator, and so may be answered with a 304.
     *
     * A success only: RFC 9110 §13.2.1 has a server ignore every precondition when the response
     * would otherwise not be a 2xx, so a 404 revalidated into a 304 would tell a cache that the
     * copy it holds of the page — a 200, from before the page went — is still current. And never
     * where the caller said how the response may be kept — see {@link self::cacheHeaders()}.
     *
     * @return bool
     */
    private function validates(): bool
    {
        return $this->status->isSuccessful() && !$this->saysHowToKeep();
    }

    /**
     * True if the caller supplied its own `Cache-Control`.
     *
     * @return bool
     */
    private function saysHowToKeep(): bool
    {
        return $this->headers->first(
            static fn(Header $header): bool => $header->name === ResponseHeader::CacheControl,
        ) !== null;
    }

    /**
     * @param Collection<Header> $headers
     * @return void
     */
    private static function sendAll(Collection $headers): void
    {
        foreach ($headers as $header) {
            header($header->line());
        }
    }
}
