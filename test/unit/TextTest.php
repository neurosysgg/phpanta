<?php

declare(strict_types=1);

namespace Phpanta\Test\Unit;

use MessageFormatter;
use Phpanta\App;
use Phpanta\Exception\TranslationException;
use Phpanta\Test\SourceTree;
use Phpanta\Text\FrameworkText;
use Phpanta\Text\Joined;
use Phpanta\Text\Language;
use Phpanta\Text\Languages;
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
#[CoversClass(Language::class)]
#[CoversClass(Languages::class)]
#[CoversClass(Phrase::class)]
#[CoversClass(Verbatim::class)]
#[CoversClass(Joined::class)]
#[CoversTrait(Translated::class)]
final class TextTest extends TestCase
{
    /**
     * @return void
     */
    public function testATranslationAnswersInEachLanguageAndFallsBackToTheAppsDefault(): void
    {
        $both    = new Translation(en: 'downloads', de: 'Downloads');
        $english = new Translation(en: 'downloads');

        self::assertSame('downloads', $both->in(Language::English));
        self::assertSame('Downloads', $both->in(Language::German));
        self::assertSame('downloads', $english->in(Language::German), 'German falls back to the default');

        self::assertTrue($both->has(Language::German));
        self::assertFalse($english->has(Language::German), 'a fallback is not a translation');
        self::assertTrue($english->has(Language::English));
    }

    /**
     * Any language is enough, and a language nobody wrote falls back along the app's languages in
     * order — the default first — and only then to whichever text was written first.
     *
     * @return void
     */
    public function testAMissingTextFallsBackAlongTheOfferedLanguagesThenToTheFirstWritten(): void
    {
        $translation = new Translation(de: 'Downloads', fr: 'téléchargements', nl: 'downloads');

        self::assertSame('téléchargements', $translation->in(Language::French));
        self::assertSame('downloads', $translation->in(Language::Dutch));
        self::assertFalse($translation->has(Language::English));
        self::assertSame('Downloads', $translation->in(Language::English), 'TestApp offers German second');

        self::assertSame(
            'téléchargements',
            $translation->fallback(new Languages(Language::Italian, Language::French, Language::German)),
            'the first offered language that has a text, not the first in the enum',
        );
        self::assertSame(
            'Downloads',
            $translation->fallback(new Languages(Language::Spanish)),
            'none offered has one: the first written',
        );
    }

    /**
     * A translation with nothing in it has nothing to fall back to.
     *
     * @return void
     */
    public function testATranslationWithNoTextIsRefused(): void
    {
        $this->expectException(TranslationException::class);
        $this->expectExceptionMessage('at least one language');

        new Translation();
    }

    /**
     * A blank text would pass for a translation and show nothing; leaving the language out is how
     * it falls back.
     *
     * @return void
     */
    public function testABlankTextIsRefused(): void
    {
        $this->expectException(TranslationException::class);
        $this->expectExceptionMessage('The Italian text is blank');

        new Translation(en: 'downloads', it: '  ');
    }

    /**
     * A language switch names each language in itself, lower case.
     *
     * @return void
     */
    public function testEveryLanguageIsNamedInItself(): void
    {
        self::assertSame(
            ['english', 'deutsch', 'français', 'español', 'italiano', 'nederlands'],
            array_map(static fn(Language $language): string => $language->endonym(), Language::cases()),
        );
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
        $languages   = App::current()->languages();

        foreach ($languages->offered()->toValues() as $language) {
            self::assertTrue($translation->has($language), "no {$language->name}: it would fall back");
            self::assertNotNull(
                MessageFormatter::create($language->value, $translation->pattern($language)),
                "not a message ICU can read in {$language->name}",
            );
            self::assertSame(
                self::arguments($translation->pattern($languages->default())),
                self::arguments($translation->pattern($language)),
                "{$language->name} names different arguments from the default",
            );
        }
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
