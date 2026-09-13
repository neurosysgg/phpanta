<?php

declare(strict_types=1);

namespace Phpanta\Http;

use Phpanta\App;
use Phpanta\Support\File;
use Phpanta\Text\Language;
use Uri\Rfc3986\Uri;

/**
 * The Request class. Represents an incoming HTTP request.
 *
 * Constructed from PHP's global server variables via {@link fromGlobals()}.
 */
readonly class Request
{
    /**
     * Constructs an instance of {@link self}.
     *
     * @param ?HttpMethod $method
     * @param string $path
     * @param bool $ajax
     * @param string $authUser
     * @param string $authPassword
     * @param string $ifNoneMatch
     * @param string $rangeHeader
     * @param string $acceptLanguage
     * @param string $authorization
     * @param string $cookie
     * @param string $referer
     */
    private function __construct(
        private ?HttpMethod $method,
        private string $path,
        private bool   $ajax,
        private string $authUser,
        private string $authPassword,
        private string $ifNoneMatch = '',
        private string $rangeHeader = '',
        private string $acceptLanguage = '',
        private string $authorization = '',
        private string $cookie = '',
        private string $referer = '',
    ) {}

    /**
     * Creates an instance from PHP's global server variables.
     *
     * Handles the Authorization header fallback required on some shared hosts
     * where Apache strips PHP_AUTH_* variables before they reach PHP.
     *
     * @return static
     */
    public static function fromGlobals(): static
    {
        // tryFrom, not from: REQUEST_METHOD is whatever the client sent, and an unrecognised one
        // has to be refused rather than throw. Null is not read-only, which is the safe default.
        $method   = HttpMethod::tryFrom(strtoupper(
            ServerVariable::RequestMethod->string() ?? HttpMethod::Get->value,
        ));
        $path     = self::normalisePath(ServerVariable::RequestUri->string() ?? '/');

        $ajax = RequestedWith::XmlHttpRequest->matches(self::header(RequestHeader::RequestedWith));

        $user = ServerVariable::AuthUser->string()     ?? '';
        $pass = ServerVariable::AuthPassword->string() ?? '';

        $authorization = self::rawAuthorization();

        // Authorization header fallback for hosts that strip PHP_AUTH_* vars. The scheme and the
        // grammar under it are AuthScheme's, so the token this matches on is the same case
        // BasicChallenge writes into the 401 that asked for it.
        if ($user === '' && AuthScheme::Basic->carries($authorization)) {
            [$user, $pass] = AuthScheme::Basic->credentials($authorization);
        }

        return new static(
            $method,
            $path,
            $ajax,
            $user,
            $pass,
            self::header(RequestHeader::IfNoneMatch),
            self::header(RequestHeader::Range),
            self::header(RequestHeader::AcceptLanguage),
            $authorization,
            self::header(RequestHeader::Cookie),
            self::header(RequestHeader::Referer),
        );
    }

    /**
     * One request header's value, or `''` if it did not arrive.
     *
     * The `$_SERVER` key is derived from the {@link RequestHeader} case rather than retyped —
     * `HTTP_` plus the name upper-cased with dashes as underscores, which is PHP's transform and
     * not ours. That is the whole reason the header names are an enum: the client sends
     * `X-Requested-With` and this reads the same string, put through the same rule.
     *
     * @param RequestHeader $header
     * @return string
     */
    private static function header(RequestHeader $header): string
    {
        $key = 'HTTP_' . str_replace('-', '_', strtoupper($header->value));

        return isset($_SERVER[$key]) && is_string($_SERVER[$key]) ? $_SERVER[$key] : '';
    }

    /**
     * The `Authorization` header, under either of the two names it can arrive as.
     *
     * Not {@link self::header()}, because this one is not passed through: Apache deliberately keeps
     * `Authorization` out of the CGI environment, so `public/.htaccess` puts it back with
     * `RewriteRule ^ - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]`. An environment variable set
     * before an internal redirect arrives on the other side renamed with a `REDIRECT_` prefix, and
     * the request reaches PHP through exactly such a redirect — the rewrite to `index.php`.
     *
     * That rule's pattern is `^`, so in practice it fires again on the redirected request and the
     * unprefixed name is defined too. In practice, though, is not a good enough standard for the
     * one header both auth gates depend on: this fails **closed** and in silence — a 401 that
     * looks exactly like a wrong password. So both spellings are read, the way the cache tiers in
     * the same file set both `VERSIONED` and `REDIRECT_VERSIONED` for the same reason.
     *
     * Both are {@link ServerVariable} cases rather than the string pair they were, because a name
     * Apache invents is one nothing can derive and so one nothing can check — see that enum.
     *
     * @return string
     */
    private static function rawAuthorization(): string
    {
        $names = [ServerVariable::Authorization, ServerVariable::RedirectAuthorization];

        foreach ($names as $variable) {
            if (($value = $variable->string()) !== null && $value !== '') {
                return $value;
            }
        }

        return '';
    }

    /**
     * The request target's path, with any trailing slash taken off.
     *
     * **Never `parse_url()` here.** It signals failure with **false**, not null, so `??` does not
     * guard it, and under `strict_types=1` the false reaches `rtrim()` as an uncaught TypeError — a
     * 500 for a target as ordinary as `GET ///`, raised in {@link self::fromGlobals()}, ahead of
     * {@link \Phpanta\Router::dispatch()} and so ahead of the method gate too. PHP 8.5's
     * {@link Uri::parse()} returns **null** on a target it cannot read, which is what `??` looks
     * for, and it reads `///` as the root written wastefully. See docs/history/security.md.
     *
     * **The fallback is the target, not `/`.** A target this could not read is not a request for
     * the home page, and answering one with the home page is the quiet kind of wrong. Same instinct
     * as `HttpMethod::tryFrom()` returning null rather than guessing GET. An *absent* `REQUEST_URI`
     * is the different case and is still the root — that default is applied by the caller, before
     * this ever sees it.
     *
     * It is the target's **path** rather than the whole of it — {@link self::unparsedPath()} says
     * why.
     *
     * Raw, not decoded: a route matches the target as it was sent.
     *
     * @param string $uri The raw request target, as `REQUEST_URI` carries it.
     * @return string
     */
    private static function normalisePath(string $uri): string
    {
        // `?:` so a target of only slashes comes back as the root rather than as an empty string.
        return rtrim(Uri::parse($uri)?->getRawPath() ?? self::unparsedPath($uri), '/') ?: '/';
    }

    /**
     * The path of a request target {@link Uri::parse()} could not read.
     *
     * Everything up to the first `?` or `#`, because that is where a path ends in a target however
     * malformed the rest of it turns out to be. It is what {@link Uri::getRawPath()} would have
     * answered had the parse succeeded, which is the whole job of a fallback.
     *
     * **Only the path, because a malformed target is not a 404.** {@link \Phpanta\Support\Route::matches()}
     * compiles `{slug}` into `([^/]+)`, which matches anything at all, so every placeholder route
     * matches one, and whatever followed the `?` would arrive inside a captured value: the whole
     * of `/demos/x"y?a=1` would reach {@link \NeuroSYS\Controller\DemoController} with a slug of
     * `x"y?a=1`, and a demo's slug names its realm, so a query string would reach a response
     * header. {@link \Phpanta\Service\Auth::demoRealm()} and {@link BasicChallenge} close the
     * other half of that. See docs/history/security.md.
     *
     * @param string $uri
     * @return string
     */
    private static function unparsedPath(string $uri): string
    {
        return substr($uri, 0, strcspn($uri, '?#'));
    }

    /**
     * Returns the HTTP method, or null if it is not one {@link HttpMethod} recognises.
     *
     * @return ?HttpMethod
     */
    public function method(): ?HttpMethod  { return $this->method; }
    /**
     * Returns true if the method only reads. The whole site is read-only, so everything
     * else is refused with a 405 rather than silently treated as a GET.
     *
     * @return bool
     */
    public function isReadOnly(): bool     { return $this->method?->isReadOnly() ?? false; }
    /**
     * Returns the normalized request path without trailing slash.
     *
     * @return string
     */
    public function path(): string         { return $this->path; }
    /**
     * Returns true if the request was made via XMLHttpRequest.
     *
     * @return bool
     */
    public function isAjax(): bool         { return $this->ajax; }
    /**
     * Returns the HTTP Basic Auth username, or an empty string if not provided.
     *
     * @return string
     */
    public function authUser(): string     { return $this->authUser; }
    /**
     * Returns the HTTP Basic Auth password, or an empty string if not provided.
     *
     * @return string
     */
    public function authPassword(): string { return $this->authPassword; }
    /**
     * The credential this request carries in $scheme, or null if it carries one in another scheme
     * or none at all.
     *
     * **Read once, in {@link self::fromGlobals()}, and kept.** The two spellings `Authorization`
     * arrives under — Apache keeps it out of the CGI environment and `public/.htaccess` puts it
     * back, where an internal redirect renames it — are dealt with in one place, by
     * {@link self::rawAuthorization()}. A second reader going to {@link ServerVariable} for itself
     * would be that loop written again in another file, which is exactly the drift
     * {@link ServerVariable::RedirectAuthorization}'s docblock exists to prevent, on the header
     * whose failure this class already calls the quietest on the site.
     *
     * The raw value is deliberately **not** exposed. What a caller may have is the parameters of a
     * scheme it asked for by name, so a reader of one scheme cannot be handed another's credential
     * and make what it likes of it — the mistake {@link AuthScheme::credentials()} guards from the
     * other side.
     *
     * @param AuthScheme $scheme
     * @return string|null
     */
    public function credential(AuthScheme $scheme): ?string
    {
        return $scheme->parameters($this->authorization);
    }

    /**
     * Returns the `If-None-Match` validator the browser sent back, or `''` if it sent none.
     *
     * Compared verbatim by {@link ViewResponse}: a browser echoes the `ETag` it was given, and the
     * only thing worth asking is whether it is the one we would send now.
     *
     * @return string
     */
    public function ifNoneMatch(): string   { return $this->ifNoneMatch; }

    /**
     * The raw request body, or `''` where there is none.
     *
     * **Read here rather than in {@link self::fromGlobals()}, and that placement is the whole of
     * the care.** Nine of the ten routes are reads that carry no body; parsing one into every
     * `Request` would make all of them pay for the one that does, and would quietly turn a class
     * that describes a request into one that has consumed it. So this is a method, not a property,
     * and `Request` stays `readonly` with nothing to memoise — `php://input` is re-readable for
     * anything that is not a multipart form, and nothing here posts a form.
     *
     * **It has exactly one caller**, {@link \Phpanta\Controller\ApiController}, and that is the
     * guarantee: the body is read at one call site, past a route that accepts POST and nothing else
     * does, and every byte of it is refused unless {@link \Phpanta\Support\PublicKey} says it was
     * signed by a key this deployment holds.
     *
     * **It is read through {@link \Phpanta\Support\File::read()}, bounded by `$limit`**, which is
     * where the diagnostic is handled and where the bound is applied *to the read* rather than
     * after it: an unbounded `file_get_contents('php://input')` pulls up to
     * `post_max_size` into memory before any caller can reject it, so the one caller,
     * {@link \Phpanta\Service\ApiGate}, passes the largest body it will consider plus a byte and
     * reads no further. `php://input` is a stream `File` reads like any other path — under CLI it is
     * STDIN, which is empty, which is why this is a method and not a property.
     *
     * @param int|null $limit The most bytes to read, or null for all of them.
     * @return string
     */
    public function body(?int $limit = null): string
    {
        return (string) new File('php://input')->read($limit);
    }

    /**
     * The bytes this request asked for out of a file $size long, or null where it asked for all of
     * them.
     *
     * The size is a parameter rather than something this could know, and that is the whole reason
     * the raw header is kept and not the parsed value: `bytes=-500` means "the last 500", which is
     * a different pair of offsets for every file. A request is not the place that knows how long
     * anything is — {@link FileResponse} is, so it asks.
     *
     * Null covers both "no `Range` header" and "one this does not read", because
     * {@link ByteRange::parse()} answers the same way for both and the caller does the same thing
     * either way: send the whole file. See that class for why that is a legal answer and not a
     * shortcut.
     *
     * @param int $size The size of the file being asked for.
     * @return ByteRange|null
     */
    public function range(int $size): ?ByteRange
    {
        return ByteRange::parse($this->rangeHeader, $size);
    }

    /**
     * Which languages this visitor would rather read.
     *
     * Parsed rather than kept raw, unlike {@link self::$rangeHeader} above — and the difference is
     * instructive. A `Range` cannot be read without knowing how long the file is, which a request
     * has no way to know; an `Accept-Language` is a complete statement on its own, and what varies
     * per caller is only which languages that page has to offer. So the parse happens here and the
     * choosing happens at {@link AcceptedLanguages::preferred()}.
     *
     * @return AcceptedLanguages
     */
    public function acceptedLanguages(): AcceptedLanguages
    {
        return AcceptedLanguages::from($this->acceptLanguage);
    }

    /**
     * The `Referer` this request carried, raw, or `''`.
     *
     * Raw because its one reader takes a single part of it and checks that — see
     * {@link RequestHeader::Referer} for which reader, and for how little of it is used.
     *
     * @return string
     */
    public function referer(): string
    {
        return $this->referer;
    }

    /**
     * The cookies this request carries, to be asked for by name.
     *
     * @return RequestCookies
     */
    public function cookies(): RequestCookies
    {
        return RequestCookies::from($this->cookie);
    }

    /**
     * The language this request is answered in.
     *
     * **The visitor's own choice first, then their browser's, then the site's.** A `lang` cookie
     * naming a language this site offers is a choice somebody made on this site, so it outranks
     * `Accept-Language`, which is a setting they made once for every site. With neither, the answer
     * is the app's default — the first of {@link \Phpanta\App::languages()}. A cookie naming
     * anything else — `lang=xx`, or a language the framework knows and this site does not write —
     * is no choice at all and falls through rather than failing.
     *
     * **A page answered in this owes a `Vary` on both headers** — see
     * {@link \Phpanta\View\View::varyOn()}.
     *
     * @return Language
     */
    public function language(): Language
    {
        $languages = App::current()->languages();

        return $languages->tryFrom($this->cookies()->value(CookieName::Language) ?? '')
            ?? $languages->preferredBy($this->acceptedLanguages());
    }
}
