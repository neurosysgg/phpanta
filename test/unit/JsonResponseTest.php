<?php

declare(strict_types=1);

namespace Phpanta\Test\Unit;

use JsonException;
use JsonSerializable;
use Phpanta\Exception\JsonEncodingException;
use Phpanta\Exception\SiteException;
use Phpanta\Http\Answer;
use Phpanta\Http\CacheControl;
use Phpanta\Http\Header;
use Phpanta\Http\HttpMethod;
use Phpanta\Http\HttpStatusCode;
use Phpanta\Http\JsonResponse;
use Phpanta\Http\MimeType;
use Phpanta\Http\ResponseHeader;
use Phpanta\Http\RobotsPolicy;
use Phpanta\Http\TextBody;
use Phpanta\Support\Collection;
use Phpanta\Test\TestRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * A value answered as JSON: its type, its cache header, and the four flags it is encoded with.
 *
 * The flags are asserted by what they do to a body rather than by reading the constant, because
 * dropping one is not an error anywhere. The body is still valid JSON, just a different one.
 */
#[CoversClass(JsonResponse::class)]
#[CoversClass(JsonEncodingException::class)]
#[CoversClass(MimeType::class)]
#[CoversClass(Answer::class)]
#[CoversClass(TextBody::class)]
#[CoversClass(Header::class)]
#[CoversClass(CacheControl::class)]
final class JsonResponseTest extends TestCase
{
    /**
     * @return void
     */
    public function testAValueIsAnsweredAsJsonThatACacheMustRevalidate(): void
    {
        $answer = new JsonResponse(self::value(['ok' => true]))->answer(TestRequest::get('/x')->request());

        self::assertSame(HttpStatusCode::Ok, $answer->status());
        self::assertSame(
            ['Content-Type: application/json', 'Cache-Control: no-cache'],
            self::lines($answer),
        );
        self::assertSame('{"ok":true}', $answer->body());
    }

    /**
     * JSON has no charset parameter (RFC 8259 §11), so none is sent.
     *
     * @return void
     */
    public function testTheTypeCarriesNoCharset(): void
    {
        self::assertSame('application/json', MimeType::json()->render());
        self::assertSame('application/json', MimeType::json()->essence());
    }

    /**
     * @return void
     */
    public function testTheStatusAndTheExtraHeadersAreTheCallers(): void
    {
        $answer = new JsonResponse(
            self::value(['id' => 7]),
            HttpStatusCode::Created,
            new Collection(Header::class)->with(new Header(ResponseHeader::Robots, RobotsPolicy::hide())),
        )->answer(TestRequest::get('/x')->request());

        self::assertSame(HttpStatusCode::Created, $answer->status());
        self::assertSame(
            ['Content-Type', 'Cache-Control', 'X-Robots-Tag'],
            $answer->headers()->map(static fn(Header $header): string => $header->name->headerName())->toValues(),
        );
    }

    /**
     * Slashes and non-ASCII characters go out as they are, a float stays a float, and the two line
     * terminators JavaScript cannot hold in a string stay escaped.
     *
     * @return void
     */
    public function testTheEncodingKeepsWhatItShouldAndEscapesOnlyWhatItMust(): void
    {
        $body = new JsonResponse(self::value([
            'url'   => 'https://example.org/a/b',
            'word'  => 'Grüße',
            'float' => 1.0,
            'line'  => "\u{2028}",
        ]))->answer(TestRequest::get('/x')->request())->body();

        self::assertSame('{"url":"https://example.org/a/b","word":"Grüße","float":1.0,"line":"\u2028"}', $body);
    }

    /**
     * A NAN has no JSON, and the refusal arrives when the answer is asked for, as the framework's
     * own exception, a `RuntimeException`, with PHP's reason kept as its cause.
     *
     * @return void
     */
    public function testAValueThatCannotBeWrittenThrowsWithItsCause(): void
    {
        $response = new JsonResponse(self::value(['ratio' => NAN]));

        try {
            (void) $response->answer(TestRequest::get('/x')->request());
            self::fail('a NAN was written as JSON');
        } catch (JsonEncodingException $refused) {
            self::assertInstanceOf(RuntimeException::class, $refused);
            self::assertInstanceOf(SiteException::class, $refused);
            self::assertInstanceOf(JsonException::class, $refused->getPrevious());
            self::assertStringContainsString('Inf and NaN cannot be JSON encoded', $refused->getMessage());
        }
    }

    /**
     * A caller's `Cache-Control` replaces the default rather than going out beside it. Two would be
     * a contradiction a cache resolves however it likes.
     *
     * @return void
     */
    public function testACallersCacheControlReplacesTheDefault(): void
    {
        $answer = new JsonResponse(
            self::value([]),
            headers: new Collection(Header::class)->with(
                new Header(ResponseHeader::CacheControl, CacheControl::doNotStore()),
            ),
        )->answer(TestRequest::get('/x')->request());

        self::assertSame(['Content-Type: application/json', 'Cache-Control: no-store, private'], self::lines($answer));
    }

    /**
     * A HEAD is answered with the body a GET gets; the server drops it.
     *
     * @return void
     */
    public function testAHeadCarriesTheBody(): void
    {
        $response = new JsonResponse(self::value(['ok' => true]));

        self::assertSame(
            $response->answer(TestRequest::get('/x')->request())->body(),
            $response->answer(TestRequest::to(HttpMethod::Head, '/x')->request())->body(),
        );
    }

    /**
     * @return void
     */
    public function testSendingWritesTheJson(): void
    {
        $answer = new JsonResponse(self::value([1, 2]))->answer(TestRequest::get('/x')->request());

        ob_start();
        $answer->send();

        self::assertSame('[1,2]', ob_get_clean());
    }

    /**
     * What a caller would write as a class of its own, standing in for any value.
     *
     * @param mixed $data
     * @return JsonSerializable
     */
    private static function value(mixed $data): JsonSerializable
    {
        return new readonly class ($data) implements JsonSerializable {
            /**
             * @param mixed $data
             */
            public function __construct(private mixed $data) {}

            /**
             * @return mixed
             */
            public function jsonSerialize(): mixed
            {
                return $this->data;
            }
        };
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
