<?php

declare(strict_types=1);

namespace Phpanta\Test\Unit;

use Closure;
use Generator;
use Phpanta\Http\Answer;
use Phpanta\Http\CacheControl;
use Phpanta\Http\Header;
use Phpanta\Http\HttpMethod;
use Phpanta\Http\HttpStatusCode;
use Phpanta\Http\MimeType;
use Phpanta\Http\ResponseHeader;
use Phpanta\Http\StreamBody;
use Phpanta\Http\StreamResponse;
use Phpanta\Http\TextBody;
use Phpanta\Http\TopLevelType;
use Phpanta\Support\Collection;
use Phpanta\Test\TestRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * A body made while it is sent: the chunks in order, the closure run only when the body is asked
 * for and never for a HEAD, and each chunk written before the next is made.
 */
#[CoversClass(StreamResponse::class)]
#[CoversClass(StreamBody::class)]
#[CoversClass(Answer::class)]
#[CoversClass(TextBody::class)]
#[CoversClass(Header::class)]
#[CoversClass(CacheControl::class)]
#[CoversClass(MimeType::class)]
final class StreamResponseTest extends TestCase
{
    /**
     * The status, the type, `no-store`, no `Content-Length`, and the chunks in the order they were
     * made.
     *
     * @return void
     */
    public function testTheChunksAreTheBodyInOrder(): void
    {
        $answer = self::csv(static function (): Generator {
            yield from ["a,b\n", "1,2\n", "3,4\n"];
        })->answer(TestRequest::get('/export')->request());

        self::assertSame(HttpStatusCode::Ok, $answer->status());
        self::assertSame(
            ['Content-Type: text/csv; charset=utf-8', 'Cache-Control: no-store'],
            self::lines($answer),
        );
        self::assertNull($answer->header(ResponseHeader::ContentLength));
        self::assertSame("a,b\n1,2\n3,4\n", $answer->body());
    }

    /**
     * Building the response and answering it make nothing; each read of the body runs the closure
     * afresh, so a generator is never read after it is spent.
     *
     * @return void
     */
    public function testTheClosureRunsOnlyWhenTheBodyIsAskedFor(): void
    {
        $calls  = 0;
        $answer = self::csv(static function () use (&$calls): Generator {
            $calls++;
            yield 'row';
        })->answer(TestRequest::get('/export')->request());

        self::assertSame(0, $calls);
        self::assertSame('row', $answer->body());
        self::assertSame('row', $answer->body());
        self::assertSame(2, $calls);
    }

    /**
     * Sending writes each chunk before the next one is made. The generator reads the output buffer
     * between yields, which holds exactly what was written so far.
     *
     * @return void
     */
    public function testSendingWritesEachChunkBeforeTheNextIsMade(): void
    {
        $seen   = [];
        $answer = self::csv(static function () use (&$seen): Generator {
            yield 'one ';
            $seen[] = ob_get_contents();
            yield 'two';
            $seen[] = ob_get_contents();
        })->answer(TestRequest::get('/export')->request());

        ob_start();
        $answer->send();

        self::assertSame('one two', ob_get_clean());
        self::assertSame(['one ', 'one two'], $seen);
    }

    /**
     * A HEAD gets the GET's status and headers and no body, and the closure never runs. For an
     * event stream, running it would mean never answering.
     *
     * @return void
     */
    public function testAHeadNeverRunsTheClosure(): void
    {
        $calls    = 0;
        $response = self::csv(static function () use (&$calls): Generator {
            $calls++;
            yield 'row';
        });

        $head = $response->answer(TestRequest::to(HttpMethod::Head, '/export')->request());
        $get  = $response->answer(TestRequest::get('/export')->request());

        self::assertSame('', $head->body());
        self::assertSame(0, $calls);
        self::assertSame($get->status(), $head->status());
        self::assertSame(self::lines($get), self::lines($head));
    }

    /**
     * A caller's `Cache-Control` replaces `no-store`, and the extra headers follow the response's own.
     *
     * @return void
     */
    public function testTheCallersHeadersFollowAndTheirCacheControlReplacesTheDefault(): void
    {
        $answer = new StreamResponse(
            HttpStatusCode::Accepted,
            new MimeType(TopLevelType::Text, 'event-stream'),
            static function (): Generator {
                yield "data: 1\n\n";
            },
            new Collection(Header::class)->with(new Header(ResponseHeader::CacheControl, CacheControl::revalidate())),
        )->answer(TestRequest::get('/events')->request());

        self::assertSame(HttpStatusCode::Accepted, $answer->status());
        self::assertSame(
            ['Content-Type: text/event-stream; charset=utf-8', 'Cache-Control: no-cache'],
            self::lines($answer),
        );
    }

    /**
     * A CSV export made by $chunks.
     *
     * @param Closure(): iterable<string> $chunks
     * @return StreamResponse
     */
    private static function csv(Closure $chunks): StreamResponse
    {
        return new StreamResponse(HttpStatusCode::Ok, new MimeType(TopLevelType::Text, 'csv'), $chunks);
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
