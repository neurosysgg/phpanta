<?php

declare(strict_types=1);

namespace Phpanta\Test\Unit;

use Phpanta\Exception\MimeTypeException;
use Phpanta\Http\MimeType;
use Phpanta\Http\TopLevelType;
use Phpanta\Support\Charset;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A `Content-Type`: the type, the subtype it refuses to be anything but, and the encoding.
 */
#[CoversClass(MimeType::class)]
#[CoversClass(TopLevelType::class)]
#[CoversClass(Charset::class)]
#[CoversClass(MimeTypeException::class)]
final class MimeTypeTest extends TestCase
{
    /**
     * The two every page and every refusal is sent as, pinned to the byte. An end-to-end check
     * over real HTTP can grep for the same strings; this is the same assertion one layer down,
     * where it can say why it failed rather than that a header did not match.
     *
     * @return void
     */
    public function testTheTwoTextTypesRenderExactly(): void
    {
        self::assertSame('text/html; charset=utf-8', MimeType::html()->render());
        self::assertSame('text/plain; charset=utf-8', MimeType::plainText()->render());
    }

    /**
     * The essence is the type without its encoding — what `Navigation` compares a response's
     * `Content-Type` with.
     *
     * @return void
     */
    public function testTheEssenceIsTheTypeWithoutItsCharset(): void
    {
        self::assertSame('text/html', MimeType::html()->essence());
        self::assertSame('audio/mpeg', MimeType::forAudio('mp3')->essence());
    }

    /**
     * The parameter is optional because most types have no encoding to declare.
     *
     * @return void
     */
    public function testANullCharsetRendersTheTypeAlone(): void
    {
        self::assertSame(
            'image/png',
            new MimeType(TopLevelType::Image, 'png', charset: null)->render(),
        );
    }

    /**
     * Every body the framework writes is text, so the parameter is there unless it is refused.
     *
     * @return void
     */
    public function testTheCharsetIsPresentByDefault(): void
    {
        self::assertSame(Charset::Utf8, new MimeType(TopLevelType::Text, 'css')->charset);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function validSubtypeProvider(): iterable
    {
        yield 'plain'         => ['html'];
        yield 'plus suffix'   => ['svg+xml'];
        yield 'vendor tree'   => ['vnd.api+json'];
        yield 'x- prefix'     => ['x-www-form-urlencoded'];
        yield 'digits'        => ['mp4'];
        yield 'leading digit' => ['3gpp'];
        yield 'at the cap'    => [str_repeat('a', 127)];
    }

    /**
     * @param string $subtype
     * @return void
     */
    #[DataProvider('validSubtypeProvider')]
    public function testAcceptsEveryShapeARegisteredSubtypeTakes(string $subtype): void
    {
        self::assertSame($subtype, new MimeType(TopLevelType::Application, $subtype)->subtype);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidSubtypeProvider(): iterable
    {
        yield 'empty'                        => [''];
        yield 'space'                        => ['ht ml'];
        yield 'a whole type'                 => ['text/html'];
        yield 'with parameter'               => ['html; q=1'];
        yield 'leading dash'                 => ['-html'];
        yield 'leading dot'                  => ['.html'];
        yield 'a token char no subtype uses' => ['ht!ml'];
        yield 'past the cap'                 => [str_repeat('a', 128)];
        yield 'newline'                      => ["html\n"];
    }

    /**
     * Mirrors `CspHost`: a bad paste has to fail where it is written, not on the wire.
     *
     * @param string $subtype
     * @return void
     */
    #[DataProvider('invalidSubtypeProvider')]
    public function testRejectsAnythingThatIsNotABareSubtype(string $subtype): void
    {
        $this->expectException(MimeTypeException::class);
        new MimeType(TopLevelType::Text, $subtype);
    }

    /**
     * Both forms, pinned to the literal each of their readers expects: the header parameter, a
     * shell's charset meta tag, and `htmlspecialchars()` in the markup tree.
     *
     * @return void
     */
    public function testTheEncodingHasAHeaderFormAndACanonicalOne(): void
    {
        self::assertSame('utf-8', Charset::Utf8->value);
        self::assertSame('UTF-8', Charset::Utf8->canonical());
    }
}
