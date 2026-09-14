<?php

declare(strict_types=1);

namespace Phpanta\Http;

use Phpanta\App;
use Phpanta\Exception\InputException;
use Phpanta\Exception\TooLargeException;
use Phpanta\Support\Diagnostics;
use Phpanta\Support\File;
use Phpanta\Text\Language;
use Phpanta\Text\LanguageAddress;
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
     * The most bytes {@link self::form()} reads: a mebibyte, which is a great deal of typing and
     * far less than a host's `post_max_size` would let into memory before anything could refuse it.
     */
    public const int MAX_FORM = 1048576;

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
     * @param string $remoteAddress
     * @param string $query           The target's query as it was sent — see {@link self::rawQuery()}.
     * @param bool   $trailingSlash   Whether the target's path had a slash the path trimmed.
     * @param string $origin          The `Origin` header, raw — see {@link self::origin()}.
     * @param string $preflightMethod The `Access-Control-Request-Method` header, raw.
     * @param string $contentType     What the body is, as its sender says — see {@link self::form()}.
     * @param int    $contentLength   How long the sender says the body is, or 0 where it did not say.
     * @param string|null $body The body, where the request was built with one; null to read
     *                          `php://input` — see {@link self::body()}.
     * @param MultipartParameters $multipart What a multipart body sent, as PHP parsed it.
     * @param string $accept The `Accept` header, raw — see {@link self::accepted()}.
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
        private string $remoteAddress = '',
        private string $query = '',
        private bool   $trailingSlash = false,
        private string $origin = '',
        private string $preflightMethod = '',
        private string $contentType = '',
        private int    $contentLength = 0,
        private ?string $body = null,
        private MultipartParameters $multipart = new MultipartParameters([], []),
        private string $accept = '',
    ) {}

    /**
     * The request this process was started for, read out of PHP's own server variables — and, for
     * a multipart body, out of what PHP parsed it into.
     *
     * @return static
     */
    public static function fromGlobals(): static
    {
        return static::from(ServerParameters::fromGlobals(), null, MultipartParameters::fromGlobals());
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
     * @param MultipartParameters|null $multipart What a multipart body sent, as PHP parsed it, or
     *                                            null for nothing.
     * @return static
     */
    public static function from(
        ServerParameters $server,
        ?string $body = null,
        ?MultipartParameters $multipart = null,
    ): static {
        // tryFrom, not from: REQUEST_METHOD is whatever the client sent, and an unrecognised one
        // has to be refused rather than throw. Null is not read-only, which is the safe default.
        $method   = HttpMethod::tryFrom(strtoupper(
            $server->string(ServerVariable::RequestMethod) ?? HttpMethod::Get->value,
        ));
        $target   = $server->string(ServerVariable::RequestUri) ?? '/';
        $path     = self::normalisePath($target);

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
            $server->string(ServerVariable::RemoteAddress) ?? '',
            self::rawQuery($target),
            self::rawPath($target) !== $path,
            $server->header(RequestHeader::Origin),
            $server->header(RequestHeader::AccessControlRequestMethod),
            $server->string(ServerVariable::ContentType) ?? '',
            // A length that is not a whole number is no length, which is what 0 says.
            (int) filter_var(
                $server->string(ServerVariable::ContentLength) ?? '',
                FILTER_VALIDATE_INT,
                ['options' => ['min_range' => 0]],
            ),
            $body,
            $multipart ?? new MultipartParameters([], []),
            $server->header(RequestHeader::Accept),
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
        // `?:` so a target of only slashes comes back as the root rather than as an empty string.
        return rtrim(self::rawPath($uri), '/') ?: '/';
    }

    /**
     * The target's path as it was sent, trailing slashes and all — what {@link self::normalisePath()}
     * trims, and what {@link self::hasTrailingSlash()} compares against.
     *
     * @param string $uri
     * @return string
     */
    private static function rawPath(string $uri): string
    {
        $path = str_starts_with($uri, '//') ? null : Uri::parse($uri)?->getRawPath();

        return $path ?? self::unparsedPath($uri);
    }

    /**
     * The target's query as it was sent — everything after the first `?` and before any `#` — or
     * `''`.
     *
     * Kept whole and raw, for one purpose: an address sent back to the visitor, like the canonical
     * one {@link self::canonicalTarget()} builds, keeps the query they asked with. Nothing here reads
     * a parameter out of it.
     *
     * @param string $uri
     * @return string
     */
    private static function rawQuery(string $uri): string
    {
        $start = strpos($uri, '?');

        return $start === false ? '' : substr($uri, $start + 1, strcspn($uri, '#', $start + 1));
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
     * Returns true if the method only reads — GET or HEAD. A read-only route refuses any other
     * method with a 405 rather than silently treating it as a GET.
     *
     * @return bool
     */
    public function isReadOnly(): bool     { return $this->method?->isReadOnly() ?? false; }
    /**
     * Returns the normalized request path without trailing slash — and, in an app whose languages
     * have addresses of their own, without the language: `/rules.de.html` is the page `/rules`.
     *
     * @return string
     */
    public function path(): string         { return $this->addressed()?->page ?? $this->path; }
    /**
     * Returns true if the request was made via XMLHttpRequest.
     *
     * @return bool
     */
    public function isAjax(): bool         { return $this->ajax; }
    /**
     * What the `Accept` header asked for. Whatever answers by it owes a `Vary: Accept` — see
     * {@link RequestHeader::Accept}.
     *
     * @return AcceptedTypes
     */
    public function accepted(): AcceptedTypes { return AcceptedTypes::from($this->accept); }
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
     * Compared by {@link ETag::matches()}, the way RFC 9110 reads it — a list, `*`, a `W/` prefix,
     * and the `-gzip` a compressing module appended inside the quotes — since a browser echoes the
     * `ETag` it was given, and the only thing worth asking is whether it is the one we would send now.
     *
     * @return string
     */
    public function ifNoneMatch(): string   { return $this->ifNoneMatch; }

    /**
     * The raw request body, or `''` where there is none.
     *
     * **Read here rather than in {@link self::from()}, and that placement is the whole of
     * the care.** Nearly every request is a read that carries no body; parsing one into every
     * `Request` would make all of them pay for the one that does, and would quietly turn a class
     * that describes a request into one that has consumed it. So this is a method, not a property,
     * and `Request` stays `readonly` with nothing to memoise — `php://input` is re-readable for
     * anything that is not a multipart form. A multipart body is never there at all: PHP parses it
     * before the script runs, and {@link self::form()} and {@link self::upload()} read what it made.
     *
     * **It has two callers**, and each bounds what it reads: {@link \Phpanta\Service\ApiGate}, which
     * refuses every byte unless {@link \Phpanta\Support\PublicKey} says a key this deployment holds
     * signed it, and {@link self::form()}, for a url-encoded form, up to {@link self::MAX_FORM}.
     *
     * **It is read through {@link \Phpanta\Support\File::read()}, bounded by `$limit`**, which is
     * where the diagnostic is handled and where the bound is applied *to the read* rather than
     * after it: an unbounded `file_get_contents('php://input')` pulls up to
     * `post_max_size` into memory before any caller can reject it, so each caller passes the
     * largest body it will consider plus a byte and nothing reads further. `php://input` is a
     * stream `File` reads like any other path — under CLI it is STDIN, which is empty, which is why
     * this is a method and not a property.
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
     * The origin this request says it comes from, or null where it names none — no `Origin`
     * header, a browser's `null`, or anything that is not exactly an origin. See {@link Origin}.
     *
     * @return Origin|null
     */
    public function origin(): ?Origin
    {
        return Origin::tryFrom($this->origin);
    }

    /**
     * True if this is a CORS preflight: an `OPTIONS` carrying `Access-Control-Request-Method`, which
     * asks whether another origin may send that method rather than asking about the resource.
     *
     * @return bool
     */
    public function isPreflight(): bool
    {
        return $this->method === HttpMethod::Options && $this->preflightMethod !== '';
    }

    /**
     * What the query string sent, to be asked for by {@link Parameter} — see {@link Input}.
     *
     * **An API action never calls this**, and a test in the framework's suite reads the API's code to
     * hold it: everything an action may act on is signed, and a query parameter would reach it
     * unsigned.
     *
     * @return Input
     * @throws InputException if what was sent does not decode.
     */
    public function query(): Input
    {
        return Input::fromUrlEncoded($this->query);
    }

    /**
     * What a form sent in the body, to be asked for like {@link self::query()}.
     *
     * Two kinds of body are read, the two an HTML form sends. `application/x-www-form-urlencoded`,
     * the default, is read here, at most {@link self::MAX_FORM} bytes of it, and a form larger than
     * that is refused rather than cut. `multipart/form-data`, how a form sends a file, is read from
     * what PHP parsed it into — see {@link MultipartParameters} — and its files are
     * {@link self::upload()}'s. A request that says nothing about its body has sent no form, and any
     * other kind is refused rather than half-read.
     *
     * An API action never calls this either, for the reason {@link self::query()} gives.
     *
     * @return Input
     * @throws TooLargeException if the form is over {@link self::MAX_FORM}, or PHP emptied a multipart
     *                           one for being over `post_max_size`.
     * @throws InputException    for a body of another kind, or one that does not decode.
     */
    public function form(): Input
    {
        $type = $this->bodyType();

        if ($type === '') {
            return Input::none();
        }

        return match (FormEncoding::tryFrom($type)) {
            FormEncoding::Multipart  => $this->posted(),
            FormEncoding::UrlEncoded => $this->urlEncoded(),
            null                     => throw new InputException(
                sprintf("A body sent as '%s' is not a form this reads.", $type),
            ),
        };
    }

    /**
     * The multipart form PHP parsed, unless PHP emptied it.
     *
     * @return Input
     * @throws TooLargeException
     * @throws InputException
     */
    private function posted(): Input
    {
        $this->refuseEmptied();

        return Input::fromPosted($this->multipart);
    }

    /**
     * The url-encoded form in the body, read no further than {@link self::MAX_FORM} and a byte.
     *
     * @return Input
     * @throws TooLargeException if it is longer than that — a 413, as for a multipart form too large.
     * @throws InputException
     */
    private function urlEncoded(): Input
    {
        $body = $this->body(self::MAX_FORM + 1);

        if (strlen($body) > self::MAX_FORM) {
            throw new TooLargeException(sprintf('A form of more than %d bytes is not read.', self::MAX_FORM));
        }

        return Input::fromUrlEncoded($body);
    }

    /**
     * The file a multipart form sent as $parameter, or null where it sent none — or was no multipart
     * form at all. See {@link Upload}.
     *
     * An API action never calls this either, for the reason {@link self::query()} gives.
     *
     * @param Parameter $parameter
     * @return Upload|null
     * @throws TooLargeException if the file, or the whole form, was larger than the host takes.
     * @throws InputException    if it arrived only in part or as a list.
     * @throws \Phpanta\Exception\UploadException if the host could not keep it.
     */
    public function upload(Parameter $parameter): ?Upload
    {
        if (FormEncoding::tryFrom($this->bodyType()) !== FormEncoding::Multipart) {
            return null;
        }

        $this->refuseEmptied();

        return $this->multipart->upload($parameter);
    }

    /**
     * The essence of what the sender says the body is — `multipart/form-data` out of
     * `multipart/form-data; boundary=…` — lower-cased, or `''` where it says nothing.
     *
     * @return string
     */
    private function bodyType(): string
    {
        return strtolower(trim(explode(';', $this->contentType, 2)[0]));
    }

    /**
     * Refuses a multipart body larger than `post_max_size`.
     *
     * **PHP empties such a form in silence**: a warning in the log, and `$_POST` and `$_FILES` as
     * empty as a form that sent nothing. Read as it stands, it would be a form with every field
     * blank — a page asking the visitor to fill in what they had, or a form-token guard refusing a
     * token that was sent. The length the sender gave is the one sign left, so it is compared here.
     *
     * @return void
     * @throws TooLargeException
     */
    private function refuseEmptied(): void
    {
        $limit = (int) Diagnostics::muted(static fn(): int => ini_parse_quantity((string) ini_get('post_max_size')));

        // 0 is post_max_size's own spelling of no limit.
        if ($limit > 0 && $this->contentLength > $limit) {
            throw new TooLargeException(sprintf('A form over post_max_size (%d bytes) was emptied by PHP.', $limit));
        }
    }

    /**
     * True if the target's path ended in a slash that {@link self::path()} trimmed — `/releases/`,
     * not `/releases`, and never `/` itself.
     *
     * Both spellings reach the same route, which is the forgiving default; a site that wants one
     * address per page lists the {@link \Phpanta\Service\Layer\TrailingSlash} layer, which asks this.
     *
     * @return bool
     */
    public function hasTrailingSlash(): bool
    {
        return $this->trailingSlash;
    }

    /**
     * The address this request would have been sent to without a trailing slash: the path, and the
     * query as it was sent.
     *
     * @return string
     */
    public function canonicalTarget(): string
    {
        return $this->path . ($this->query === '' ? '' : '?' . $this->query);
    }

    /**
     * The address this request came from, as the server reports it — `REMOTE_ADDR` — or `''` where
     * nothing arrived: a synthetic request, a CLI.
     *
     * The peer of the connection, which behind a reverse proxy is the proxy and not the visitor.
     * Nothing here reads `X-Forwarded-For` in its place, because without a proxy the app trusts that
     * header is the client's to write. See {@link \Phpanta\Service\Layer\RateLimit} for the question
     * it answers.
     *
     * @return string
     */
    public function remoteAddress(): string
    {
        return $this->remoteAddress;
    }

    /**
     * True if this request came from this machine: a loopback address, IPv4's `127.0.0.0/8`, IPv6's
     * `::1`, or the first written the way a dual-stack socket reports it, `::ffff:127.x.x.x`.
     *
     * Read as an address rather than matched as a prefix, so `127.0.0.1.example` is not one and
     * neither is anything that does not parse. Nothing that did not arrive — a synthetic request, a
     * CLI — is from loopback either. See {@link \Phpanta\Environment::showsFaultsTo()} for the one
     * question it answers.
     *
     * @return bool
     */
    public function isFromLoopback(): bool
    {
        if (filter_var($this->remoteAddress, FILTER_VALIDATE_IP) === false) {
            return false;
        }

        $packed = (string) inet_pton($this->remoteAddress);
        $mapped = str_repeat("\0", 10) . "\xff\xff";

        return match (strlen($packed)) {
            4       => $packed[0] === "\x7f",
            default => $packed === str_repeat("\0", 15) . "\x01"
                || (str_starts_with($packed, $mapped) && $packed[12] === "\x7f"),
        };
    }

    /**
     * The session this request carried, opened with the app's key — or a fresh one, where it carried
     * none that opens. See {@link Session}.
     *
     * @return Session
     * @throws \Phpanta\Exception\SessionException if the deployment has no session key.
     */
    public function session(): Session
    {
        return Session::of($this, App::current()->sessionSeal());
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
     * **An address that names its language first, then the visitor's own choice, then their
     * browser's, then the app's.** `/rules.de.html`, in an app whose languages have addresses of
     * their own, is the most specific thing anybody can ask for: a link to the German page. A `lang`
     * cookie naming a language the app offers is a choice somebody made on this site, so it outranks
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

        return $this->addressed()?->language
            ?? $languages->tryFrom($this->cookies()->value(CookieName::Language) ?? '')
            ?? $languages->preferredBy($this->acceptedLanguages());
    }

    /**
     * The page and language this request's own path names, where the app gives languages addresses
     * of their own and the path is one — see {@link \Phpanta\Text\LanguageAddresses}.
     *
     * Read from the raw path, so {@link self::canonicalTarget()}, which keeps it, still redirects
     * `/rules.de.html/` to `/rules.de.html` rather than to the page without its language.
     *
     * @return LanguageAddress|null
     */
    private function addressed(): ?LanguageAddress
    {
        return App::current()->languageAddresses()->read($this->path, App::current()->languages());
    }
}
