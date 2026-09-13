<?php

declare(strict_types=1);

namespace Phpanta\Http;

/**
 * The RequestHeader enum. The request headers the framework reads.
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
     * arrangement as {@link ResponseHeader::PoweredBy}, which names a header the framework never
     * sends.
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
     * dragged, so a server that ignores this plays a track perfectly and refuses to skip — a broken
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
     * Every page reads it, through {@link Request::language()}: where no language cookie has
     * decided, {@link AcceptedLanguages} picks which of the app's languages a visitor meets.
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
     * Read by a language switch's route, for one thing: which page to send a visitor back to after
     * they switch language. Only its path is taken, and only after
     * {@link \Phpanta\View\Html\Element::staysOnThisOrigin()} agrees the path stays here; it is
     * never stored and never logged. The framework's own `Referrer-Policy` is
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

    /**
     * The key this header arrives under in the server variables.
     *
     * `HTTP_` plus the name upper-cased with dashes as underscores, which is PHP's transform and
     * not ours — derived here rather than retyped at each reader, which is the whole reason the
     * header names are an enum: the client sends `X-Requested-With`, and the server reads the same
     * string put through the same rule.
     *
     * @return string
     */
    public function serverKey(): string
    {
        return 'HTTP_' . str_replace('-', '_', strtoupper($this->value));
    }
}
