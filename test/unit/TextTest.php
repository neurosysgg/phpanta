<?php

declare(strict_types=1);

namespace Phpanta\Test\Unit;

use MessageFormatter;
use Phpanta\Exception\TranslationException;
use Phpanta\Test\SourceTree;
use Phpanta\Text\FrameworkText;
use Phpanta\Text\Joined;
use Phpanta\Text\Language;
use Phpanta\Text\Phrase;
use Phpanta\Text\Translatable;
use Phpanta\Text\Translated;
use Phpanta\Text\Translation;
use Phpanta\Text\Verbatim;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversTrait;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use UnitEnum;

/**
 * The text layer: a translation, a catalog case, a phrase with its arguments bound — and the
 * framework's own catalogs, written in every language it offers.
 *
 * How the markup tree decides which language one renders in is {@link MarkupTest}'s to say.
 */
#[CoversClass(Translation::class)]
#[CoversClass(Phrase::class)]
#[CoversClass(Verbatim::class)]
#[CoversClass(Joined::class)]
#[CoversTrait(Translated::class)]
final class TextTest extends TestCase
{
    /**
     * @return void
     */
    public function testATranslationAnswersInEachLanguageAndGermanFallsBackToEnglish(): void
    {
        $both    = new Translation(en: 'downloads', de: 'Downloads');
        $english = new Translation(en: 'downloads');

        self::assertSame('downloads', $both->in(Language::English));
        self::assertSame('Downloads', $both->in(Language::German));
        self::assertSame('downloads', $english->in(Language::German), 'German falls back to English');

        self::assertTrue($both->has(Language::German));
        self::assertFalse($english->has(Language::German), 'a fallback is not a translation');
        self::assertTrue($english->has(Language::English));
    }

    /**
     * English is the language every other falls back to, so a translation without it has nothing to
     * fall back on.
     *
     * @return void
     */
    public function testATranslationWithNoEnglishIsRefused(): void
    {
        $this->expectException(TranslationException::class);

        new Translation(en: '  ', de: 'etwas');
    }

    /**
     * Unbound text is shown as written, so a description with a brace in it cannot fail to render.
     *
     * @return void
     */
    public function testUnboundTextIsLiteralEvenWhereItLooksLikeAMessage(): void
    {
        self::assertSame("it's {not} a message", new Translation("it's {not} a message")->in(Language::German));
    }

    /**
     * A catalog case is its attribute's text, read once and then kept.
     *
     * @return void
     */
    public function testACaseIsItsOwnAttributesText(): void
    {
        self::assertSame('Downloads', TextFixture::Plain->in(Language::German));
        self::assertSame('downloads', TextFixture::Plain->in(Language::English));
        self::assertSame('only in English', TextFixture::EnglishOnly->in(Language::German));
        self::assertSame(TextFixture::Plain->translation(), TextFixture::Plain->translation(), 'read once per case');
    }

    /**
     * @return void
     */
    public function testACaseWithNoTranslationIsLoud(): void
    {
        $this->expectException(TranslationException::class);
        $this->expectExceptionMessage('TextFixture::Untranslated has no #[Translation]');

        TextFixture::Untranslated->in(Language::English);
    }

    /**
     * The plural form and the number both follow the language — which is the whole of what ICU is
     * here for.
     *
     * @return void
     */
    public function testAPhraseFormatsItsArgumentsByTheLanguagesOwnRules(): void
    {
        self::assertSame('1 download', TextFixture::Counted->with(count: 1)->in(Language::English));
        self::assertSame('3 downloads', TextFixture::Counted->with(count: 3)->in(Language::English));
        self::assertSame('1,000 downloads', TextFixture::Counted->with(count: 1000)->in(Language::English));
        self::assertSame('1.000 Downloads', TextFixture::Counted->with(count: 1000)->in(Language::German));
    }

    /**
     * @return void
     */
    public function testAPhraseIcuCannotFormatIsLoud(): void
    {
        $this->expectException(TranslationException::class);
        $this->expectExceptionMessage('is not a message ICU can format in English');

        new Phrase(new Translation('{count, plural,'), ['count' => 1])->in(Language::English);
    }

    /**
     * A name is the same in every language, and says so rather than posing as a translation — which
     * is also why it takes the empty string a translation refuses.
     *
     * @return void
     */
    public function testVerbatimTextIsTheSameInEveryLanguage(): void
    {
        self::assertSame('note', new Verbatim('note')->in(Language::German));
        self::assertSame('', new Verbatim('')->in(Language::English));
    }

    /**
     * Each part is put into the language before the parts are joined, which is what lets a
     * translated section and a name share one title.
     *
     * @return void
     */
    public function testJoinedTextPutsEachPartIntoTheLanguageFirst(): void
    {
        $title = new Joined(' — ', TextFixture::Plain, new Verbatim('Example'));

        self::assertSame('downloads — Example', $title->in(Language::English));
        self::assertSame('Downloads — Example', $title->in(Language::German));
    }

    // ─────────────────────────── the framework's own words ───────────────────────────

    /**
     * Every case of every catalog under the framework's `src/`.
     *
     * Found by walking the tree rather than listed, so a catalog nobody added here is one this
     * checks anyway. A site's suite checks its own catalogs the same way, through its own index.
     *
     * @return iterable<string, array{UnitEnum&Translatable}>
     */
    public static function frameworkCaseProvider(): iterable
    {
        foreach (self::frameworkCatalogs() as $catalog) {
            foreach ($catalog::cases() as $case) {
                yield $case::class . '::' . $case->name => [$case];
            }
        }
    }

    /**
     * Every word the framework says is written in every language it offers, as a message ICU can
     * read, naming the same arguments in each — so a word the framework never translated is this
     * test failing, not an English word on some site's German page.
     *
     * @param UnitEnum&Translatable $case
     * @return void
     */
    #[DataProvider('frameworkCaseProvider')]
    public function testEveryFrameworkCaseIsWrittenInEveryLanguageAsAMessageIcuCanRead(
        UnitEnum&Translatable $case,
    ): void {
        $translation = $case->translation();

        self::assertTrue($translation->has(Language::German), 'no German: it would fall back to English');

        foreach (Language::cases() as $language) {
            self::assertNotNull(
                MessageFormatter::create($language->value, $translation->pattern($language)),
                "not a message ICU can read in {$language->name}",
            );
        }

        self::assertSame(
            self::arguments($translation->pattern(Language::English)),
            self::arguments($translation->pattern(Language::German)),
            'the two languages name different arguments',
        );
    }

    /**
     * The walk above finds something, so an empty provider cannot pass for a clean one.
     *
     * @return void
     */
    public function testTheFrameworkHasCatalogsToCheck(): void
    {
        self::assertContains(FrameworkText::class, self::frameworkCatalogs());
    }

    /**
     * The enums under the framework's `src/` that use {@link Translated}, sorted.
     *
     * @return list<class-string<UnitEnum&Translatable>>
     */
    private static function frameworkCatalogs(): array
    {
        $found = array_values(array_filter(
            SourceTree::framework()->classes(),
            static fn(string $class): bool => enum_exists($class)
                && in_array(Translated::class, class_uses($class), true),
        ));

        sort($found);

        return $found;
    }

    /**
     * The argument names a message uses, sorted — `{title}`, and `{count, plural, …}`'s `count`.
     *
     * @param string $pattern
     * @return list<string>
     */
    private static function arguments(string $pattern): array
    {
        preg_match_all('/\{\s*(\w+)\s*[,}]/', $pattern, $matches);

        $names = array_values(array_unique($matches[1]));
        sort($names);

        return $names;
    }
}
