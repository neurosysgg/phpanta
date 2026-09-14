<?php

declare(strict_types=1);

namespace Phpanta\Test\Unit;

use Phpanta\Exception\MimeTypeException;
use Phpanta\Exception\SecurityPolicyException;
use Phpanta\Http\AcceptRanges;
use Phpanta\Http\Answer;
use Phpanta\Http\ByteRange;
use Phpanta\Http\ContentLength;
use Phpanta\Http\ContentRange;
use Phpanta\Http\FileBody;
use Phpanta\Http\FileResponse;
use Phpanta\Http\HttpMethod;
use Phpanta\Http\MimeType;
use Phpanta\Http\RequestHeader;
use Phpanta\Http\RobotsDirective;
use Phpanta\Http\RobotsPolicy;
use Phpanta\Support\Directory;
use Phpanta\Support\File;
use Phpanta\Test\TestRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A file answered in the part asked for, and the small typed values that say so.
 *
 * **Ranges work**, which is not a nicety: an `<audio>` element seeks by asking for one, so a server
 * that ignores them gives you a player that will not skip, with nothing in any console. What is
 * asserted here is the parse — every header shape, down to a number too long to be an integer — and
 * that the bytes that come back are exactly the ones named. The statuses and headers a range is
 * answered with, end to end, are {@link AnswerTest}'s.
 */
#[CoversClass(ByteRange::class)]
#[CoversClass(ContentRange::class)]
#[CoversClass(ContentLength::class)]
#[CoversClass(AcceptRanges::class)]
#[CoversClass(RobotsPolicy::class)]
#[CoversClass(RobotsDirective::class)]
#[CoversClass(MimeType::class)]
#[CoversClass(FileResponse::class)]
#[CoversClass(FileBody::class)]
#[CoversClass(Answer::class)]
#[CoversClass(SecurityPolicyException::class)]
#[CoversClass(MimeTypeException::class)]
final class FileResponseTest extends TestCase
{
    private Directory $fixtures;

    private File $file;

    /**
     * Ten bytes whose values are their own offsets, so a wrong slice is legible in the failure.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->fixtures = Directory::temporary('phpanta-file-');
        $this->file     = $this->fixtures->file('v3.mp3');

        self::assertTrue($this->file->write('0123456789'));
    }

    /**
     * @return void
     */
    protected function tearDown(): void
    {
        foreach ($this->fixtures->files() as $file) {
            $file->delete();
        }

        $this->fixtures->remove();
    }

    // ───────────────────────────── ByteRange ─────────────────────────────

    /**
     * @param string   $header
     * @param int      $size
     * @param int|null $first Null where the header names nothing this reads.
     * @param int|null $last
     * @return void
     */
    #[DataProvider('rangeProvider')]
    public function testARangeHeaderResolvesAgainstTheSizeOfTheFile(
        string $header,
        int $size,
        ?int $first,
        ?int $last,
    ): void {
        $range = ByteRange::parse($header, $size);

        if ($first === null) {
            self::assertNull($range, 'this header names no single byte range');

            return;
        }

        self::assertNotNull($range);
        self::assertSame($first, $range->first);
        self::assertSame($last, $range->last);
    }

    /**
     * @return iterable<string, array{string, int, ?int, ?int}>
     */
    public static function rangeProvider(): iterable
    {
        yield 'a closed range'        => ['bytes=0-499', 5000, 0, 499];
        yield 'one byte'              => ['bytes=0-0', 5000, 0, 0];
        yield 'open ended'            => ['bytes=500-', 5000, 500, 4999];
        yield 'a suffix'              => ['bytes=-500', 5000, 4500, 4999];
        yield 'a suffix longer than the file' => ['bytes=-9000', 5000, 0, 4999];
        yield 'clamped to the end'    => ['bytes=4000-9999', 5000, 4000, 4999];
        yield 'surrounding space'     => [' bytes=0-9 ', 5000, 0, 9];
        yield 'the unit in any case'  => ['Bytes=0-9', 5000, 0, 9];
        yield 'leading zeros'         => ['bytes=0010-0019', 5000, 10, 19];

        // Understood, and unsatisfiable — a 416, which is a different answer from ignoring it.
        yield 'past the end'          => ['bytes=9000-', 5000, 9000, 4999];
        yield 'a zero-length suffix'  => ['bytes=-0', 5000, 1, 0];
        yield 'a zero-length suffix of nothing' => ['bytes=-0', 0, 1, 0];

        // Not understood. Every one of these means "send the whole file", which is always legal.
        yield 'no header'             => ['', 5000, null, null];
        yield 'backwards'             => ['bytes=500-100', 5000, null, null];
        yield 'a list'                => ['bytes=0-99,200-299', 5000, null, null];
        yield 'another unit'          => ['items=0-99', 5000, null, null];
        yield 'no unit'               => ['0-99', 5000, null, null];
        yield 'nothing at all'        => ['bytes=-', 5000, null, null];
        yield 'not a number'          => ['bytes=a-b', 5000, null, null];
        yield 'negative'              => ['bytes=--5', 5000, null, null];

        // Numbers too long to be integers. Cast, they saturate at PHP_INT_MAX and name a different
        // request from the one sent — the first would be a 416 for a position nobody asked for.
        yield 'a start past any integer'  => ['bytes=99999999999999999999-', 5000, null, null];
        yield 'an end past any integer'   => ['bytes=10-99999999999999999999', 5000, null, null];
        yield 'a suffix past any integer' => ['bytes=-99999999999999999999', 5000, null, null];

        // An empty file has no last 500 bytes, and a 206 has no way to say so; a non-zero suffix is
        // satisfiable whatever the length, so not a 416 either. The whole of it — nothing — with a 200.
        yield 'a suffix of an empty file' => ['bytes=-500', 0, null, null];
    }

    /**
     * @return void
     */
    public function testSatisfiabilityIsAskedSeparatelyFromUnderstanding(): void
    {
        self::assertTrue(ByteRange::parse('bytes=0-499', 5000)?->isSatisfiable());
        self::assertSame(500, ByteRange::parse('bytes=0-499', 5000)?->length());

        // Understood; the file is simply too short.
        self::assertFalse(ByteRange::parse('bytes=9000-', 5000)?->isSatisfiable());
        self::assertSame(0, ByteRange::parse('bytes=9000-', 5000)?->length());
        self::assertFalse(ByteRange::parse('bytes=-0', 5000)?->isSatisfiable());

        // An empty file holds no byte, so no range over it is satisfiable.
        self::assertFalse(ByteRange::parse('bytes=0-', 0)?->isSatisfiable());
    }

    // ───────────────────────────── the header values ─────────────────────────────

    /**
     * @return void
     */
    public function testContentRangeStatesThePartOrTheSizeAlone(): void
    {
        $range = ByteRange::parse('bytes=0-1023', 5000);

        self::assertNotNull($range);
        self::assertSame('bytes 0-1023/5000', ContentRange::of($range)->render());
        self::assertSame('bytes */5000', ContentRange::unsatisfiable(5000)->render());
    }

    /**
     * @return void
     */
    public function testTheSmallHeaderValuesRenderAsTheirGrammarRequires(): void
    {
        self::assertSame('4096', new ContentLength(4096)->render());
        self::assertSame('0', new ContentLength(0)->render());
        self::assertSame('bytes', AcceptRanges::Bytes->render());
        self::assertSame('none', AcceptRanges::None->render());
        self::assertSame('noindex, nofollow, noarchive', RobotsPolicy::hide()->render());
        self::assertSame('noindex', RobotsPolicy::of(RobotsDirective::NoIndex)->render());
    }

    /**
     * A negative length is not a header. The way to get one is arithmetic on a range that went
     * wrong, which is precisely the code this class ships alongside.
     *
     * @return void
     */
    public function testANegativeContentLengthIsRefused(): void
    {
        $this->expectException(SecurityPolicyException::class);

        new ContentLength(-1);
    }

    /**
     * An empty `X-Robots-Tag` is a malformed header rather than a permissive one — the same rule
     * `CacheControl::of()` and `Vary::on()` follow. A response with nothing to ask omits it.
     *
     * @return void
     */
    public function testAnEmptyRobotsPolicyIsRefused(): void
    {
        $this->expectException(SecurityPolicyException::class);

        RobotsPolicy::of();
    }

    // ───────────────────────────── MimeType ─────────────────────────────

    /**
     * Audio is bytes, not characters, so it carries no charset — and an extension nothing knows
     * throws rather than falling back, because `nosniff` means a wrong type cannot be corrected by
     * the browser: an MP3 typed as octet-stream downloads instead of playing.
     *
     * @return void
     */
    public function testAudioTypesCarryNoCharsetAndAnUnknownExtensionThrows(): void
    {
        self::assertSame('audio/mpeg', MimeType::forAudio('mp3')->render());
        self::assertSame('audio/mpeg', MimeType::forAudio('MP3')->render());
        self::assertSame('audio/flac', MimeType::forAudio('flac')->render());
        self::assertSame('audio/wav', MimeType::forAudio('wav')->render());
        self::assertSame('audio/mp4', MimeType::forAudio('m4a')->render());
        self::assertSame('audio/ogg', MimeType::forAudio('ogg')->render());
        self::assertSame('audio/opus', MimeType::forAudio('opus')->render());
        self::assertNull(MimeType::forAudio('mp3')->charset);

        $this->expectException(MimeTypeException::class);
        MimeType::forAudio('exe');
    }

    // ───────────────────────────── FileResponse ─────────────────────────────

    /**
     * The bytes have to be exactly the ones named, or a browser treats the response as broken
     * rather than as an approximation.
     *
     * @param string $header
     * @param string $expected
     * @return void
     */
    #[DataProvider('rangeBodyProvider')]
    public function testARangedRequestGetsExactlyTheBytesItNamed(string $header, string $expected): void
    {
        $request = TestRequest::get('/files/v3')->with(RequestHeader::Range, $header)->request();

        self::assertSame($expected, $this->response()->answer($request)->body());
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function rangeBodyProvider(): iterable
    {
        yield 'the first three'  => ['bytes=0-2', '012'];
        yield 'the middle'       => ['bytes=3-5', '345'];
        yield 'to the end'       => ['bytes=7-', '789'];
        yield 'one byte'         => ['bytes=5-5', '5'];
        yield 'clamped'          => ['bytes=8-99', '89'];

        // Unsatisfiable: no body at all, because the whole file would look like the part asked for.
        yield 'past the end'     => ['bytes=50-', ''];

        // Not understood: the whole file, which is always a legal answer to a Range.
        yield 'a list'           => ['bytes=0-1,4-5', '0123456789'];
    }

    /**
     * A HEAD asks what a GET would answer, not for the answer. Other responses echo regardless and
     * let the server drop the body; a file is read off disk, so this one does not — whether or not
     * the HEAD names a range.
     *
     * @return void
     */
    public function testHeadSendsNoBody(): void
    {
        $request = TestRequest::to(HttpMethod::Head, '/files/v3')->request();

        self::assertSame('', $this->response()->answer($request)->body());
    }

    /**
     * A file that cannot be opened is no body, not a PHP warning printed into the audio.
     *
     * A caller asks `exists()` first, which is `is_file()` and says nothing about whether the file
     * can be *read* — the exact gap `File::read()`'s docblock names, where an unreadable file once
     * put an `E_WARNING` ahead of a page's doctype. Here the headers have already gone out, so a
     * warning would land in the middle of the stream.
     *
     * Deleting it between construction and the answer is the honest version of that: the same race a
     * live deploy can produce, and it costs no assumptions about file modes or which user is running
     * the suite. {@link AnswerTest} deletes it later still, between the answer and its body.
     *
     * @return void
     */
    public function testAFileThatCannotBeOpenedSendsNoBodyAndNoWarning(): void
    {
        $response = $this->response();

        self::assertTrue($this->file->delete());
        self::assertSame('', $response->answer(TestRequest::get('/files/v3')->request())->body());
    }

    /**
     * @return FileResponse
     */
    private function response(): FileResponse
    {
        return new FileResponse($this->file, MimeType::forAudio('mp3'));
    }
}
