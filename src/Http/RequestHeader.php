<?php

declare(strict_types=1);

namespace Phpanta\Http;

/**
 * The RequestHeader enum. The request headers this site reads.
 *
 * {@link ResponseHeader} is the other direction; both are {@link HeaderName}s, so {@link Header}
 * formats either.
 */
enum RequestHeader: string implements HeaderName
{
    /**
     * Set by `Navigation` on its fetches, and the whole signal for a fragment response.
     *
     * If this name drifts on either side the server answers a SPA fetch with a full document and
     * `Navigation` writes `<!DOCTYPE html><html>…` into `<main>` — a page broken in a way nothing
     * reports. `assets/ts/model/RequestHeader.ts` mirrors it and the parity test compares them.
     */
    case RequestedWith = 'X-Requested-With';

    /**
     * The validator a browser sends back to ask "is my copy still good?".
     *
     * The one case here **no client code writes** — the browser adds it on its own from the
     * {@link ResponseHeader::ETag} it was given, and {@link ViewResponse} answers a match with a
     * 304. It is mirrored in `assets/ts/model/RequestHeader.ts` all the same, because that mirror
     * is compared case for case and in order; a case with no reader on one side is the same
     * arrangement as {@link ResponseHeader::PoweredBy}, which names a header the site never sends,
     * and `EmbedAttribute::Loaded`, which no view may emit.
     *
     * Naming it is the point. The alternative is reading `HTTP_IF_NONE_MATCH` off `$_SERVER` as a
     * bare string, which is the thing this enum exists to stop.
     */
    case IfNoneMatch = 'If-None-Match';

    /**
     * Which bytes of a file the client wants, when it does not want all of them.
     *
     * Read only by {@link FileResponse}, which is the only response here with a file behind it.
     * The reason it exists is seeking: an `<audio>` element asks for a range when the scrubber is
     * dragged, so a server that ignores this plays a demo perfectly and refuses to skip — a broken
     * control with nothing in the console about it.
     *
     * Mirrored in `assets/ts/model/RequestHeader.ts` with no reader on that side, the same
     * arrangement as {@link self::IfNoneMatch} above: the parity test compares the two case for
     * case, so a case existing on one side only is what fails.
     */
    case Range = 'Range';

    /**
     * Which languages the visitor would rather read, and how much rather.
     *
     * The site is English, so most pages ignore this. The imprint and the privacy policy are not:
     * each carries a German half and an English half, and {@link AcceptedLanguages} decides which
     * one a visitor meets first. See {@link \Phpanta\View\View::language()}.
     *
     * **Whatever reads this owes a `Vary`**, and that is the whole hazard here rather than a note
     * beside it: two visitors asking for the same URL get different bytes, so a cache that has not
     * been told hands one of them the other's page. {@link ViewResponse} takes the header names it
     * varies on from the view, which is what keeps the two facts — "I read this" and "I depend on
     * this" — from being stated separately.
     *
     * Mirrored in `assets/ts/model/RequestHeader.ts` with no reader on that side, the same
     * arrangement as {@link self::IfNoneMatch} and {@link self::Range}: the browser sends it on its
     * own, from the visitor's own language settings, and no client code here writes it.
     */
    case AcceptLanguage = 'Accept-Language';

    /**
     * The cookies the browser holds for this origin.
     *
     * Read for one of them only — {@link CookieName::Language}, by {@link Request::language()},
     * where a visitor's choice outranks {@link self::AcceptLanguage} — and never kept whole; see
     * {@link RequestCookies}. It carries the same hazard as `Accept-Language` above and for the same
     * reason: a page answered by it owes a `Vary` naming it.
     *
     * Mirrored in `assets/ts/model/RequestHeader.ts` with no reader on that side: the browser sends
     * it on its own, and no client code here writes a cookie.
     */
    case Cookie = 'Cookie';

    /**
     * The page the visitor was on when they followed a link here.
     *
     * Read by one route, `LanguageController`, for one thing: which page
     * to send a visitor back to after they switch language. Only its path is taken, and only after
     * {@link \Phpanta\View\Html\Element::staysOnThisOrigin()} agrees the path stays here; it is
     * never stored and never logged. The site's own `Referrer-Policy` is
     * `strict-origin-when-cross-origin`, so a click from one of its pages carries the full path.
     *
     * Mirrored in `assets/ts/model/RequestHeader.ts` with no reader on that side, the same
     * arrangement as {@link self::IfNoneMatch}: the browser sends it on its own.
     */
    case Referer = 'Referer';

    /**
     * @return string
     */
    public function headerName(): string
    {
        return $this->value;
    }
}
