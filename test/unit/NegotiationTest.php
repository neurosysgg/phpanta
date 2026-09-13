<?php

declare(strict_types=1);

namespace Phpanta\Test\Unit;

use Phpanta\Http\AcceptedTypes;
use Phpanta\Http\MediaRange;
use Phpanta\Http\MimeType;
use Phpanta\Http\QualityValue;
use Phpanta\Http\Representation;
use Phpanta\Http\Request;
use Phpanta\Http\RequestHeader;
use Phpanta\Test\TestRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * `Accept`: what a request can read, and which of an answer's forms it gets.
 *
 * The table is the claim. A browser, curl and a request that says nothing all get the default; a
 * script that asks for data gets data; a request naming only types on offer at weight zero, or
 * types not on offer at all, gets nothing — which its caller turns into a `406`.
 */
#[CoversClass(AcceptedTypes::class)]
#[CoversClass(MediaRange::class)]
#[CoversClass(QualityValue::class)]
#[CoversClass(Representation::class)]
#[CoversClass(MimeType::class)]
#[CoversClass(Request::class)]
final class NegotiationTest extends TestCase
{
    /**
     * @param string $header
     * @param Representation|null $expected
     * @return void
     */
    #[DataProvider('preferenceProvider')]
    public function testTheBestRepresentationOnOfferIsChosen(string $header, ?Representation $expected): void
    {
        $chosen = AcceptedTypes::from($header)->preferred(Representation::Html, Representation::Json);

        self::assertSame($expected, $chosen);
    }

    /**
     * @return iterable<string, array{string, ?Representation}>
     */
    public static function preferenceProvider(): iterable
    {
        yield 'no header'                            => ['', Representation::Html];
        yield 'anything'                             => ['*/*', Representation::Html];
        yield 'a browser'                            => [
            'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
            Representation::Html,
        ];
        yield 'data'                                 => ['application/json', Representation::Json];
        yield 'data, loosely'                        => ['application/*', Representation::Json];
        yield 'text, loosely'                        => ['text/*', Representation::Html];
        yield 'data weighed over a page'             => [
            'application/json;q=0.5, text/html;q=0.4',
            Representation::Json,
        ];
        yield 'a tie goes to the default'            => ['application/json, text/html', Representation::Html];
        yield 'the most specific range decides'      => ['*/*;q=0.1, application/json', Representation::Json];
        yield 'a page refused'                       => ['text/html;q=0, */*', Representation::Json];
        yield 'plain text alone'                     => ['text/plain', null];
        yield 'everything refused'                   => ['*/*;q=0', null];
        yield 'nothing readable'                     => ['not a range, */html', Representation::Html];
        yield 'an unreadable weight drops its entry' => ['application/json;q=high', Representation::Html];
        yield 'case and spaces'                      => [' Application/JSON ; Q=1 ', Representation::Json];
    }

    /**
     * @param string $entry
     * @param array{string, string, float}|null $expected Type, subtype and weight, or null for no range.
     * @return void
     */
    #[DataProvider('rangeProvider')]
    public function testARangeIsReadOrRefused(string $entry, ?array $expected): void
    {
        $range = MediaRange::parse($entry);

        self::assertSame($expected, $range === null ? null : [$range->type, $range->subtype, $range->quality]);
    }

    /**
     * @return iterable<string, array{string, array{string, string, float}|null}>
     */
    public static function rangeProvider(): iterable
    {
        yield 'a type'                          => ['text/html', ['text', 'html', 1.0]];
        yield 'a weight'                        => ['text/html;q=0.5', ['text', 'html', 0.5]];
        yield 'other parameters are read past'  => ['text/html;level=1;q=0.25', ['text', 'html', 0.25]];
        yield 'every type'                      => ['*/*', ['*', '*', 1.0]];
        yield 'every subtype'                   => ['text/*', ['text', '*', 1.0]];
        yield 'no slash'                        => ['html', null];
        yield 'a wildcard type with a subtype'  => ['*/html', null];
        yield 'two slashes'                     => ['a/b/c', null];
        yield 'empty'                           => ['', null];
        yield 'a weight over one'               => ['text/html;q=2', null];
        yield 'too many decimals'               => ['text/html;q=0.1234', null];
        yield 'a newline inside'                => ["text/ht\nml", null];
        yield 'a weight with a trailing newline' => ["text/html;q=1\n", null];
    }

    /**
     * How exactly a range names a representation: the type and subtype, the type alone, anything —
     * or not at all.
     *
     * @return void
     */
    public function testTheMoreExactRangeIsTheMoreSpecific(): void
    {
        self::assertSame(2, MediaRange::parse('text/html')?->specificity(Representation::Html));
        self::assertSame(1, MediaRange::parse('text/*')?->specificity(Representation::Html));
        self::assertSame(0, MediaRange::parse('*/*')?->specificity(Representation::Html));
        self::assertNull(MediaRange::parse('text/*')?->specificity(Representation::Json));
    }

    /**
     * Each representation is sent as its own type, a text type with its charset.
     *
     * @return void
     */
    public function testEachRepresentationIsSentAsItsType(): void
    {
        self::assertSame('text/html; charset=utf-8', Representation::Html->mimeType()->render());
        self::assertSame('application/json', Representation::Json->mimeType()->render());
    }

    /**
     * A request reads its own `Accept`, and one that sent none asked for nothing in particular.
     *
     * @return void
     */
    public function testARequestReadsItsOwnAccept(): void
    {
        $asked = TestRequest::get('/')->with(RequestHeader::Accept, 'application/json')->request();
        $plain = TestRequest::get('/')->request();

        $offered = [Representation::Html, Representation::Json];

        self::assertSame(Representation::Json, $asked->accepted()->preferred(...$offered));
        self::assertSame(Representation::Html, $plain->accepted()->preferred(...$offered));
    }
}
