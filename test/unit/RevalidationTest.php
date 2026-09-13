<?php

declare(strict_types=1);

namespace Phpanta\Test\Unit;

use Phpanta\Http\ETag;
use Phpanta\Http\HttpStatusCode;
use Phpanta\Http\Request;
use Phpanta\Http\ViewResponse;
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

    /** @var array<string, mixed> */
    private array $server;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        $this->server = $_SERVER;
    }

    /**
     * @return void
     */
    protected function tearDown(): void
    {
        $_SERVER = $this->server;
    }

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
        $body     = $this->send($response, '');

        self::assertStringContainsString('<p>hello</p>', $body);
        self::assertSame('', $this->send($response, ETag::forBody($body)->render()));
        self::assertSame('', $this->send($response, '*'));
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
        $body     = $this->send($response, '');

        self::assertSame($body, $this->send($response, ETag::forBody($body)->render()));
        self::assertSame($body, $this->send($response, '*'));
    }

    /**
     * What $response sends to a `GET /` carrying $ifNoneMatch.
     *
     * @param ViewResponse $response
     * @param string       $ifNoneMatch
     * @return string
     */
    private function send(ViewResponse $response, string $ifNoneMatch): string
    {
        $_SERVER = ['REQUEST_URI' => '/', 'HTTP_IF_NONE_MATCH' => $ifNoneMatch];

        ob_start();
        $response->send(Request::fromGlobals());

        return (string) ob_get_clean();
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
