<?php

declare(strict_types=1);

namespace Phpanta\Test\Unit;

use Phpanta\Http\Api\ApiResult;
use Phpanta\Http\HttpStatusCode;
use Phpanta\Model\Health\HealthFact;
use Phpanta\Model\Health\HealthSection;
use Phpanta\Model\Health\Verdict;
use Phpanta\Support\Collection;
use Phpanta\Tool\Api\ResultReader;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The signing CLI's half of an admin answer: data read back into the result it was written from.
 *
 * The claim is a round trip — what the server writes as data, the CLI reads back into a result
 * whose text is the text the server would have written — and that anything else is not a result
 * rather than an error, since an unverified call is answered with a page the command explains.
 *
 * No `#[CoversClass]`, like every other test of `tools/`: `tools/` is not coverage source.
 */
final class ResultReaderTest extends TestCase
{
    /**
     * A result written as data reads back to the same result: the same status, the same text, the
     * same data again.
     *
     * @return void
     */
    public function testAResultReadsBackToTheResultItWasWrittenFrom(): void
    {
        $result = ApiResult::of(
            HttpStatusCode::ServiceUnavailable,
            HealthSection::facts('runtime', new Collection(HealthFact::class)->with(
                new HealthFact('php', '8.5.9', Verdict::Pass),
                new HealthFact('sapi', ''),
            )),
            HealthSection::lines(null, 'applied', '+ public/index.php'),
        );

        $read = ResultReader::read(json_encode($result, JSON_THROW_ON_ERROR));

        self::assertNotNull($read);
        self::assertSame(HttpStatusCode::ServiceUnavailable, $read->status);
        self::assertSame($result->text(), $read->text());
        self::assertSame(json_encode($result), json_encode($read));
    }

    /**
     * @param string $body
     * @return void
     */
    #[DataProvider('notAResultProvider')]
    public function testAnythingElseIsNotAResult(string $body): void
    {
        self::assertNull(ResultReader::read($body));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function notAResultProvider(): iterable
    {
        $fact = static fn(string $fact): string
            => '{"status":200,"sections":[{"caption":null,"facts":[' . $fact . ']}]}';

        yield 'a page'                         => ['<!DOCTYPE html><html></html>'];
        yield 'nothing'                        => [''];
        yield 'a list'                         => ['[]'];
        yield 'no status'                      => ['{"sections":[]}'];
        yield 'a status that is not one'       => ['{"status":999,"sections":[]}'];
        yield 'a status written as text'       => ['{"status":"200","sections":[]}'];
        yield 'no sections'                    => ['{"status":200}'];
        yield 'a section that is not an object' => ['{"status":200,"sections":["x"]}'];
        yield 'a caption that is not text'     => ['{"status":200,"sections":[{"caption":1,"lines":[]}]}'];
        yield 'a line that is not text'        => ['{"status":200,"sections":[{"caption":null,"lines":[1]}]}'];
        yield 'neither facts nor lines'        => ['{"status":200,"sections":[{"caption":null}]}'];
        yield 'a fact that is not an object'   => [$fact('"x"')];
        yield 'a fact with no value'           => [$fact('{"name":"a"}')];
        yield 'a verdict that is not one'      => [$fact('{"name":"a","value":"b","verdict":"meh"}')];
        yield 'a verdict that is not text'     => [$fact('{"name":"a","value":"b","verdict":1}')];
        yield 'nested deeper than any result'  => [str_repeat('[', 20) . str_repeat(']', 20)];
    }
}
