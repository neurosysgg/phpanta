<?php

declare(strict_types=1);

namespace Phpanta\Http;

/**
 * The ResponseHeader enum. The headers a response sends that are not security headers.
 *
 * Those live in {@link SecurityHeader}, which is deliberately exhaustive — see {@link HeaderName}
 * for why the two lists stay apart.
 */
enum ResponseHeader: string implements HeaderName
{
    /** What the body is, and in which encoding. */
    case ContentType = 'Content-Type';

    /**
     * Which language the body is written in, so a browser, a translation offer and a crawler do
     * not have to guess it from the words.
     *
     * Sent by {@link ViewResponse} with every page, from the page's own language — the same fact
     * `<html lang>` states, once for the document and once for the wire. A fragment has no
     * `<html>` to carry it, so for `Navigation`'s fetches this is the only statement of it.
     */
    case ContentLanguage = 'Content-Language';

    /** Where a redirect points. */
    case Location = 'Location';

    /**
     * A cookie for the browser to keep.
     *
     * Two cookies, and {@link SetCookie} builds both — see it for the attributes each carries and
     * why: the language a visitor chose, sent only because they clicked a switch, and the sealed
     * session, sent only by an answer a {@link Session} was attached to.
     */
    case SetCookie = 'Set-Cookie';

    /** Which methods a route accepts, sent with a 405. */
    case Allow = 'Allow';

    /** The Basic Auth challenge, sent with a 401. */
    case WwwAuthenticate = 'WWW-Authenticate';

    /**
     * Whether a response may be reused, and on what terms.
     *
     * Two answers in the main, and they are opposites. A public document, a JSON answer and a
     * sitemap say `no-cache`, which is not `no-store`: keep it, but ask before reusing it — see
     * {@link ViewResponse}. A page behind a password, a refusal, a file and a fault say
     * `no-store, private` instead — {@link CacheControl::doNotStore()}. A {@link StreamResponse}
     * says `no-store` alone.
     */
    case CacheControl = 'Cache-Control';

    /**
     * A validator for the body, so a revalidation can come back as a 304 instead of the page.
     *
     * {@link ViewResponse} hashes what it is about to send. That is the whole trick behind the
     * caching here: a document embeds every versioned asset URL, so a rebuild changes the body,
     * which changes this, which retires the cached copy — no coupling to the build stamp needed,
     * because the dependency is already in the bytes.
     */
    case ETag = 'ETag';

    /**
     * Which request headers the stored response depends on.
     *
     * Load-bearing here rather than decorative: {@link ViewResponse} answers one URL with two
     * different bodies depending on `X-Requested-With` — a whole document to a browser, a
     * fragment to `Navigation`. Without this a cache is entitled to hand either one to the other,
     * and the fragment landing on a navigation is a blank page. It was harmless while nothing
     * cached; it stopped being harmless the moment {@link self::ETag} appeared.
     */
    case Vary = 'Vary';

    /**
     * That this response may be asked for in pieces, and in which unit.
     *
     * Sent by {@link FileResponse} and by nothing else, because it is the only response here whose
     * body is a file rather than a rendered page. An `<audio>` element reads it before it will let
     * anyone drag the scrubber — see {@link AcceptRanges}.
     */
    case AcceptRanges = 'Accept-Ranges';

    /**
     * How many bytes the body is.
     *
     * PHP works this out on its own for everything else here. A ranged response has to say it,
     * because the number is the length of the *part* and not of the file.
     */
    case ContentLength = 'Content-Length';

    /**
     * Which part of the file a 206 carries — or, on a 416, how long the file actually is.
     *
     * One header name with two grammars, which is why the value is a {@link ContentRange} and not
     * a string assembled where it is sent.
     */
    case ContentRange = 'Content-Range';

    /**
     * What a crawler may do with this response.
     *
     * Sent only on gated routes that ask for it. It is not what keeps them out of an index — a crawler is
     * answered with a 401 and never sees a page — see {@link RobotsPolicy} for the narrower gap
     * this actually covers, and for why `robots.txt` would be the wrong tool.
     */
    case Robots = 'X-Robots-Tag';

    /**
     * Which other origin may read this response — sent by the {@link \Phpanta\Service\Layer\Cors}
     * layer, naming the one origin that asked, and only when it is one the site lists.
     */
    case AccessControlAllowOrigin = 'Access-Control-Allow-Origin';

    /**
     * Which methods a cross-origin request may use — the answer to a preflight, from the same layer.
     */
    case AccessControlAllowMethods = 'Access-Control-Allow-Methods';

    /**
     * How long a refused client should wait before asking again — sent with the 429 the
     * {@link \Phpanta\Service\Layer\RateLimit} layer answers, in seconds; see {@link RetryAfter}.
     */
    case RetryAfter = 'Retry-After';

    /**
     * The one case here that names a header the site does **not** send.
     *
     * PHP adds it, with its exact patch version, before any of our code runs.
     * {@link SecurityHeaders::send()} removes it. It is named here rather than written as a
     * string literal there for the same reason every other header name is.
     */
    case PoweredBy = 'X-Powered-By';

    /**
     * @return string
     */
    public function headerName(): string
    {
        return $this->value;
    }
}
