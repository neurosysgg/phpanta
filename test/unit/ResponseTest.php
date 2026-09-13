<?php

declare(strict_types=1);

namespace Phpanta\Test\Unit;

use Phpanta\Http\Answer;
use Phpanta\Http\CacheControl;
use Phpanta\Http\ContentLanguage;
use Phpanta\Http\ETag;
use Phpanta\Http\Header;
use Phpanta\Http\HttpStatusCode;
use Phpanta\Http\MimeType;
use Phpanta\Http\Request;
use Phpanta\Http\RequestHeader;
use Phpanta\Http\ResponseHeader;
use Phpanta\Http\SetCookie;
use Phpanta\Http\TextBody;
use Phpanta\Http\Vary;
use Phpanta\Http\ViewResponse;
use Phpanta\Support\Collection;
use Phpanta\Test\TestRequest;
use Phpanta\Text\Language;
use Phpanta\Text\Translatable;
use Phpanta\Text\Verbatim;
use Phpanta\View\Html\Element;
use Phpanta\View\Html\HtmlTag;
use Phpanta\View\Html\Node;
use Phpanta\View\View;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A page as a response: the whole document or the fragment Navigation swaps in, and the headers
 * that say how either may be kept. When a validator earns a 304 is {@link RevalidationTest}'s.
 */
#[CoversClass(ViewResponse::class)]
#[CoversClass(Answer::class)]
#[CoversClass(TextBody::class)]
#[CoversClass(HttpStatusCode::class)]
#[CoversClass(Header::class)]
#[CoversClass(ResponseHeader::class)]
#[CoversClass(ETag::class)]
#[CoversClass(CacheControl::class)]
#[CoversClass(Vary::class)]
#[CoversClass(MimeType::class)]
#[CoversClass(ContentLanguage::class)]
final class ResponseTest extends TestCase
{
    /** What every page varies on, whatever else it reads. */
    private const string VARY = 'Vary: X-Requested-With, Accept-Language, Cookie';

    // ───────────────────────────── the document and the fragment ─────────────────────────────

    /**
     * @return void
     */
    public function testAFullPageRequestGetsTheWholeDocument(): void
    {
        $html = new ViewResponse(self::view())->render(TestRequest::get('/')->request());

        self::assertStringStartsWith('<!DOCTYPE html>', $html);
        self::assertStringContainsString('<html lang="en">', $html);
        self::assertStringContainsString('<body>', $html);
        self::assertStringContainsString('<p>hello</p>', $html);
    }

    /**
     * Navigation swaps this straight into `#content`, so a full document here would nest one.
     *
     * @return void
     */
    public function testAnAjaxRequestGetsAFragmentWithNoDocumentShell(): void
    {
        $html = new ViewResponse(self::view())->render(self::ajax());

        self::assertStringNotContainsString('<html', $html);
        self::assertStringNotContainsString('<!DOCTYPE', $html);
        self::assertStringNotContainsString('<body', $html);
        self::assertStringContainsString('<p>hello</p>', $html);
    }

    /**
     * @return void
     */
    public function testTheAjaxFragmentLeadsWithTheTitleNavigationLooksFor(): void
    {
        $html = new ViewResponse(self::view())->render(self::ajax());

        self::assertSame(1, preg_match('/^<title>(.*?)<\/title>/', $html, $m));
        self::assertSame('Page', $m[1]);
    }

    /**
     * Navigation HTML-decodes this before assigning `document.title`. The two have to agree: the
     * fragment escapes, the client decodes.
     *
     * @return void
     */
    public function testTheAjaxTitleIsEscapedSoTheClientCanDecodeIt(): void
    {
        $html = new ViewResponse(self::view('rock & roll'))->render(self::ajax());

        preg_match('/^<title>(.*?)<\/title>/', $html, $m);

        self::assertSame('rock &amp; roll', $m[1]);
        self::assertSame('rock & roll', html_entity_decode($m[1], ENT_QUOTES));
    }

    /**
     * @return void
     */
    public function testTheDefaultStatusIsOk(): void
    {
        $response = new ViewResponse(self::view());

        self::assertSame(HttpStatusCode::Ok, $response->status());
        self::assertSame(HttpStatusCode::Ok, $response->answer(TestRequest::get('/')->request())->status());
    }

    // ───────────────────────────── caching ─────────────────────────────

    /**
     * A public document says how it may be reused, and the answer is "ask first": `no-cache`, its
     * validator, and what the body depends on — after the two that describe the body itself.
     *
     * @return void
     */
    public function testAPublicDocumentSaysHowItMayBeReused(): void
    {
        $request = TestRequest::get('/')->request();
        $answer  = new ViewResponse(self::view())->answer($request);

        self::assertSame(
            [
                'Content-Type: text/html; charset=utf-8',
                'Content-Language: en',
                'Cache-Control: no-cache',
                'ETag: ' . ETag::forBody($answer->body())->render(),
                self::VARY,
            ],
            self::lines($answer),
        );
        self::assertMatchesRegularExpression(
            '/^"[0-9a-f]+"$/',
            $answer->header(ResponseHeader::ETag)?->value->render() ?? '',
        );
    }

    /**
     * Extra headers reach the answer after the page's own, in the order given.
     *
     * @return void
     */
    public function testExtraHeadersFollowThePagesOwnInTheOrderGiven(): void
    {
        $answer = new ViewResponse(self::view(), HttpStatusCode::Ok, new Collection(Header::class)->with(
            new Header(ResponseHeader::SetCookie, SetCookie::language(Language::German)),
            new Header(ResponseHeader::SetCookie, SetCookie::language(Language::English)),
        ))->answer(TestRequest::get('/')->request());

        self::assertSame(
            [
                'Set-Cookie: ' . SetCookie::language(Language::German)->render(),
                'Set-Cookie: ' . SetCookie::language(Language::English)->render(),
            ],
            array_slice(self::lines($answer), -2),
        );
        self::assertStringContainsString('<p>hello</p>', $answer->body());
    }

    /**
     * The document and the fragment are one URL with two bodies, so they must not validate against
     * each other. `Vary` is what says so to a cache; this is why it holds even where `Vary` is
     * ignored — the bytes differ, so the hash of the bytes differs.
     *
     * @return void
     */
    public function testTheFragmentAndTheDocumentDoNotShareAValidator(): void
    {
        $response = new ViewResponse(self::view());

        self::assertNotSame(
            $response->answer(TestRequest::get('/')->request())->header(ResponseHeader::ETag)?->value->render(),
            $response->answer(self::ajax())->header(ResponseHeader::ETag)?->value->render(),
        );
    }

    /**
     * A caller that already said how its response may be kept is not argued with.
     *
     * A page behind a password says `no-store, private`. Adding a validator to that would be
     * offering to revalidate something just asked not to be stored. The `Vary` stays: it says what
     * the body depends on, which no `Cache-Control` changes.
     *
     * @return void
     */
    public function testAResponseThatAlreadySaidHowItMayBeKeptGetsNoValidator(): void
    {
        $answer = new ViewResponse(self::view(), HttpStatusCode::Ok, new Collection(Header::class)->with(
            new Header(ResponseHeader::CacheControl, CacheControl::doNotStore()),
        ))->answer(TestRequest::get('/')->request());

        self::assertSame(
            [
                'Content-Type: text/html; charset=utf-8',
                'Content-Language: en',
                self::VARY,
                'Cache-Control: no-store, private',
            ],
            self::lines($answer),
        );
    }

    /**
     * A 404 is told to revalidate — left to its heuristics a browser may keep one, for a page that
     * has since been published — and carries no validator, because only a success is one.
     *
     * @return void
     */
    public function testANotFoundPageRevalidatesAndCarriesNoValidator(): void
    {
        $answer = new ViewResponse(self::view(), HttpStatusCode::NotFound)
            ->answer(TestRequest::get('/gone')->request());

        self::assertSame(HttpStatusCode::NotFound, $answer->status());
        self::assertSame(
            ['Content-Type: text/html; charset=utf-8', 'Content-Language: en', 'Cache-Control: no-cache', self::VARY],
            self::lines($answer),
        );
    }

    // ───────────────────────────── status codes and headers ─────────────────────────────

    /**
     * @param HttpStatusCode $case
     * @param int            $value
     * @return void
     */
    #[DataProvider('statusProvider')]
    public function testTheStatusCodesTheFrameworkSendsHaveTheRightValues(HttpStatusCode $case, int $value): void
    {
        self::assertSame($value, $case->value);
    }

    /**
     * @return iterable<array{HttpStatusCode, int}>
     */
    public static function statusProvider(): iterable
    {
        yield [HttpStatusCode::Ok, 200];
        yield [HttpStatusCode::SeeOther, 303];
        yield [HttpStatusCode::NotModified, 304];
        yield [HttpStatusCode::Unauthorized, 401];
        yield [HttpStatusCode::NotFound, 404];
        yield [HttpStatusCode::MethodNotAllowed, 405];
        yield [HttpStatusCode::ServiceUnavailable, 503];
    }

    /**
     * Every header a response sends is formatted in one place rather than at each `header()` call.
     *
     * @return void
     */
    public function testAHeaderFormatsItselfAsNameColonValue(): void
    {
        self::assertSame(
            'Cache-Control: no-store, private',
            new Header(ResponseHeader::CacheControl, CacheControl::doNotStore())->line(),
        );
    }

    /**
     * Each name goes on the wire as its backing value; there is no second spelling anywhere.
     *
     * @return void
     */
    public function testEveryResponseHeaderIsNamedAsItGoesOnTheWire(): void
    {
        foreach (ResponseHeader::cases() as $header) {
            self::assertSame($header->value, $header->headerName());
        }
    }

    /**
     * A request Navigation made, for `/`.
     *
     * @return Request
     */
    private static function ajax(): Request
    {
        return TestRequest::get('/')->with(RequestHeader::RequestedWith, 'XMLHttpRequest')->request();
    }

    /**
     * @param Answer $answer
     * @return list<string>
     */
    private static function lines(Answer $answer): array
    {
        return $answer->headers()->map(static fn(Header $header): string => $header->line())->toValues();
    }

    /**
     * A page titled $title, holding one paragraph.
     *
     * @param string $title
     * @return View
     */
    private static function view(string $title = 'Page'): View
    {
        return new class ($title) extends View {
            /**
             * @param string $title
             */
            public function __construct(private string $title) {}

            /**
             * @return Translatable
             */
            public function pageTitle(): Translatable
            {
                return new Verbatim($this->title);
            }

            /**
             * @return Node
             */
            public function content(): Node
            {
                return new Element(HtmlTag::P)->containing('hello');
            }
        };
    }
}
