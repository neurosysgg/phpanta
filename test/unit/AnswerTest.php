<?php

declare(strict_types=1);

namespace Phpanta\Test\Unit;

use Phpanta\App;
use Phpanta\Controller\ApiController;
use Phpanta\Controller\UnroutedController;
use Phpanta\Http\Answer;
use Phpanta\Http\FileBody;
use Phpanta\Http\FileResponse;
use Phpanta\Http\Header;
use Phpanta\Http\HttpMethod;
use Phpanta\Http\HttpStatusCode;
use Phpanta\Http\Location;
use Phpanta\Http\MimeType;
use Phpanta\Http\PlainTextResponse;
use Phpanta\Http\RedirectResponse;
use Phpanta\Http\Request;
use Phpanta\Http\RequestHeader;
use Phpanta\Http\ResponseHeader;
use Phpanta\Http\SecurityHeaders;
use Phpanta\Http\ServerParameters;
use Phpanta\Http\ServerVariable;
use Phpanta\Http\SetCookie;
use Phpanta\Http\TextBody;
use Phpanta\Router;
use Phpanta\Service\Auth;
use Phpanta\Support\Collection;
use Phpanta\Support\File;
use Phpanta\Test\TestRequest;
use Phpanta\Text\Language;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * What goes on the wire, asserted in-process: every response returns an {@link Answer}, and
 * {@link App::handle()} answers a whole request — gate, router, controller, security headers —
 * without sending anything.
 */
#[CoversClass(Answer::class)]
#[CoversClass(TextBody::class)]
#[CoversClass(FileBody::class)]
#[CoversClass(FileResponse::class)]
#[CoversClass(PlainTextResponse::class)]
#[CoversClass(RedirectResponse::class)]
#[CoversClass(ServerParameters::class)]
#[CoversClass(ServerVariable::class)]
#[CoversClass(Request::class)]
#[CoversClass(RequestHeader::class)]
#[CoversClass(Auth::class)]
#[CoversClass(App::class)]
#[CoversClass(Router::class)]
#[CoversClass(UnroutedController::class)]
#[CoversClass(ApiController::class)]
#[CoversClass(SecurityHeaders::class)]
final class AnswerTest extends TestCase
{
    /** A directory of this test's own, emptied afterwards. */
    private string $scratch;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        $this->scratch = sys_get_temp_dir() . '/phpanta-answer-' . bin2hex(random_bytes(6));
        mkdir($this->scratch);
    }

    /**
     * @return void
     */
    protected function tearDown(): void
    {
        foreach (scandir($this->scratch) ?: [] as $name) {
            if ($name !== '.' && $name !== '..') {
                unlink("$this->scratch/$name");
            }
        }

        rmdir($this->scratch);
    }

    // ───────────────────────── the app, end to end ─────────────────────────

    /**
     * @return void
     */
    public function testAReadNoRouteClaimsIsTheAppsNotFound(): void
    {
        $answer = TestRequest::get('/no-such-page')->answer();

        self::assertSame(HttpStatusCode::NotFound, $answer->status());
        self::assertSame("404\n", $answer->body());
        self::assertSame('text/plain; charset=utf-8', $answer->header(ResponseHeader::ContentType)?->value->render());
    }

    /**
     * @return void
     */
    public function testAWriteNoRouteClaimsIsRefusedNamingTheReadMethods(): void
    {
        $answer = TestRequest::to(HttpMethod::Post, '/no-such-page')->answer();

        self::assertSame(HttpStatusCode::MethodNotAllowed, $answer->status());
        self::assertSame('GET, HEAD', $answer->header(ResponseHeader::Allow)?->value->render());
        self::assertSame(UnroutedController::refusal(Language::English), $answer->body());
    }

    /**
     * The API unsigned is an address that is not there — to every verb, including one nobody knows,
     * and to the German it would be refused in. Nothing on the wire tells the two apart.
     *
     * @param string $method
     * @param string $api
     * @return void
     */
    #[DataProvider('unsignedProvider')]
    public function testAnUnsignedApiCallIsAnsweredAsAnAddressThatIsNotThere(string $method, string $api): void
    {
        $asked  = TestRequest::to($method, $api)->with(RequestHeader::AcceptLanguage, 'de')->answer();
        $absent = TestRequest::to($method, '/no-such-page')->with(RequestHeader::AcceptLanguage, 'de')->answer();

        self::assertSame($absent->status(), $asked->status());
        self::assertSame(self::lines($absent), self::lines($asked));
        self::assertSame($absent->body(), $asked->body());
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function unsignedProvider(): iterable
    {
        yield 'a read'               => ['GET', '/api/update/v1/version'];
        yield 'a write'              => ['POST', '/api/update/v1/patch'];
        yield 'a write to a read'    => ['PUT', '/api/update/v1/version'];
        yield 'a verb nobody knows'  => ['BREW', '/api/update/v1/patch'];
        yield 'a service not there'  => ['GET', '/api/nope/v1/nothing'];
        yield 'too deep'             => ['POST', '/api/update/v1/patch/more'];
    }

    /**
     * The five security headers lead every answer, in the order they were always sent, and exactly
     * once each.
     *
     * @param string $method
     * @param string $target
     * @return void
     */
    #[DataProvider('everyKindProvider')]
    public function testEveryAnswerLeadsWithTheSecurityHeaders(string $method, string $target): void
    {
        $expected = SecurityHeaders::all()->map(static fn(Header $header): string => $header->line())->toValues();
        $lines    = self::lines(TestRequest::to($method, $target)->answer());

        self::assertSame($expected, array_slice($lines, 0, count($expected)));
        self::assertSame($lines, array_values(array_unique($lines)), 'a header was sent twice');
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function everyKindProvider(): iterable
    {
        yield 'a 404' => ['GET', '/no-such-page'];
        yield 'a 405' => ['DELETE', '/'];
        yield 'the API, unsigned' => ['POST', '/api/update/v1/patch'];
    }

    // ───────────────────────── the pre-launch gate ─────────────────────────

    /**
     * With a credentials file, the gate refuses anything but its own pair with the app's challenge,
     * and no body; with the right pair, or with no file at all, it stands aside.
     *
     * @return void
     */
    public function testTheSiteGateRefusesWithTheAppsChallengeAndOtherwiseStandsAside(): void
    {
        $file = new File("$this->scratch/site_auth.php");
        $file->write('<?php return ' . var_export([
            'user'      => 'preview',
            'pass_hash' => password_hash('hunter2', PASSWORD_BCRYPT, ['cost' => 4]),
        ], true) . ';');

        $wrong   = TestRequest::get('/')->withCredentials('preview', 'wrong')->request();
        $refusal = Auth::siteGate($wrong, $file);

        self::assertNotNull($refusal);

        $answer = $refusal->answer($wrong);

        self::assertSame(HttpStatusCode::Unauthorized, $answer->status());
        self::assertSame(
            'Basic realm="phpanta"',
            $answer->header(ResponseHeader::WwwAuthenticate)?->value->render(),
        );
        self::assertSame('', $answer->body());

        $right = TestRequest::get('/')->withCredentials('preview', 'hunter2')->request();

        self::assertNull(Auth::siteGate($right, $file));
        self::assertNull(Auth::siteGate($wrong, new File("$this->scratch/absent.php")));
    }

    // ───────────────────────── the responses ─────────────────────────

    /**
     * A redirect's status is its own, its extra headers go ahead of the `Location`, a second header
     * of a name that repeats is kept rather than folded into the first, and there is no body.
     *
     * @return void
     */
    public function testARedirectCarriesItsStatusItsHeadersAndNoBody(): void
    {
        $answer = new RedirectResponse(
            new Location('/there'),
            HttpStatusCode::MovedPermanently,
            new Collection(Header::class)->with(
                new Header(ResponseHeader::SetCookie, SetCookie::language(Language::English)),
                new Header(ResponseHeader::SetCookie, SetCookie::language(Language::German)),
            ),
        )->answer(TestRequest::get('/')->request());

        self::assertSame(HttpStatusCode::MovedPermanently, $answer->status());
        self::assertSame(
            [
                'Set-Cookie: ' . SetCookie::language(Language::English)->render(),
                'Set-Cookie: ' . SetCookie::language(Language::German)->render(),
                'Location: /there',
            ],
            self::lines($answer),
        );
        self::assertSame('', $answer->body());
    }

    /**
     * @return void
     */
    public function testPlainTextStatesItsTypeAheadOfItsOwnHeaders(): void
    {
        $answer = new PlainTextResponse(
            HttpStatusCode::ServiceUnavailable,
            "not yet\n",
            new Collection(Header::class)->with(new Header(ResponseHeader::Location, new Location('/later'))),
        )->answer(TestRequest::get('/')->request());

        self::assertSame(HttpStatusCode::ServiceUnavailable, $answer->status());
        self::assertSame(['Content-Type: text/plain; charset=utf-8', 'Location: /later'], self::lines($answer));
        self::assertSame("not yet\n", $answer->body());
    }

    /**
     * A file is answered whole, in the part asked for, or — for a part it does not hold — with its
     * size and nothing else; a HEAD is told the length and given no bytes.
     *
     * @param string         $method
     * @param string         $range
     * @param HttpStatusCode $status
     * @param string         $body
     * @param string|null    $length
     * @param string|null    $contentRange
     * @return void
     */
    #[DataProvider('fileProvider')]
    public function testAFileIsAnsweredWholeInPartOrNotAtAll(
        string $method,
        string $range,
        HttpStatusCode $status,
        string $body,
        ?string $length,
        ?string $contentRange,
    ): void {
        $request = TestRequest::to($method, '/file');

        if ($range !== '') {
            $request = $request->with(RequestHeader::Range, $range);
        }

        $answer = $this->fileResponse('0123456789')->answer($request->request());

        self::assertSame($status, $answer->status());
        self::assertSame($body, $answer->body());
        self::assertSame($length, $answer->header(ResponseHeader::ContentLength)?->value->render());
        self::assertSame($contentRange, $answer->header(ResponseHeader::ContentRange)?->value->render());
        self::assertSame('no-store, private', $answer->header(ResponseHeader::CacheControl)?->value->render());
        self::assertNull($answer->header(ResponseHeader::ETag));
    }

    /**
     * @return iterable<string, array{string, string, HttpStatusCode, string, string|null, string|null}>
     */
    public static function fileProvider(): iterable
    {
        yield 'whole'          => ['GET', '', HttpStatusCode::Ok, '0123456789', '10', null];
        yield 'a part'         => ['GET', 'bytes=2-4', HttpStatusCode::PartialContent, '234', '3', 'bytes 2-4/10'];
        yield 'the last bytes' => ['GET', 'bytes=-3', HttpStatusCode::PartialContent, '789', '3', 'bytes 7-9/10'];
        yield 'past the end'   => ['GET', 'bytes=20-30', HttpStatusCode::RangeNotSatisfiable, '', '0', 'bytes */10'];
        yield 'a HEAD'         => ['HEAD', 'bytes=2-4', HttpStatusCode::Ok, '', '10', null];
    }

    /**
     * A file larger than one chunk comes back whole, in order — the read is a loop, and the last
     * read is cut to what is left.
     *
     * @return void
     */
    public function testAFileLargerThanAChunkIsReadWhole(): void
    {
        $contents = str_repeat('0123456789abcdef', 20000);

        $answer   = $this->fileResponse($contents)->answer(TestRequest::get('/file')->request());

        self::assertSame($contents, $answer->body());
    }

    /**
     * A file that has gone by the time its bytes are read is no body, and no warning written into
     * the body in its place.
     *
     * @return void
     */
    public function testAFileGoneBeforeItIsReadIsNoBody(): void
    {
        $answer = $this->fileResponse('0123456789')->answer(TestRequest::get('/file')->request());
        unlink("$this->scratch/file.mp3");

        self::assertSame(HttpStatusCode::Ok, $answer->status());
        self::assertSame('', $answer->body());
    }

    // ───────────────────────── the answer itself ─────────────────────────

    /**
     * @return void
     */
    public function testAHeaderIsFoundByItsNameAndAnAbsentOneIsNull(): void
    {
        $answer = new Answer(
            HttpStatusCode::Ok,
            new Collection(Header::class)->with(
                new Header(ResponseHeader::Location, new Location('/first')),
                new Header(ResponseHeader::Location, new Location('/second')),
            ),
        );

        self::assertSame('/first', $answer->header(ResponseHeader::Location)?->value->render());
        self::assertNull($answer->header(ResponseHeader::Allow));
        self::assertSame('', $answer->body());
    }

    /**
     * @return void
     */
    public function testHeadersPutFirstGoAheadAndTheAnswerItselfIsUnchanged(): void
    {
        $answer = new Answer(
            HttpStatusCode::Ok,
            new Collection(Header::class)->with(new Header(ResponseHeader::Location, new Location('/own'))),
            new TextBody('body'),
        );
        $first  = new Collection(Header::class)->with(new Header(ResponseHeader::Location, new Location('/first')));
        $led    = $answer->withHeadersFirst($first);

        self::assertSame(['Location: /first', 'Location: /own'], self::lines($led));
        self::assertSame(['Location: /own'], self::lines($answer));
        self::assertSame(HttpStatusCode::Ok, $led->status());
        self::assertSame('body', $led->body());
    }

    /**
     * Sending writes the body, after the headers. The headers themselves go nowhere under the CLI;
     * the end-to-end suite of a site built on this is what sees them arrive.
     *
     * @return void
     */
    public function testSendingWritesTheBody(): void
    {
        $answer = new Answer(
            HttpStatusCode::Ok,
            new Collection(Header::class)->with(new Header(ResponseHeader::ContentType, MimeType::plainText())),
            new TextBody('sent'),
        );

        ob_start();
        $answer->send();

        self::assertSame('sent', ob_get_clean());
    }

    /**
     * A request built with a body answers that, cut to the limit it is asked with, and never reads
     * `php://input`.
     *
     * @return void
     */
    public function testARequestBuiltWithABodyAnswersIt(): void
    {
        $request = TestRequest::to(HttpMethod::Post, '/')->withBody('0123456789')->request();

        self::assertSame('0123456789', $request->body());
        self::assertSame('0123', $request->body(4));
    }

    /**
     * A variable that is not a string is no variable at all, the way a missing one is.
     *
     * @return void
     */
    public function testAServerVariableThatIsNotAStringIsAbsent(): void
    {
        $server = new ServerParameters([ServerVariable::RequestUri->value => ['/'], 'HTTP_RANGE' => 5]);

        self::assertNull($server->string(ServerVariable::RequestUri));
        self::assertSame('', $server->header(RequestHeader::Range));
    }

    /**
     * @param string $contents
     * @return FileResponse
     */
    private function fileResponse(string $contents): FileResponse
    {
        $file = new File("$this->scratch/file.mp3");
        $file->write($contents);

        return new FileResponse($file, MimeType::forAudio('mp3'));
    }

    /**
     * @param Answer $answer
     * @return list<string>
     */
    private static function lines(Answer $answer): array
    {
        return $answer->headers()->map(static fn(Header $header): string => $header->line())->toValues();
    }
}
