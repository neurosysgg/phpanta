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
 * Read out of the server variables it arrived with — {@link self::from()}, which
 * {@link self::fromGlobals()} hands the process's own — or made up for a static export with
 * {@link self::synthetic()}.
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
     * @param string|null $body The body, where the request was built with one; null to read
     *                          `php://input` — see {@link self::body()}.
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
        private ?string $body = null,
    ) {}

    /**
     * The request this process was started for, read out of PHP's own server variables.
     *
     * @return static
     */
    public static function fromGlobals(): static
    {
        return static::from(ServerParameters::fromGlobals());
    }

    /**
     * The request $server describes.
     *
     * Handles the Authorization header fallback required on some shared hosts
     * where Apache strips PHP_AUTH_* variables before they reach PHP.
     *
     * @param ServerParameters $server The server variables it arrived with.
     * @param string|null      $body   Its body, or null for the one `php://input` holds — which is
     *                                 the only body a request from a real server has, and why only
     *                                 a request built some other way passes one.
     * @return static
     */
    public static function from(ServerParameters $server, ?string $body = null): static
    {
        // tryFrom, not from: REQUEST_METHOD is whatever the client sent, and an unrecognised one
        // has to be refused rather than throw. Null is not read-only, which is the safe default.
        $method   = HttpMethod::tryFrom(strtoupper(
            $server->string(ServerVariable::RequestMethod) ?? HttpMethod::Get->value,
        ));
        $path     = self::normalisePath($server->string(ServerVariable::RequestUri) ?? '/');

        $ajax = RequestedWith::XmlHttpRequest->matches($server->header(RequestHeader::RequestedWith));

        $user = $server->string(ServerVariable::AuthUser)     ?? '';
        $pass = $server->string(ServerVariable::AuthPassword) ?? '';

        $authorization = self::rawAuthorization($server);

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
            $server->header(RequestHeader::IfNoneMatch),
            $server->header(RequestHeader::Range),
            $server->header(RequestHeader::AcceptLanguage),
            $authorization,
            $server->header(RequestHeader::Cookie),
            $server->header(RequestHeader::Referer),
            $body,
        );
    }

    /**
     * A request that never arrived: a `GET` for $path, asking for $language, and nothing else.
     *
     * What a static export renders each page from. It carries no credential, no cookie and no
     * `X-Requested-With`, so every page comes back whole and as an anonymous visitor would see it —
     * which is the only visitor a static host has. The language is asked for the way a browser asks,
     * through `Accept-Language`, so {@link self::language()} answers it when the app offers it and
     * the app's default when it does not.
     *
     * @param string   $path
     * @param Language $language
     * @return static
     */
    public static function synthetic(string $path, Language $language): static
    {
        return new static(
            HttpMethod::Get,
            self::normalisePath($path),
            false,
            '',
            '',
            acceptLanguage: $language->value,
        );
    }

    /**
     * The `Authorization` header, under either of the two names it can arrive as.
     *
     * Not {@link ServerParameters::header()}, because this one is not passed through: Apache deliberately keeps
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
     * @param ServerParameters $server
     * @return string
     */
    private static function rawAuthorization(ServerParameters $server): string
    {
        $names = [ServerVariable::Authorization, ServerVariable::RedirectAuthorization];

        foreach ($names as $variable) {
            if (($value = $server->string($variable)) !== null && $value !== '') {
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
     * for, and it reads `///` as the root written wastefully. See docs/security.md.
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
     * **A target is a path, never an authority.** A request line carries an absolute path, whose
     * segments may be empty, so `//x/posts` is three segments and the first of them is empty.
     * {@link Uri::parse()} reads the same string as a relative reference, where a leading `//` opens
     * an authority: it answers `/posts` for that target, and the page at `/posts` would be served
     * at an address with a host written into it. A target that opens with `//` therefore never reaches
     * the parser and is cut the way an unreadable one is, which keeps every segment — and 404s,
     * because no route has an empty one.
     *
     * Raw, not decoded: a route matches the target as it was sent.
     *
     * @param string $uri The raw request target, as `REQUEST_URI` carries it.
     * @return string
     */
    private static function normalisePath(string $uri): string
    {
        $path = str_starts_with($uri, '//') ? null : Uri::parse($uri)?->getRawPath();

        // `?:` so a target of only slashes comes back as the root rather than as an empty string.
        return rtrim($path ?? self::unparsedPath($uri), '/') ?: '/';
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
     * of `/posts/x"y?a=1` would reach the controller with a slug of `x"y?a=1`, and where a gate
     * names its realm after the slug, a query string would reach a response header. The gate
     * encoding the slug and {@link BasicChallenge} close the other half of that. See
     * docs/security.md.
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
     * **Read once, in {@link self::from()}, and kept.** The two spellings `Authorization`
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
     * **Read here rather than in {@link self::from()}, and that placement is the whole of
     * the care.** Every route but the API's is a read that carries no body; parsing one into every
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
     * A request built with a body of its own — by a test, which has no `php://input` to fill —
     * answers that instead, cut to the same limit.
     *
     * @param int|null $limit The most bytes to read, or null for all of them.
     * @return string
     */
    public function body(?int $limit = null): string
    {
        if ($this->body !== null) {
            return $limit === null ? $this->body : substr($this->body, 0, $limit);
        }

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
     * **And a request that is not a GET**, because RFC 9110 §14.2 has a server ignore `Range` on
     * any other method. A HEAD is answered as the GET without one would be — the whole file's
     * length and a 200 — so a client that asks before it downloads is told how long the file is,
     * not how long the part was.
     *
     * @param int $size The size of the file being asked for.
     * @return ByteRange|null
     */
    public function range(int $size): ?ByteRange
    {
        return $this->method === HttpMethod::Get ? ByteRange::parse($this->rangeHeader, $size) : null;
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
     * **The visitor's own choice first, then their browser's, then the app's.** A `lang` cookie
     * naming a language the app offers is a choice somebody made on this site, so it outranks
     * `Accept-Language`, which is a setting they made once for every site. With neither, the answer
     * is the app's default — the first of {@link \Phpanta\App::languages()}. A cookie naming
     * anything else — `lang=xx`, or a language the framework knows and the app does not offer —
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
