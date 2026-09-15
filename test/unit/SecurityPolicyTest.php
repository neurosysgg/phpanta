<?php

declare(strict_types=1);

namespace Phpanta\Test\Unit;

use FilesystemIterator;
use Phpanta\Exception\SecurityPolicyException;
use Phpanta\Http\AcceptRanges;
use Phpanta\Http\Allow;
use Phpanta\Http\BasicChallenge;
use Phpanta\Http\ByteRange;
use Phpanta\Http\CacheControl;
use Phpanta\Http\CacheDirective;
use Phpanta\Http\ContentDisposition;
use Phpanta\Http\ContentLanguage;
use Phpanta\Http\ContentLength;
use Phpanta\Http\ContentRange;
use Phpanta\Http\ETag;
use Phpanta\Http\Header;
use Phpanta\Http\HeaderValue;
use Phpanta\Http\Location;
use Phpanta\Http\MimeType;
use Phpanta\Http\Origin;
use Phpanta\Http\RequestHeader;
use Phpanta\Http\ResponseHeader;
use Phpanta\Http\RetryAfter;
use Phpanta\Http\RobotsPolicy;
use Phpanta\Http\Security\ContentSecurityPolicy;
use Phpanta\Http\Security\ContentTypeOptions;
use Phpanta\Http\Security\CrossOriginOpenerPolicy;
use Phpanta\Http\Security\CrossOriginResourcePolicy;
use Phpanta\Http\Security\CspDirective;
use Phpanta\Http\Security\CspHost;
use Phpanta\Http\Security\CspKeyword;
use Phpanta\Http\Security\CspScheme;
use Phpanta\Http\Security\CspSource;
use Phpanta\Http\Security\CspSourceList;
use Phpanta\Http\Security\PermissionsPolicy;
use Phpanta\Http\Security\PermissionsPolicyFeature;
use Phpanta\Http\Security\ReferrerPolicy;
use Phpanta\Http\Security\StrictTransportSecurity;
use Phpanta\Http\SecurityHeader;
use Phpanta\Http\SecurityHeaders;
use Phpanta\Http\SetCookie;
use Phpanta\Http\Vary;
use Phpanta\Text\Language;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

#[CoversClass(ContentSecurityPolicy::class)]
#[CoversClass(CspHost::class)]
#[CoversClass(CspSourceList::class)]
#[CoversClass(CspKeyword::class)]
#[CoversClass(CspScheme::class)]
#[CoversClass(CspDirective::class)]
#[CoversClass(PermissionsPolicy::class)]
#[CoversClass(PermissionsPolicyFeature::class)]
#[CoversClass(SecurityHeader::class)]
#[CoversClass(StrictTransportSecurity::class)]
#[CoversClass(CrossOriginOpenerPolicy::class)]
#[CoversClass(CrossOriginResourcePolicy::class)]
// Declared because this file is the only thing that exercises the realm check, and a test class
// that names any #[CoversClass] records coverage for *only* those classes — so without this line
// the guard reads as 0% while eight data rows drive it. The same trap UpdateFile fell into.
#[CoversClass(BasicChallenge::class)]
#[CoversClass(ContentLanguage::class)]
#[CoversClass(SetCookie::class)]
#[CoversClass(Location::class)]
#[CoversClass(Origin::class)]
#[CoversClass(RetryAfter::class)]
// The empty-list refusals below are these two classes' only guards, and without the classes named
// here the rows that drive them record nothing.
#[CoversClass(CacheControl::class)]
#[CoversClass(Vary::class)]
#[CoversClass(ContentDisposition::class)]
final class SecurityPolicyTest extends TestCase
{
    // ───────────────────────── StrictTransportSecurity ─────────────────────────

    /**
     * @return void
     */
    public function testTheTransportPolicyRendersItsMaxAgeAndSubdomains(): void
    {
        self::assertSame(
            'max-age=31536000; includeSubDomains',
            new StrictTransportSecurity()->render(),
        );
    }

    /**
     * @return void
     */
    public function testSubdomainsCanBeLeftOutForAnEstateThatNeedsIt(): void
    {
        self::assertSame(
            'max-age=86400',
            new StrictTransportSecurity(StrictTransportSecurity::ONE_DAY, includeSubDomains: false)->render(),
        );
    }

    /**
     * Zero is the documented way to switch the policy off, so it is a value and not an error.
     *
     * @return void
     */
    public function testAZeroMaxAgeIsAllowed(): void
    {
        self::assertSame('max-age=0; includeSubDomains', new StrictTransportSecurity(0)->render());
    }

    /**
     * A negative max-age is a header the browser discards, which is worse than no header: it reads
     * as protection that is present when there is none.
     *
     * @return void
     */
    public function testANegativeMaxAgeIsRefused(): void
    {
        $this->expectException(SecurityPolicyException::class);

        new StrictTransportSecurity(-1);
    }

    /**
     * What the site actually sends, rather than what the class can express.
     *
     * A year is the value that makes the policy worth having; anything shorter leaves a window
     * where a visitor who has not been back lately still sends the Basic Auth header in the clear.
     * Asserted as a floor, so shipping the ONE_DAY ramp value by accident fails here.
     *
     * @return void
     */
    public function testTheSiteSendsAtLeastAYearAndCoversSubdomains(): void
    {
        $sent = SecurityHeaders::headers()[SecurityHeader::StrictTransportSecurity->value];

        self::assertMatchesRegularExpression('/^max-age=(\d+); includeSubDomains$/', $sent);
        self::assertGreaterThanOrEqual(
            StrictTransportSecurity::ONE_YEAR,
            (int) preg_replace('/\D/', '', explode(';', $sent)[0] ?? ''),
        );
    }

    /**
     * Preload is deliberately not offered — see the class docblock. It ships the host inside the
     * browser binary, where nothing this server sends can take it back, so it is a decision to make
     * on purpose rather than a flag to pass on the way past.
     *
     * @return void
     */
    public function testThePolicyDoesNotClaimToBePreloaded(): void
    {
        self::assertStringNotContainsStringIgnoringCase(
            'preload',
            new StrictTransportSecurity()->render(),
        );
    }

    // ───────────────────────── CspHost ─────────────────────────

    /**
     * @return void
     */
    public function testAcceptsABareOrigin(): void
    {
        self::assertSame('https://files.example.com', new CspHost('https://files.example.com')->source());
    }

    /**
     * @return iterable
     */
    public static function validOriginProvider(): iterable
    {
        yield 'https'             => ['https://example.com'];
        yield 'http'              => ['http://example.com'];
        yield 'subdomain'         => ['https://w.example.com'];
        yield 'wildcard'          => ['https://*.example.com'];
        yield 'port'              => ['https://example.com:8443'];
        yield 'hyphenated'        => ['https://my-cdn.example.com'];
        yield 'deep subdomain'    => ['https://a.b.c.example.com'];
    }

    /**
     * @param string $origin
     * @return void
     */
    #[DataProvider('validOriginProvider')]
    public function testAcceptsEveryWellFormedOrigin(string $origin): void
    {
        self::assertSame($origin, new CspHost($origin)->source());
    }

    /**
     * @return iterable
     */
    public static function invalidOriginProvider(): iterable
    {
        yield 'trailing slash'  => ['https://example.com/'];
        yield 'with a path'     => ['https://files.example.com/api/download'];
        yield 'with a query'    => ['https://example.com?a=b'];
        yield 'no scheme'       => ['example.com'];
        yield 'scheme only'     => ['https://'];
        yield 'no dot'          => ['https://localhost'];
        yield 'empty'           => [''];
        yield 'a keyword'       => ["'self'"];
        yield 'space'           => ['https://exa mple.com'];
        yield 'newline'         => ["https://example.com\n"];
        yield 'javascript'      => ['javascript:alert(1)'];
    }

    /**
     * Like any value with a grammar: a bad paste has to fail where it is written, not on the wire.
     *
     * @param string $origin
     * @return void
     */
    #[DataProvider('invalidOriginProvider')]
    public function testRejectsAnythingThatIsNotABareOrigin(string $origin): void
    {
        $this->expectException(SecurityPolicyException::class);
        new CspHost($origin);
    }

    // ───────────────────────── sources ─────────────────────────

    /**
     * @return void
     */
    public function testKeywordsCarryTheirQuotes(): void
    {
        self::assertSame("'self'", CspKeyword::SelfOrigin->source());
        self::assertSame("'none'", CspKeyword::None->source());
        self::assertSame("'unsafe-inline'", CspKeyword::UnsafeInline->source());
    }

    /**
     * @return void
     */
    public function testSchemesCarryTheirColon(): void
    {
        self::assertSame('data:', CspScheme::Data->source());
        self::assertSame('https:', CspScheme::Https->source());
    }

    /**
     * Keyword, scheme and host are interchangeable wherever a source is wanted.
     *
     * @return void
     */
    public function testAllThreeSourceKindsShareTheInterface(): void
    {
        $sources = [CspKeyword::SelfOrigin, CspScheme::Data, new CspHost('https://example.com')];

        foreach ($sources as $source) {
            self::assertInstanceOf(CspSource::class, $source);
            self::assertNotSame('', $source->source());
        }
    }

    // ───────────────────────── ContentSecurityPolicy ─────────────────────────

    /**
     * @return void
     */
    public function testRendersDirectivesInInsertionOrder(): void
    {
        $policy = new ContentSecurityPolicy()
            ->allow(CspDirective::DefaultSrc, CspKeyword::SelfOrigin)
            ->allow(CspDirective::ObjectSrc, CspKeyword::None);

        self::assertSame("default-src 'self'; object-src 'none'", $policy->render());
    }

    /**
     * @return void
     */
    public function testRendersMultipleSourcesSpaceSeparated(): void
    {
        $policy = new ContentSecurityPolicy()->allow(
            CspDirective::ImgSrc,
            CspKeyword::SelfOrigin,
            CspScheme::Data,
            new CspHost('https://files.example.com'),
        );

        self::assertSame("img-src 'self' data: https://files.example.com", $policy->render());
    }

    /**
     * @return void
     */
    public function testAnEmptyPolicyRendersEmpty(): void
    {
        self::assertSame('', new ContentSecurityPolicy()->render());
    }

    /**
     * @return void
     */
    public function testAllowIsImmutable(): void
    {
        $base = new ContentSecurityPolicy()->allow(CspDirective::DefaultSrc, CspKeyword::SelfOrigin);
        $extended = $base->allow(CspDirective::ObjectSrc, CspKeyword::None);

        self::assertSame("default-src 'self'", $base->render());
        self::assertNotSame($base, $extended);
        self::assertStringContainsString('object-src', $extended->render());
    }

    /**
     * @return void
     */
    public function testADirectiveNeedsAtLeastOneSource(): void
    {
        $this->expectException(SecurityPolicyException::class);
        $this->expectExceptionMessageIsOrContains('CspKeyword::None');

        (void) new ContentSecurityPolicy()->allow(CspDirective::ScriptSrc);
    }

    /**
     * A browser honours the first occurrence, so a second one would silently do nothing.
     *
     * @return void
     */
    public function testADirectiveCannotBeSetTwice(): void
    {
        $this->expectException(SecurityPolicyException::class);
        $this->expectExceptionMessageIsOrContains('already set');

        (void) new ContentSecurityPolicy()
            ->allow(CspDirective::ScriptSrc, CspKeyword::SelfOrigin)
            ->allow(CspDirective::ScriptSrc, CspKeyword::UnsafeInline);
    }

    /**
     * @return void
     */
    public function testHostsReportsOnlyHostSourcesAndDeduplicates(): void
    {
        $policy = new ContentSecurityPolicy()
            ->allow(CspDirective::ImgSrc, CspKeyword::SelfOrigin, new CspHost('https://a.example.com'))
            ->allow(CspDirective::FrameSrc, new CspHost('https://a.example.com'))
            ->allow(CspDirective::ScriptSrc, new CspHost('https://b.example.com'));

        self::assertSame(['https://a.example.com', 'https://b.example.com'], $policy->hosts());
    }

    /**
     * @return void
     */
    public function testHostsIsEmptyForASelfOnlyPolicy(): void
    {
        $policy = new ContentSecurityPolicy()->allow(CspDirective::DefaultSrc, CspKeyword::SelfOrigin);

        self::assertSame([], $policy->hosts());
    }

    // ───────────────────────── PermissionsPolicy ─────────────────────────

    /**
     * @return void
     */
    public function testDenyRendersEachFeatureAsDeniedToEveryone(): void
    {
        $policy = PermissionsPolicy::deny(
            PermissionsPolicyFeature::Geolocation,
            PermissionsPolicyFeature::Camera,
        );

        self::assertSame('geolocation=(), camera=()', $policy->render());
    }

    /**
     * @return void
     */
    public function testDenyAllCoversEveryCase(): void
    {
        $rendered = PermissionsPolicy::denyAll()->render();

        foreach (PermissionsPolicyFeature::cases() as $feature) {
            self::assertStringContainsString($feature->denied(), $rendered);
        }
    }

    /**
     * @return void
     */
    public function testDenyingNothingIsAnError(): void
    {
        $this->expectException(SecurityPolicyException::class);
        PermissionsPolicy::deny();
    }

    /**
     * @return void
     */
    public function testAFeatureRendersAsAnEmptyAllowList(): void
    {
        self::assertSame('geolocation=()', PermissionsPolicyFeature::Geolocation->denied());
    }

    // ───────────────────────── SecurityHeader ─────────────────────────

    // ───────────────────────────── header values ─────────────────────────────

    /**
     * Every header value is an object that knows its own grammar, and `Header` will not take
     * anything else. Pinned in both directions: each of these must implement the interface, and
     * nothing that reaches `Header` may be a bare string.
     *
     * @param string $expected
     * @param HeaderValue $value
     * @return void
     */
    #[DataProvider('headerValueProvider')]
    public function testAHeaderValueRendersItsOwnGrammar(string $expected, HeaderValue $value): void
    {
        self::assertSame($expected, $value->render());
    }

    /** @return iterable<string, array{string, HeaderValue}> */
    public static function headerValueProvider(): iterable
    {
        yield 'the revalidating document' => ['no-cache', CacheControl::revalidate()];
        yield 'the gated page'            => ['no-store, private', CacheControl::doNotStore()];
        yield 'one directive'             => ['private', CacheControl::of(CacheDirective::Private)];

        // The quotes are grammar, not decoration: `ETag: abc` is a different header from `ETag: "abc"`.
        yield 'a validator is quoted'     => ['"' . hash('xxh128', 'x') . '"', ETag::forBody('x')];

        yield 'what the body depends on'  => ['X-Requested-With', Vary::on(RequestHeader::RequestedWith)];
        yield 'what the site accepts'     => ['GET, HEAD', Allow::readOnly()];
        yield 'the realm, quoted'         => ['Basic realm="Example"', new BasicChallenge('Example')];
        yield 'a signature, asked for'    => ['NS1', new \Phpanta\Http\SignedChallenge()];
        yield 'where a download goes'     => ['https://x.example/f?id=1', new Location('https://x.example/f?id=1')];
        yield 'back to a page, after a switch' => ['/posts/x', new Location('/posts/x')];
        yield 'the one cookie this site sets'  => [
            'lang=de; Path=/; Max-Age=31536000; SameSite=Lax; Secure; HttpOnly',
            SetCookie::language(Language::German),
        ];
        yield 'a media type'              => ['text/html; charset=utf-8', MimeType::html()];
        yield 'the language a body is in' => ['de', new ContentLanguage(Language::German)];
        yield 'a single-value enum'       => ['nosniff', ContentTypeOptions::NoSniff];
        yield 'no opener across origins'  => ['same-origin', CrossOriginOpenerPolicy::SameOrigin];
        yield 'loaded only by this origin' => ['same-origin', CrossOriginResourcePolicy::SameOrigin];

        // A file response's four. It is the only response here whose body is not a rendered
        // page, so it is the only one that has a length to state, a part to name and a unit to
        // offer — and the only one a crawler is told to leave alone.
        yield 'how long the body is'      => ['4096', new ContentLength(4096)];
        yield 'the part being sent'       => [
            'bytes 0-1023/5000',
            ContentRange::of(ByteRange::parse('bytes=0-1023', 5000) ?? self::fail('unparsed')),
        ];
        yield 'a range that cannot be met' => ['bytes */5000', ContentRange::unsatisfiable(5000)];
        yield 'ranges are supported'      => ['bytes', AcceptRanges::Bytes];
        yield 'what a crawler may do'     => ['noindex, nofollow, noarchive', RobotsPolicy::hide()];
        yield 'who may read it from afar' => ['https://app.example.org', Origin::of('https://app.example.org')];
        yield 'how long to wait'          => ['120', new RetryAfter(120)];

        // A file the admin serves: shown where it lands, or saved under its name — written twice,
        // plainly with what a quoted string cannot hold replaced, and in full as UTF-8.
        yield 'shown where it lands'      => ['inline', ContentDisposition::inline()];
        yield 'saved under its name'      => [
            'attachment; filename="__ber ___ _.flac"; filename*=UTF-8\'\'%C3%BCber%20%22_%22%20%5C.flac',
            ContentDisposition::attachment('über "_" \\.flac'),
        ];

        // The four that already rendered before the interface existed. They are here as well as in
        // their own tests above, because this table is the one place that answers "what can the
        // site put after a colon?" — and the audit below is what keeps it able to answer.
        yield 'the transport policy'      => [
            'max-age=31536000; includeSubDomains',
            new StrictTransportSecurity(),
        ];
        yield 'a content policy'          => [
            "default-src 'self'",
            new ContentSecurityPolicy()->allow(CspDirective::DefaultSrc, CspKeyword::SelfOrigin),
        ];
        yield 'a referrer policy'         => [
            'strict-origin-when-cross-origin',
            ReferrerPolicy::StrictOriginWhenCrossOrigin,
        ];
        yield 'a denied feature'          => [
            'geolocation=()',
            PermissionsPolicy::deny(PermissionsPolicyFeature::Geolocation),
        ];
    }

    /**
     * A realm may hold `qdtext` and nothing else.
     *
     * This was the one header value carrying something other than a fixed vocabulary that did not
     * check it, and the gap matters because a site may build a realm out of a **URL segment**: a
     * `"` in the segment closes the quoted-string early and leaves the rest as trailing rubbish in a
     * `WWW-Authenticate` header. Never header injection — `header()` refuses CR and LF — and never
     * reachable behind a proxy that percent-encodes the byte first. Both of which are reasons it is
     * hard to notice rather than reasons it is fine.
     *
     * @return iterable
     */
    public static function badRealmProvider(): iterable
    {
        yield 'a closing quote'  => ['Example: a"b'];
        yield 'a backslash'      => ['Example: a\\b'];
        yield 'an added param'   => ['x",charset="UTF-8'];
        yield 'a newline'        => ["Example\n"];
        yield 'a carriage return' => ["Example\r"];
        yield 'a NUL'            => ["Example\0"];

        // Legal in the grammar and refused anyway: an empty realm keys every credential on the
        // origin together, which is the exact failure a realm per protected page exists to prevent.
        yield 'empty'            => [''];

        // obs-text is permitted by RFC 9110 and left out on RFC 7617's advice, so a realm is
        // US-ASCII. Nothing here builds one that is not.
        yield 'non-ASCII'        => ['Example ü'];
    }

    /**
     * @param string $realm
     * @return void
     */
    #[DataProvider('badRealmProvider')]
    public function testABasicRealmRefusesAnythingButQdtext(string $realm): void
    {
        $this->expectException(SecurityPolicyException::class);
        $this->expectExceptionMessageIsOrContains('qdtext');

        new BasicChallenge($realm);
    }

    /**
     * The characters a realm is actually made of, so the refusal above is not simply strict.
     *
     * A space and a tab are `qdtext`, and so is every printable ASCII character but the two a
     * quoted-string cannot carry — which is what lets the realms a site builds through unchanged.
     *
     * @return void
     */
    public function testABasicRealmAcceptsTheRestOfQdtext(): void
    {
        self::assertSame(
            'Basic realm="Example: notes-and-drafts"',
            new BasicChallenge('Example: notes-and-drafts')->render(),
        );

        // Every qdtext byte at once: HTAB, SP, `!`, and the two printable ranges around `"` and `\`.
        $qdtext = "\t !#\$%&'()*+,-./0123456789:;<=>?@ABCDEFGHIJKLMNOPQRSTUVWXYZ[]^_`"
            . 'abcdefghijklmnopqrstuvwxyz{|}~';

        self::assertSame('Basic realm="' . $qdtext . '"', new BasicChallenge($qdtext)->render());
    }

    /**
     * The set of header values, pinned in both directions.
     *
     * The same instinct as any set a test pins against reflection rather than a list somebody keeps:
     * a value that reaches {@link Header} without implementing the interface can no longer compile,
     * but a *new* implementer that nobody remembered to cover would be a grammar nothing checks.
     * Adding one means adding it to {@link self::headerValueProvider()} too, and this is what says
     * so.
     *
     * @return void
     */
    public function testExactlyTheseAreHeaderValues(): void
    {
        $found = [];

        foreach (self::classesUnderSrc() as $class) {
            if (is_a($class, HeaderValue::class, allow_string: true)) {
                $found[] = $class;
            }
        }
        sort($found);

        self::assertSame(
            [
                'Phpanta\Http\AcceptRanges',
                'Phpanta\Http\Allow',
                'Phpanta\Http\BasicChallenge',
                'Phpanta\Http\CacheControl',
                'Phpanta\Http\ContentDisposition',
                'Phpanta\Http\ContentLanguage',
                'Phpanta\Http\ContentLength',
                'Phpanta\Http\ContentRange',
                'Phpanta\Http\ETag',
                'Phpanta\Http\Location',
                'Phpanta\Http\MimeType',
                'Phpanta\Http\Origin',
                'Phpanta\Http\RetryAfter',
                'Phpanta\Http\RobotsPolicy',
                'Phpanta\Http\Security\ContentSecurityPolicy',
                'Phpanta\Http\Security\ContentTypeOptions',
                'Phpanta\Http\Security\CrossOriginOpenerPolicy',
                'Phpanta\Http\Security\CrossOriginResourcePolicy',
                'Phpanta\Http\Security\PermissionsPolicy',
                'Phpanta\Http\Security\ReferrerPolicy',
                'Phpanta\Http\Security\StrictTransportSecurity',
                'Phpanta\Http\SetCookie',
                'Phpanta\Http\SignedChallenge',
                'Phpanta\Http\Vary',
            ],
            $found,
        );
    }

    /**
     * And every one of them is exercised above.
     *
     * @return void
     */
    public function testEveryHeaderValueIsCovered(): void
    {
        $covered = [];

        foreach (self::headerValueProvider() as [, $value]) {
            $covered[] = $value::class;
        }

        $found = [];
        foreach (self::classesUnderSrc() as $class) {
            if (is_a($class, HeaderValue::class, allow_string: true)) {
                $found[] = $class;
            }
        }

        array_diff($found, $covered)
            |> array_values(...)
            |> (fn($x) => self::assertSame([], $x));
    }

    /**
     * Every class and enum under the framework's `src/`, named from its path the way its autoloader
     * names it — so a file this cannot name is one production could not load either.
     *
     * @return list<class-string>
     */
    private static function classesUnderSrc(): array
    {
        $root    = PHPANTA_ROOT . '/src/';
        $classes = [];
        $files   = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        );

        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $relative = substr($file->getPathname(), strlen($root), -strlen('.php'));
            $class    = 'Phpanta\\' . str_replace('/', '\\', $relative);

            if (class_exists($class) || enum_exists($class)) {
                $classes[] = $class;
            }
        }

        sort($classes);

        return $classes;
    }

    /**
     * An empty list is a malformed header rather than a permissive one — the same rule PermissionsPolicy has.
     *
     * @return void
     */
    public function testAnEmptyCacheControlIsRefused(): void
    {
        $this->expectException(SecurityPolicyException::class);
        $this->expectExceptionMessageIsOrContains('at least one directive');

        CacheControl::of();
    }

    /**
     * @return void
     */
    public function testAnEmptyVaryIsRefused(): void
    {
        $this->expectException(SecurityPolicyException::class);
        $this->expectExceptionMessageIsOrContains('at least one header');

        Vary::on();
    }

    /**
     * A `Location` is an address the site emits, so it is checked like every other one.
     *
     * Narrower than the spec on purpose: a redirect here goes to the file host, absolute and over
     * TLS, or back to a page of this site after a language switch. A path is asked of the WHATWG
     * parser, so the two spellings of another host a leading slash can hide are refused with the
     * rest. The newline case is the one that would matter most — PHP's `header()` refuses one
     * anyway, but a validator that does not mean what it says is worth closing regardless.
     *
     * @param string $url
     * @return void
     */
    #[DataProvider('badLocationProvider')]
    public function testALocationMustBeHttpsOrAPathOnThisSite(string $url): void
    {
        $this->expectException(SecurityPolicyException::class);

        new Location($url);
    }

    /** @return iterable<string, array{string}> */
    public static function badLocationProvider(): iterable
    {
        yield 'protocol-relative' => ['//evil.example/x'];
        yield 'a backslash that is a second slash' => ['/\\evil.example/x'];
        yield 'a path with a space' => ['/a b'];
        yield 'plaintext'         => ['http://x.example/'];
        yield 'a scheme that runs script' => ['javascript:alert(1)'];
        yield 'trailing newline'  => ["https://x.example/\n"];
        yield 'an embedded space' => ['https://x.example/a b'];
        yield 'empty'             => [''];
    }

    /**
     * @return void
     */
    public function testAHeaderFormatsItsOwnLine(): void
    {
        self::assertSame(
            'X-Content-Type-Options: nosniff',
            new Header(SecurityHeader::ContentTypeOptions, ContentTypeOptions::NoSniff)->line(),
        );
    }

    /**
     * Header takes any HeaderName, which is the whole reason the interface exists.
     *
     * @return void
     */
    public function testAHeaderFormatsAResponseHeaderTheSameWay(): void
    {
        self::assertSame(
            'Allow: GET, HEAD',
            new Header(ResponseHeader::Allow, Allow::readOnly())->line(),
        );
    }

    /**
     * @return void
     */
    public function testEveryHeaderNameLooksLikeAHeaderName(): void
    {
        foreach (SecurityHeader::cases() as $header) {
            self::assertMatchesRegularExpression('/^[A-Za-z][A-Za-z0-9-]*$/', $header->value);
        }
    }
}
