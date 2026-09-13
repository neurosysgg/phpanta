<?php

declare(strict_types=1);

namespace Phpanta\Http;

/**
 * The ServerVariable enum. The `$_SERVER` keys the framework names outright.
 *
 * Every reader of `$_SERVER` here ends in a default — `?? 'GET'`, `?? '/'`, `?? ''` — because a
 * key that did not arrive is an ordinary thing rather than an error. That is exactly what makes a
 * misspelled key **silent**: it is indistinguishable from a request that simply did not carry the
 * value, so there is no line in any log and nothing in the response to read the mistake off. The
 * {@link self::AuthUser} pair is the case that matters most, and it fails *closed* — see its note.
 *
 * **Not every `$_SERVER` key belongs here, and the rule is which name is ours.** A request header
 * arrives under `HTTP_` plus the name upper-cased with dashes as underscores; that is PHP's
 * transform, so {@link Request::header()} applies it to a {@link RequestHeader} case rather than
 * anybody retyping the result. A case earns a place here when that derivation cannot reach the
 * name ({@link self::RedirectAuthorization}) or when the reader has no {@link Request} to ask
 * ({@link self::Referer}).
 */
enum ServerVariable: string
{
    /**
     * The request verb.
     *
     * {@link HttpMethod::tryFrom()} has always guarded the *value* — an unrecognised verb is null
     * and null is not read-only — while nothing guarded the key it was read under. A typo there
     * answers null too, so the read-only gate refuses every request on the site with a 405.
     */
    case RequestMethod = 'REQUEST_METHOD';

    /** The request target, before {@link Request::normalisePath()} has anything to say about it. */
    case RequestUri = 'REQUEST_URI';

    /**
     * The credentials Apache decoded out of a `Basic` challenge.
     *
     * **The one place a typo here fails closed.** An empty user is the signal
     * {@link Request::fromGlobals()} uses to fall back to the raw `Authorization` header, and if
     * that is absent too the value stays `''` — which no stored credential equals. Every gate then
     * refuses everything, with a 401 that looks precisely like a wrong password. Read the paragraph
     * on {@link self::RedirectAuthorization}: a gate is lost exactly this way to a name it reads
     * under and does not check.
     */
    case AuthUser = 'PHP_AUTH_USER';

    /** The password half of {@link self::AuthUser}, and PHP abbreviates it. */
    case AuthPassword = 'PHP_AUTH_PW';

    /**
     * The `Authorization` header, put back into the environment by hand.
     *
     * Apache deliberately keeps this one out of the CGI environment, so `public/.htaccess` restores
     * it with `RewriteRule ^ - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]`. It is a header that
     * arrives under the derivable name, but not *because* of the derivation — nothing would put it
     * there unaided, which is why it is named here beside the spelling it can also arrive as.
     */
    case Authorization = 'HTTP_AUTHORIZATION';

    /**
     * The same header, seen from the far side of an internal redirect.
     *
     * An environment variable set before a rewrite arrives renamed with a `REDIRECT_` prefix, and
     * every request here reaches PHP through exactly such a rewrite. No transform of a header name
     * produces this, which is the whole reason it is a case: it is a name of Apache's rather than
     * of HTTP's, and {@link Request::rawAuthorization()} reads both spellings because getting this
     * one wrong is a 401 on every page with nothing to distinguish it from a bad password.
     */
    case RedirectAuthorization = 'REDIRECT_HTTP_AUTHORIZATION';

    /**
     * The page a link was followed from, and the one case that could have been derived.
     *
     * It is not, because its reader is one with no {@link Request} in its hand — a logger a site
     * switches on behind a guard, whose read has to stay *behind* that guard, where a value passed
     * as an argument is evaluated in front of it. With the guard off it reads nothing at all, and a
     * test can assert that, which an argument would quietly falsify.
     *
     * Note the spelling. The header lost an `r` in 1996 and kept the loss, and the property it
     * fills is usually spelled `referrer`, correctly, a few lines away. That is the whole argument
     * for naming it here.
     */
    case Referer = 'HTTP_REFERER';

    /**
     * What the web server calls itself, and the first of two cases read by something with no
     * request in its hand.
     *
     * A name of CGI's rather than of HTTP's, so the `HTTP_` derivation cannot reach it — the first
     * clause of the membership rule above. {@link \Phpanta\Service\Api\CapabilityRuntime} is the
     * reader, and it satisfies the second clause as well: an {@link \Phpanta\Http\Api\ApiHandler}
     * takes no {@link Request} by construction, because everything a handler may act on is signed
     * and reaching back for the unsigned request would be reaching around the gate.
     *
     * Absent on CLI, like {@link self::ServerProtocol} and {@link self::DocumentRoot} — which is
     * what that report's `?? ''` is for, and why a dash there means "not served by a web server"
     * rather than "misread".
     */
    case ServerSoftware = 'SERVER_SOFTWARE';

    /**
     * Which HTTP version the request arrived on, and the second of that pair.
     *
     * Worth reporting because whether a host serves HTTP/2, HTTP/3 or neither is a fact about a
     * shared host that can change without anybody being told, the way a compression module can
     * come and go between two consecutive days.
     */
    case ServerProtocol = 'SERVER_PROTOCOL';

    /**
     * The webroot's absolute path, and the only fact here that no derivation can reach.
     *
     * {@link \Phpanta\App::webroot()} needs the webroot's directory *name* — `public/` in the
     * repository, whatever the host calls it on the live one — and no source file can know which.
     * Only the server does, which is exactly the membership rule this enum states: a case belongs
     * when the `HTTP_` derivation cannot reach the name. It is not an HTTP header and no request
     * can set it.
     *
     * Read for its basename alone. The whole string is *not* interchangeable with a path built from
     * `__DIR__`: a shared host can reach one directory through two mounts, so this reads under one
     * prefix while `__DIR__` for a file in the same directory reads under another. See that method,
     * which is where the consequence of mixing them is written down.
     */
    case DocumentRoot = 'DOCUMENT_ROOT';

    /**
     * This variable's value, or null if it did not arrive.
     *
     * Null for a key that is absent *and* for one holding something other than a string, which is
     * the same collapse {@link Request} made in its own words at three call sites. Callers supply
     * their own default, because the right one differs: a missing method reads as `GET`, a missing
     * target as `/`, and a missing header as `''`.
     *
     * @return string|null
     */
    public function string(): ?string
    {
        return isset($_SERVER[$this->value]) && is_string($_SERVER[$this->value])
            ? $_SERVER[$this->value]
            : null;
    }
}
