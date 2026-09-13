<?php

declare(strict_types=1);

namespace Phpanta\Test\Unit;

use Phpanta\Http\Answer;
use Phpanta\Http\CacheControl;
use Phpanta\Http\ETag;
use Phpanta\Http\Header;
use Phpanta\Http\HttpStatusCode;
use Phpanta\Http\RequestHeader;
use Phpanta\Http\ResponseHeader;
use Phpanta\Http\ViewResponse;
use Phpanta\Support\Collection;
use Phpanta\Test\TestRequest;
use Phpanta\Text\Translatable;
use Phpanta\Text\Verbatim;
use Phpanta\View\Html\Element;
use Phpanta\View\Html\HtmlTag;
use Phpanta\View\Html\Node;
use Phpanta\View\View;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * When a page is answered with a 304: which `If-None-Match` a validator accepts, and which responses
 * carry a validator at all.
 */
final class RevalidationTest extends TestCase
{
    /** The body the validators below are for. */
    private const string BODY = 'the page';

    /**
     * `If-None-Match` is a list, compared weakly, and what a browser holds is the tag as the server
     * last sent it — which, behind a compressing module, has a coding appended inside the quotes.
     *
     * @param string $ifNoneMatch
     * @param bool   $expected
     * @return void
     */
    #[DataProvider('validatorProvider')]
    public function testAValidatorIsReadAsTheListTheHeaderIs(string $ifNoneMatch, bool $expected): void
    {
        self::assertSame($expected, ETag::forBody(self::BODY)->matches($ifNoneMatch));
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function validatorProvider(): iterable
    {
        $tag = hash('xxh128', self::BODY);

        yield 'as it was sent'           => ["\"$tag\"", true];
        yield 'weakened'                 => ["W/\"$tag\"", true];
        yield 'one of several'           => ["\"0000\", \"$tag\"", true];
        yield 'a list with no spaces'    => ["\"0000\",W/\"$tag\"", true];
        yield 'anything at all'          => ['*', true];
        yield 'anything, padded'         => [' * ', true];
        yield 'gzipped by mod_deflate'   => ["\"$tag-gzip\"", true];
        yield 'by mod_brotli'            => ["\"$tag-br\"", true];
        yield 'deflated'                 => ["\"$tag-deflate\"", true];
        yield 'weakened and gzipped'     => ["W/\"$tag-gzip\"", true];
        yield 'a comma inside a tag'     => ["\"a,b\", \"$tag\"", true];

        yield 'nothing sent'             => ['', false];
        yield 'another page'             => ['"0123456789abcdef"', false];
        yield 'unquoted'                 => [$tag, false];
        yield 'a coding this leaves'     => ["\"$tag-zstd\"", false];
        yield 'a coding twice'           => ["\"$tag-gzip-gzip\"", false];
        yield 'a prefix of it'           => ['"' . substr($tag, 0, 8) . '"', false];
        yield 'a star inside a list'     => ['"0000", *', false];
    }

    /**
     * A success is answered with a 304 for its own validator, and with nothing at all.
     *
     * @return void
     */
    public function testASuccessIsNotModifiedForItsOwnValidator(): void
    {
        $response = new ViewResponse(self::view());
        $whole    = self::answer($response, '');

        self::assertSame(HttpStatusCode::Ok, $whole->status());
        self::assertStringContainsString('<p>hello</p>', $whole->body());

        // The second is what a browser holds behind mod_deflate, `-gzip` inside the quotes: compared
        // verbatim, no compressed page was ever answered with a 304.
        $etag = ETag::forBody($whole->body())->render();

        foreach ([$etag, substr($etag, 0, -1) . '-gzip"', '*'] as $validator) {
            $answer = self::answer($response, $validator);

            self::assertSame(HttpStatusCode::NotModified, $answer->status(), $validator);
            self::assertSame('', $answer->body());
            self::assertNull($answer->header(ResponseHeader::ContentType), 'a 304 describes no content');
        }
    }

    /**
     * A validator for another page, or for a previous build, is not this response.
     *
     * @return void
     */
    public function testAStaleValidatorGetsTheWholePageBack(): void
    {
        $answer = self::answer(new ViewResponse(self::view()), '"0123456789abcdef"');

        self::assertSame(HttpStatusCode::Ok, $answer->status());
        self::assertStringStartsWith('<!DOCTYPE html>', $answer->body());
    }

    /**
     * A response whose caller said how it may be kept — a page behind a password, `no-store` —
     * carries no validator, and so cannot be short-circuited into a 304 by a guessed one either.
     *
     * @return void
     */
    public function testAResponseThatSaidHowItMayBeKeptIsNeverNotModified(): void
    {
        $response = new ViewResponse(self::view(), HttpStatusCode::Ok, new Collection(Header::class)->with(
            new Header(ResponseHeader::CacheControl, CacheControl::doNotStore()),
        ));
        $body     = self::answer($response, '')->body();

        foreach ([ETag::forBody($body)->render(), '*'] as $validator) {
            $answer = self::answer($response, $validator);

            self::assertSame(HttpStatusCode::Ok, $answer->status(), $validator);
            self::assertSame($body, $answer->body());
        }
    }

    /**
     * Anything but a 2xx is sent whole, whatever validator arrives: RFC 9110 §13.2.1 has a server
     * ignore a precondition when the response would not otherwise be a success.
     *
     * @return void
     */
    public function testAnythingButASuccessIsSentWhole(): void
    {
        $response = new ViewResponse(self::view(), HttpStatusCode::NotFound);
        $body     = self::answer($response, '')->body();

        foreach ([ETag::forBody($body)->render(), '*'] as $validator) {
            $answer = self::answer($response, $validator);

            self::assertSame(HttpStatusCode::NotFound, $answer->status());
            self::assertSame($body, $answer->body());
        }
    }

    /**
     * What $response answers a `GET /` carrying $ifNoneMatch with.
     *
     * @param ViewResponse $response
     * @param string       $ifNoneMatch
     * @return Answer
     */
    private static function answer(ViewResponse $response, string $ifNoneMatch): Answer
    {
        return $response->answer(TestRequest::get('/')->with(RequestHeader::IfNoneMatch, $ifNoneMatch)->request());
    }

    /**
     * @return View
     */
    private static function view(): View
    {
        return new class () extends View {
            /**
             * @return Translatable
             */
            public function pageTitle(): Translatable
            {
                return new Verbatim('Page');
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
