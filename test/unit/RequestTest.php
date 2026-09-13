<?php

declare(strict_types=1);

namespace Phpanta\Test\Unit;

use Phpanta\Http\AcceptedLanguages;
use Phpanta\Http\AuthScheme;
use Phpanta\Http\BasicChallenge;
use Phpanta\Http\CookieName;
use Phpanta\Http\Request;
use Phpanta\Http\RequestCookies;
use Phpanta\Http\RequestedWith;
use Phpanta\Http\RequestHeader;
use Phpanta\Http\ServerVariable;
use Phpanta\Text\Language;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(Request::class)]
#[CoversClass(AcceptedLanguages::class)]
#[CoversClass(RequestCookies::class)]
#[CoversClass(AuthScheme::class)]
#[CoversClass(RequestHeader::class)]
#[CoversClass(RequestedWith::class)]
#[CoversClass(ServerVariable::class)]
final class RequestTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $serverBackup;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        $this->serverBackup = $_SERVER;
    }

    /**
     * @return void
     */
    protected function tearDown(): void
    {
        $_SERVER = $this->serverBackup;
    }

    /**
     * @param array<string, string> $server
     * @return Request
     */
    private function request(array $server): Request
    {
        $_SERVER = $server;
        return Request::fromGlobals();
    }

    /**
     * @return iterable
     */
    public static function pathProvider(): iterable
    {
        yield 'root'                  => ['/', '/'];
        yield 'trailing slash dropped' => ['/posts/', '/posts'];
        yield 'nested trailing slash'  => ['/posts/x/', '/posts/x'];
        yield 'query string stripped'  => ['/posts?sort=new', '/posts'];
        yield 'fragment stripped'      => ['/posts#top', '/posts'];
        yield 'bare slash stays root'  => ['/', '/'];
        yield 'deep path'              => ['/posts/x/y', '/posts/x/y'];

        // Targets parse_url() cannot read. It signals failure with false, not null, so `?? '/'`
        // would not guard it: the false would reach rtrim() as an uncaught TypeError under
        // strict_types, a 500 in fromGlobals() ahead of the read-only gate. They are ordinary
        // request targets — the first is three slashes. See docs/security.md.
        //
        // Note the two different answers. `///` is the root written wastefully, and comes back as
        // the root. The second is a target we could not read, and comes back as itself rather than
        // as the home page, which would be the quieter wrong.
        yield 'only slashes'              => ['///', '/'];
        yield 'unparseable authority'     => ['//host:notaport/x', '//host:notaport/x'];
        yield 'unparseable, trailing slash' => ['//host:notaport/x/', '//host:notaport/x'];
        yield 'empty'                     => ['', '/'];

        // A target the parser refuses is still cut at the `?` or the `#`. A malformed target still
        // matches a route — Route compiles `{slug}` to `([^/]+)`, which matches anything — so an
        // uncut `/notes/x"y?a=1` would reach its controller with a slug of `x"y?a=1`, and a slug
        // that reaches a header would carry the query string into it. See Request::unparsedPath().
        yield 'unparseable, query cut'    => ['//host:notaport/x?a=1', '//host:notaport/x'];
        yield 'unparseable, fragment cut' => ['//host:notaport/x#top', '//host:notaport/x'];
        yield 'raw quote, query cut'      => ['/notes/x"y?a=1&b=2', '/notes/x"y'];
        yield 'query only'                => ['/?a=1', '/'];

        // A target is a path, never an authority. Read as a relative reference, `//x/posts` is the
        // host `x` and the path `/posts`, and the posts page answered at an address with a host
        // inside it; `//whatever` was the host `whatever` and the path '', which is the root.
        // Kept whole, both are paths no route has, and both 404.
        yield 'a leading double slash is a path' => ['//whatever', '//whatever'];
        yield 'and does not alias another page'  => ['//x/posts', '//x/posts'];
        yield 'with its query cut'               => ['//x/posts?a=1', '//x/posts'];
        yield 'an IPv6 literal is not a host'    => ['//[::1]/posts', '//[::1]/posts'];
        yield 'two slashes stay the root'        => ['//', '/'];
    }

    /**
     * @param string $uri
     * @param string $expected
     * @return void
     */
    #[DataProvider('pathProvider')]
    public function testNormalisesPath(string $uri, string $expected): void
    {
        self::assertSame($expected, $this->request(['REQUEST_URI' => $uri])->path());
    }

    /**
     * @return void
     */
    public function testDefaultsToRootWhenRequestUriIsAbsent(): void
    {
        self::assertSame('/', $this->request([])->path());
    }

    /**
     * A `Range` is read on a GET and nowhere else — RFC 9110 §14.2 — so a HEAD is answered as the
     * GET without one would be: the whole length and a 200, never a 206 describing the part.
     *
     * @return void
     */
    public function testARangeIsReadOnAGetOnly(): void
    {
        $asked = ['REQUEST_URI' => '/notes/x/v1', 'HTTP_RANGE' => 'bytes=3-5'];

        self::assertSame(3, $this->request($asked)->range(10)?->first);
        self::assertSame(3, $this->request($asked + ['REQUEST_METHOD' => 'GET'])->range(10)?->first);
        self::assertNull($this->request($asked + ['REQUEST_METHOD' => 'HEAD'])->range(10));
        self::assertNull($this->request($asked + ['REQUEST_METHOD' => 'POST'])->range(10));
    }

    /**
     * @return void
     */
    public function testDetectsAjaxRequestCaseInsensitively(): void
    {
        self::assertTrue($this->request([
            'REQUEST_URI'          => '/',
            'HTTP_X_REQUESTED_WITH' => 'XmlHttpRequest',
        ])->isAjax());
    }

    /**
     * @return void
     */
    public function testIsNotAjaxWithoutTheHeader(): void
    {
        self::assertFalse($this->request(['REQUEST_URI' => '/'])->isAjax());
    }

    /**
     * @return void
     */
    public function testIsNotAjaxForSomeOtherRequestedWithValue(): void
    {
        self::assertFalse($this->request([
            'REQUEST_URI'           => '/',
            'HTTP_X_REQUESTED_WITH' => 'fetch',
        ])->isAjax());
    }

    /**
     * @return void
     */
    public function testReadsPhpAuthVariablesWhenPresent(): void
    {
        $request = $this->request([
            'REQUEST_URI'   => '/admin',
            'PHP_AUTH_USER' => 'admin',
            'PHP_AUTH_PW'   => 'hunter2',
        ]);

        self::assertSame('admin', $request->authUser());
        self::assertSame('hunter2', $request->authPassword());
    }

    /**
     * Some hosts strip PHP_AUTH_* before it reaches PHP, so .htaccess forwards the raw
     * Authorization header instead. Without this fallback a page behind Basic auth is
     * unreachable on such a host while working fine locally.
     *
     * @return void
     */
    public function testFallsBackToTheAuthorizationHeader(): void
    {
        $request = $this->request([
            'REQUEST_URI'        => '/admin',
            'HTTP_AUTHORIZATION' => 'Basic ' . base64_encode('admin:hunter2'),
        ]);

        self::assertSame('admin', $request->authUser());
        self::assertSame('hunter2', $request->authPassword());
    }

    /**
     * @return void
     */
    public function testAuthorizationHeaderPasswordMayContainColons(): void
    {
        $request = $this->request([
            'REQUEST_URI'        => '/admin',
            'HTTP_AUTHORIZATION' => 'Basic ' . base64_encode('admin:a:b:c'),
        ]);

        self::assertSame('admin', $request->authUser());
        self::assertSame('a:b:c', $request->authPassword());
    }

    /**
     * @return void
     */
    public function testAuthorizationHeaderWithoutAColonYieldsAnEmptyPassword(): void
    {
        $request = $this->request([
            'REQUEST_URI'        => '/admin',
            'HTTP_AUTHORIZATION' => 'Basic ' . base64_encode('adminonly'),
        ]);

        self::assertSame('adminonly', $request->authUser());
        self::assertSame('', $request->authPassword());
    }

    /**
     * @return void
     */
    public function testIgnoresNonBasicAuthorizationSchemes(): void
    {
        $request = $this->request([
            'REQUEST_URI'        => '/admin',
            'HTTP_AUTHORIZATION' => 'Bearer some-token',
        ]);

        self::assertSame('', $request->authUser());
        self::assertSame('', $request->authPassword());
    }

    /**
     * @return void
     */
    public function testGarbageInTheAuthorizationHeaderDoesNotCrash(): void
    {
        $request = $this->request([
            'REQUEST_URI'        => '/admin',
            'HTTP_AUTHORIZATION' => 'Basic !!!not-base64!!!',
        ]);

        self::assertSame('', $request->authPassword());
    }

    /**
     * @return void
     */
    public function testPhpAuthUserWinsOverTheHeaderFallback(): void
    {
        $request = $this->request([
            'REQUEST_URI'        => '/admin',
            'PHP_AUTH_USER'      => 'real',
            'PHP_AUTH_PW'        => 'realpass',
            'HTTP_AUTHORIZATION' => 'Basic ' . base64_encode('spoofed:spoofedpass'),
        ]);

        self::assertSame('real', $request->authUser());
        self::assertSame('realpass', $request->authPassword());
    }

    /**
     * @return void
     */
    public function testCredentialsAreEmptyWhenNoneAreSupplied(): void
    {
        $request = $this->request(['REQUEST_URI' => '/']);

        self::assertSame('', $request->authUser());
        self::assertSame('', $request->authPassword());
    }

    /**
     * The body is read from `php://input`, and the read is bounded by the limit it is given.
     *
     * No more than that many bytes are ever pulled in, which is what keeps
     * {@link \Phpanta\Service\ApiGate} from inheriting `post_max_size` as its true ingress cap.
     * Null reads whatever is there, and asking twice is allowed — the stream is re-readable, which
     * is why {@link Request::body()} is a method rather than a memoised property.
     *
     * @return void
     */
    public function testTheBodyIsReadFromPhpInputBoundedByTheLimit(): void
    {
        $request = $this->request(['REQUEST_URI' => '/']);

        PhpInputStream::around('0123456789', function () use ($request): void {
            self::assertSame('0123456789', $request->body(), 'null did not read the whole body');
            self::assertSame('0123', $request->body(4), 'the read ran past its limit');
        });
    }

    // ───────────────────── the header that asks for a fragment ─────────────────────

    /**
     * The worst name on the site to get wrong. Drift on either side and the server answers a SPA
     * fetch with a whole document, which Navigation then writes into <main> — a page broken in a
     * way nothing reports. `assets/ts/model/RequestHeader.ts` mirrors this and the parity test
     * compares them; what belongs here is that the wire name is the value.
     *
     * @return void
     */
    public function testTheRequestedWithHeaderIsNamedAsItGoesOnTheWire(): void
    {
        self::assertSame('X-Requested-With', RequestHeader::RequestedWith->headerName());
        self::assertSame(RequestHeader::RequestedWith->value, RequestHeader::RequestedWith->headerName());
    }

    /**
     * fromGlobals() derives the $_SERVER key from the case rather than retyping it, because that
     * transform is PHP's rather than ours. This is the derivation, spelled out once.
     *
     * @return void
     */
    public function testTheServerKeyIsDerivedFromTheHeaderName(): void
    {
        self::assertSame(
            'HTTP_X_REQUESTED_WITH',
            'HTTP_' . RequestHeader::RequestedWith->headerName()
                |> strtoupper(...)
                |> (fn($x) => str_replace('-', '_', $x)),
        );
    }

    /**
     * The header is conventional rather than standard and libraries disagree on its casing, so
     * the rule lives on the enum instead of as a strtolower() at the one call site that
     * remembers it.
     *
     * @param string $header
     * @param bool $expected
     * @return void
     */
    #[DataProvider('requestedWithProvider')]
    public function testTheRequestedWithValueIsMatchedWhateverCaseItArrivesIn(
        string $header,
        bool $expected,
    ): void {
        self::assertSame($expected, RequestedWith::XmlHttpRequest->matches($header));
    }

    /**
     * @return iterable
     */
    public static function requestedWithProvider(): iterable
    {
        yield 'as sent'    => ['XMLHttpRequest', true];
        yield 'lower'      => ['xmlhttprequest', true];
        yield 'mixed'      => ['XmlHttpRequest', true];
        yield 'upper'      => ['XMLHTTPREQUEST', true];
        yield 'fetch'      => ['fetch', false];
        yield 'empty'      => ['', false];
        yield 'substring'  => ['not-XMLHttpRequest', false];
    }

    // ───────────────────────────── ServerVariable ─────────────────────────────

    /**
     * The keys, spelled out once, against the names Apache and PHP actually use.
     *
     * This is the whole reason the enum exists, so it is asserted the same way {@link RequestHeader}
     * asserts its wire names: the value is the environment's spelling, and a case that drifts from
     * it reads at runtime as a request that simply did not carry the value.
     *
     * {@link ServerVariable::Referer} is the one worth looking at twice — one `r`, because HTTP
     * lost it in 1996 and never got it back, while the property it fills spells it correctly.
     *
     * {@link ServerVariable::DocumentRoot} is the one that is not a request header at all, which is
     * exactly why it earns a case: no `HTTP_` derivation reaches it, and it carries the one fact
     * about a deployment that {@link \Phpanta\App} cannot derive — what the webroot directory is
     * called.
     *
     * {@link ServerVariable::ServerSoftware} and {@link ServerVariable::ServerProtocol} are two
     * more of that kind — CGI's names rather than HTTP's — and they are read by something with no
     * {@link \Phpanta\Http\Request} to ask, which is the enum's other membership clause. Both
     * are absent on CLI, so this asserts the spelling and nothing about the value; what a real
     * server puts in them is an end-to-end test's to see.
     *
     * @return void
     */
    public function testTheServerVariablesAreNamedAsTheEnvironmentSpellsThem(): void
    {
        self::assertSame([
            'REQUEST_METHOD',
            'REQUEST_URI',
            'PHP_AUTH_USER',
            'PHP_AUTH_PW',
            'HTTP_AUTHORIZATION',
            'REDIRECT_HTTP_AUTHORIZATION',
            'HTTP_REFERER',
            'SERVER_SOFTWARE',
            'SERVER_PROTOCOL',
            'DOCUMENT_ROOT',
        ], array_column(ServerVariable::cases(), 'value'));
    }

    /**
     * A key that is there comes back; one that is not comes back null rather than ''.
     *
     * Null and not the empty string, because the callers want different defaults out of the same
     * absence — `GET`, `/` and `''` — and a reader that picked one for them would have made
     * {@link Request::fromGlobals()} say `?: 'GET'`, which is a different question.
     *
     * @return void
     */
    public function testAServerVariableReadsItsKeyAndAnswersNullWhenItIsAbsent(): void
    {
        $_SERVER = ['REQUEST_URI' => '/posts'];

        self::assertSame('/posts', ServerVariable::RequestUri->string());
        self::assertNull(ServerVariable::RequestMethod->string());
    }

    /**
     * A key holding something that is not a string is the same as one that is not there.
     *
     * The guard a bare `$_SERVER['HTTP_REFERER'] ?? ''` never has: under `strict_types=1` a
     * non-string reaching a `string`-typed parameter is an uncaught TypeError, which would take a
     * response down over a header nothing needed. Unreachable through Apache, and guarded anyway,
     * because it is input nothing here wrote.
     *
     * @return void
     */
    public function testAServerVariableHoldingSomethingOtherThanAStringReadsAsAbsent(): void
    {
        $_SERVER = ['HTTP_REFERER' => ['https://example.invalid/'], 'REQUEST_METHOD' => 405];

        self::assertNull(ServerVariable::Referer->string());
        self::assertNull(ServerVariable::RequestMethod->string());
    }

    /**
     * The two spellings of one header, and why only one of them can be derived.
     *
     * `HTTP_AUTHORIZATION` is what the derivation in {@link Request::header()} would produce for a
     * header named `Authorization`; `REDIRECT_HTTP_AUTHORIZATION` is Apache's own name for the same
     * value seen from the far side of an internal rewrite, and no transform of a header name
     * reaches it. That asymmetry is the rule for what belongs on this enum rather than on
     * {@link RequestHeader}, so it is asserted rather than described.
     *
     * @return void
     */
    public function testOnlyOneOfTheAuthorizationSpellingsIsOneAHeaderNameCanProduce(): void
    {
        $derive = static fn(string $header): string
            => 'HTTP_' . str_replace('-', '_', strtoupper($header));

        self::assertSame(ServerVariable::Authorization->value, $derive('Authorization'));
        self::assertNotSame(ServerVariable::RedirectAuthorization->value, $derive('Authorization'));
    }

    /**
     * Both spellings are read, and the unprefixed one wins when both are set.
     *
     * @param array<string, string> $server
     * @param string $expected
     * @return void
     */
    #[DataProvider('authorizationProvider')]
    public function testTheAuthorizationHeaderIsReadUnderEitherName(array $server, string $expected): void
    {
        $request = $this->request(['REQUEST_URI' => '/'] + $server);

        self::assertSame($expected, $request->authUser());
    }

    /**
     * @return iterable<string, array{array<string, string>, string}>
     */
    public static function authorizationProvider(): iterable
    {
        $basic = static fn(string $user): string => 'Basic ' . base64_encode($user . ':pw');

        yield 'unprefixed'  => [['HTTP_AUTHORIZATION' => $basic('alice')], 'alice'];
        yield 'redirected'  => [['REDIRECT_HTTP_AUTHORIZATION' => $basic('bob')], 'bob'];
        yield 'both, first wins' => [
            ['HTTP_AUTHORIZATION' => $basic('alice'), 'REDIRECT_HTTP_AUTHORIZATION' => $basic('bob')],
            'alice',
        ];
        yield 'empty falls through' => [
            ['HTTP_AUTHORIZATION' => '', 'REDIRECT_HTTP_AUTHORIZATION' => $basic('bob')],
            'bob',
        ];
        yield 'neither' => [[], ''];
    }

    // ─────────────────────── the scheme both gates speak ───────────────────────

    /**
     * @return void
     */
    public function testTheChallengeAndTheParseUseTheSameToken(): void
    {
        self::assertStringStartsWith(
            AuthScheme::Basic->value . ' ',
            new BasicChallenge('realm')->render() . ' ',
        );
        self::assertTrue(AuthScheme::Basic->carries('Basic ' . base64_encode('a:b')));
    }

    /**
     * The space is part of the token: `Basicxyz` starts with `Basic` and is not a credential.
     *
     * @return void
     */
    public function testAnotherSchemeIsNotCarried(): void
    {
        self::assertFalse(AuthScheme::Basic->carries('Bearer abc123'));
        self::assertFalse(AuthScheme::Basic->carries('Basicxyz'));
        self::assertFalse(AuthScheme::Basic->carries("Basic\txyz"));
        self::assertFalse(AuthScheme::Basic->carries(''));
        self::assertSame(['', ''], AuthScheme::Basic->credentials('Bearer abc123'));
    }

    /**
     * The scheme is a case-insensitive token, separated from its parameters by one space or more —
     * RFC 9110 §11.1 and §11.4. A client that wrote it any other way than `Basic` and one space was
     * refused as though its password were wrong.
     *
     * @param string $authorization
     * @return void
     */
    #[DataProvider('schemeSpellingProvider')]
    public function testTheSchemeIsReadAsTheGrammarWritesIt(string $authorization): void
    {
        self::assertTrue(AuthScheme::Basic->carries($authorization));
        self::assertSame(['admin', 'pw'], AuthScheme::Basic->credentials($authorization));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function schemeSpellingProvider(): iterable
    {
        $token = base64_encode('admin:pw');

        yield 'as browsers send it' => ["Basic $token"];
        yield 'lower case'          => ["basic $token"];
        yield 'upper case'          => ["BASIC $token"];
        yield 'two spaces'          => ["Basic  $token"];
    }

    /**
     * The same for the signed scheme, whose parameters are everything after the spaces.
     *
     * @return void
     */
    public function testAnOpaqueSchemesParametersFollowItsSpaces(): void
    {
        self::assertSame('abc', AuthScheme::NS1->parameters('nS1   abc'));
        self::assertSame('', AuthScheme::NS1->parameters('NS1 '));
        self::assertNull(AuthScheme::NS1->parameters('NS1abc'));
        self::assertNull(AuthScheme::NS1->parameters('Basic abc'));
    }

    /**
     * @return void
     */
    public function testCredentialsSplitOnTheFirstColonOnly(): void
    {
        self::assertSame(
            ['admin', 'a:b:c'],
            AuthScheme::Basic->credentials('Basic ' . base64_encode('admin:a:b:c')),
        );
    }

    // ─────────────────────── which language a visitor wants ───────────────────────

    /**
     * @param string   $header
     * @param Language $expected
     * @return void
     */
    #[DataProvider('languageProvider')]
    public function testTheHeaderPicksALanguage(string $header, Language $expected): void
    {
        self::assertSame(
            $expected,
            AcceptedLanguages::from($header)->preferred(Language::English, Language::German),
        );
    }

    /**
     * @return iterable
     */
    public static function languageProvider(): iterable
    {
        yield 'plain German'        => ['de', Language::German];
        yield 'German with region'  => ['de-AT', Language::German];
        yield 'weighted, German up' => ['de-DE,de;q=0.9,en;q=0.8', Language::German];
        yield 'weighted, English up' => ['en-GB,en;q=0.9,de;q=0.8', Language::English];
        yield 'no header at all'    => ['', Language::English];
        yield 'nothing we have'     => ['fr,es;q=0.8', Language::English];
        yield 'a tie goes to the default' => ['en;q=0.5,de;q=0.5', Language::English];
        yield 'German refused'      => ['de;q=0', Language::English];
        yield 'whitespace and case' => ['  DE-at ; q=0.7 , en;q=0.2', Language::German];
        // A weight this cannot read drops its entry. Read as the 1.0 an absent weight means, as it
        // once was, an unreadable entry became the strongest in the list.
        yield 'unparseable weight'  => ['de;q=high', Language::English];
        yield 'an unreadable weight drops only its entry' => ['en;q=high,de;q=0.1', Language::German];
        yield 'a weight past 1'     => ['en;q=2,de;q=0.5', Language::German];
        yield 'four decimals'       => ['en;q=0.5000,de;q=0.1', Language::German];
        yield 'one, written longest' => ['de;q=1.000,en;q=0.9', Language::German];
        yield 'empty entries'       => [',,,', Language::English];
        yield 'a range that is not one' => ['12345678901,de', Language::German];
        // A range may carry parameters other than a weight. Rare in the wild, legal in the grammar,
        // and the arm that skips them is otherwise never run.
        yield 'a parameter that is not a weight' => ['en;q=0.9,de;x=1;q=0.95', Language::German];
    }

    /**
     * A wildcard covers whatever is on offer; an explicit `q=0` still refuses.
     *
     * The second assertion is the one worth having: German is the *default* there and the visitor
     * is given English anyway, because refusing a language outright outranks being the fallback.
     *
     * @return void
     */
    public function testTheWildcardIsBeatenByAnExplicitRefusal(): void
    {
        $accepted = AcceptedLanguages::from('*;q=0.5,de;q=0');

        self::assertSame(Language::English, $accepted->preferred(Language::English, Language::German));
        self::assertSame(Language::English, $accepted->preferred(Language::German, Language::English));
    }

    /**
     * But a refusal with nothing else acceptable still gets a page.
     *
     * There is no "406 Not Acceptable" here and there should not be: both halves are sent whatever
     * happens, and the only question this answers is which one comes first.
     *
     * @return void
     */
    public function testRefusingEverythingStillYieldsTheDefault(): void
    {
        self::assertSame(
            Language::German,
            AcceptedLanguages::from('de;q=0')->preferred(Language::German, Language::English),
        );
    }

    /**
     * The better of two ranges sharing a primary subtag is what the client meant by sending both.
     *
     * @return void
     */
    public function testTwoRangesForOneLanguageKeepTheHigherWeight(): void
    {
        self::assertSame(
            Language::German,
            AcceptedLanguages::from('de-AT;q=0.9,de;q=0.1,en;q=0.5')
                ->preferred(Language::English, Language::German),
        );
    }

    /**
     * The header reaches the request, which is the half a unit test of the parser cannot show.
     *
     * @return void
     */
    public function testTheRequestCarriesTheAcceptLanguageHeader(): void
    {
        $request = $this->request([
            'REQUEST_URI'          => '/imprint',
            'HTTP_ACCEPT_LANGUAGE' => 'de-DE,de;q=0.9',
        ]);

        self::assertSame(
            Language::German,
            $request->acceptedLanguages()->preferred(Language::English, Language::German),
        );
    }

    // ─────────────────────── the language a request is answered in ───────────────────────

    /**
     * @param string      $header
     * @param string|null $expected
     * @return void
     */
    #[DataProvider('cookieProvider')]
    public function testACookieIsFoundByItsName(string $header, ?string $expected): void
    {
        self::assertSame($expected, RequestCookies::from($header)->value(CookieName::Language));
    }

    /**
     * @return iterable
     */
    public static function cookieProvider(): iterable
    {
        yield 'alone'                         => ['lang=de', 'de'];
        yield 'among others'                  => ['theme=dark; lang=en; x=1', 'en'];
        yield 'with no space after the ;'     => ['a=1;lang=de', 'de'];
        yield 'quoted, which the grammar allows' => ['lang="de"', 'de'];
        yield 'the first of two wins'         => ['lang=de; lang=en', 'de'];
        yield 'a name that only ends in it'   => ['xlang=de', null];
        yield 'a pair with no ='              => ['lang; other=1', null];
        yield 'an empty value is still one'   => ['lang=', ''];
        yield 'a lone quote is not quoting'   => ['lang="', '"'];
        yield 'no header at all'              => ['', null];
    }

    /**
     * The visitor's choice, then their browser's, then the site's — and a cookie that names no
     * language of ours is no choice.
     *
     * @param array<string, string> $server
     * @param Language              $expected
     * @return void
     */
    #[DataProvider('requestLanguageProvider')]
    public function testTheRequestIsAnsweredInTheVisitorsLanguage(array $server, Language $expected): void
    {
        self::assertSame($expected, $this->request(['REQUEST_URI' => '/'] + $server)->language());
    }

    /**
     * @return iterable
     */
    public static function requestLanguageProvider(): iterable
    {
        $german = ['HTTP_ACCEPT_LANGUAGE' => 'de-DE,de;q=0.9'];

        yield 'nothing asked'                   => [[], Language::English];
        yield 'the browser asks for German'     => [$german, Language::German];
        yield 'the cookie asks for German'      => [['HTTP_COOKIE' => 'lang=de'], Language::German];
        yield 'the cookie outranks the browser' => [['HTTP_COOKIE' => 'lang=en'] + $german, Language::English];
        yield 'a cookie naming no language falls through' => [['HTTP_COOKIE' => 'lang=xx'] + $german, Language::German];
        yield 'an empty cookie falls through'   => [['HTTP_COOKIE' => 'lang='] + $german, Language::German];
    }

    /**
     * The `Referer` reaches the request raw; how little of it is used is its one reader's to decide.
     *
     * @return void
     */
    public function testTheRequestCarriesTheRefererRaw(): void
    {
        $from = 'https://x.example/posts/x';

        self::assertSame($from, $this->request(['REQUEST_URI' => '/', 'HTTP_REFERER' => $from])->referer());
        self::assertSame('', $this->request(['REQUEST_URI' => '/'])->referer());
    }
}
