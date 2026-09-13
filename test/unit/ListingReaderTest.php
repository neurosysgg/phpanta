<?php

declare(strict_types=1);

namespace Phpanta\Test\Unit;

use Phpanta\Http\Api\ApiListing;
use Phpanta\Http\Api\ApiService;
use Phpanta\Http\Api\ApiVersion;
use Phpanta\Text\Language;
use Phpanta\Tool\Api\ListingReader;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * What `php tools/api.php` prints for less than a whole address: the server's listing, one line
 * each, in the layout every other answer it prints has — and nothing for an answer that is not one.
 *
 * No `#[CoversClass]`, like every other test of `tools/`: `tools/` is not coverage source.
 */
final class ListingReaderTest extends TestCase
{
    /**
     * A version's actions read as a line each: the method, whether it writes, what it is for, what
     * it takes, and whether only the signing commands can run it.
     *
     * @return void
     */
    public function testAListingOfActionsReadsAsALineEach(): void
    {
        $text = ListingReader::text((string) json_encode(
            ApiListing::actions(ApiService::Update, ApiVersion::V1, Language::English),
            JSON_THROW_ON_ERROR,
        ));

        self::assertNotNull($text);
        self::assertStringStartsWith("/admin/update/v1\n", $text);
        self::assertMatchesRegularExpression(
            '/^  patch +POST  writes  Write a pushed tree, and remove what it leaves out\.'
            . '  \(apply, mirror\)  \[CLI only\]$/m',
            $text,
        );
        self::assertMatchesRegularExpression('/^  version +GET   reads   What is deployed: .+\.$/m', $text);
        self::assertMatchesRegularExpression('/^  rollback +POST  writes  .+  \(apply\)$/m', $text);
    }

    /**
     * The entrance reads as the services and what each is for.
     *
     * @return void
     */
    public function testTheEntranceReadsAsItsServices(): void
    {
        $text = ListingReader::text((string) json_encode(ApiListing::services(Language::English), JSON_THROW_ON_ERROR));

        self::assertNotNull($text);
        self::assertStringStartsWith("/admin\n", $text);
        self::assertMatchesRegularExpression('/^  update +Deploying, and asking what is deployed\.$/m', $text);
    }

    /**
     * @param string $body
     * @return void
     */
    #[DataProvider('notAListingProvider')]
    public function testAnythingElseIsNotAListing(string $body): void
    {
        self::assertNull(ListingReader::text($body));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function notAListingProvider(): iterable
    {
        $entry = static fn(string $entry): string => '{"address":"/admin","entries":[' . $entry . ']}';
        $act   = static fn(string $more): string
            => $entry('{"name":"a","href":"/x","description":"d","method":"GET"' . $more . '}');

        yield 'a page'                        => ['<!doctype html>'];
        yield 'nothing'                       => [''];
        yield 'a list'                        => ['[]'];
        yield 'no address'                    => ['{"entries":[]}'];
        yield 'no entries'                    => ['{"address":"/admin"}'];
        yield 'an address that is not text'   => ['{"address":1,"entries":[]}'];
        yield 'an entry that is not an object' => [$entry('"x"')];
        yield 'an entry with no name'         => [$entry('{"href":"/x","description":"d"}')];
        yield 'a description that is not text' => [$entry('{"name":"a","description":1}')];
        yield 'a method that is not text'     => [$entry('{"name":"a","description":"d","method":1}')];
        yield 'an action with nothing else'   => [$act('')];
        yield 'fields that are not a list'    => [$act(',"writes":false,"browser":true,"fields":"apply"')];
        yield 'a field that is not text'      => [$act(',"writes":false,"browser":true,"fields":[1]')];
        yield 'nested deeper than any listing' => [str_repeat('[', 20) . str_repeat(']', 20)];
    }
}
