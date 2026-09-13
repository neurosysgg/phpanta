<?php

declare(strict_types=1);

namespace PhpantaSite\Test;

use Dom\Element as DomElement;
use Dom\HTMLDocument;
use Phpanta\Http\HttpStatusCode;
use Phpanta\Http\Request;
use Phpanta\Text\Language;
use Phpanta\Text\Translatable;
use PhpantaSite\Page;
use PhpantaSite\Site;
use PhpantaSite\Text\ArchitectureText;
use PhpantaSite\Text\GettingStartedText;
use PhpantaSite\Text\HomeText;
use PhpantaSite\Text\RulesText;
use PhpantaSite\Text\SiteText;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use UnitEnum;

/**
 * Every page of the site, at every address it is exported at, in every language — rendered the way
 * the export renders them, through the site's own app.
 *
 * What is pinned is what a reader would otherwise find broken with nothing reporting it: a page in
 * the wrong language, a link from the German page to an English one, a switch that leads nowhere,
 * an anchor that moved when a heading was translated, a word left in English on the German page.
 */
final class SitePagesTest extends TestCase
{
    /** An anchor as the pages spell one: lower-case words, joined by hyphens. */
    private const string ID = '/^[a-z0-9]+(?:-[a-z0-9]+)*\z/';

    /** A placeholder in a sentence, as `Sentence` reads one. */
    private const string PLACEHOLDER = '/\{([a-z][A-Za-z0-9]*)\}/';

    /** Every catalog the site writes its words in. */
    private const array CATALOGS = [
        SiteText::class,
        HomeText::class,
        GettingStartedText::class,
        RulesText::class,
        ArchitectureText::class,
    ];

    /** Text that is the same in every language on purpose: names, not words. */
    private const array NAMES = [Site::NAME, 'GitHub'];

    /**
     * Every page at every address the export writes it at.
     *
     * @return iterable<string, array{Page, string, Language}>
     */
    public static function addressProvider(): iterable
    {
        $site = Site::current();

        foreach (Page::cases() as $page) {
            foreach ($site->languageAddresses()->exported($page->path()->to(), $site->languages()) as $address) {
                yield $address->address => [$page, $address->address, $address->language];
            }
        }
    }

    /**
     * A page at any of its addresses is a page in that address's language: its `lang`, the two
     * alternates its head states, a switch to each, a shell marked to follow the language, and every
     * on-site link leading to a page in the same language.
     *
     * @param Page $page
     * @param string $address
     * @param Language $language
     * @return void
     */
    #[DataProvider('addressProvider')]
    public function testEveryPageAnswersAtEveryAddressInItsLanguage(
        Page $page,
        string $address,
        Language $language,
    ): void {
        $document = self::render($address, $language);
        $expected = [];

        foreach (Site::current()->languages()->offered() as $offered) {
            $expected[$offered->value] = $page->path()->inLanguage($offered);
        }

        self::assertSame($language->value, $document->documentElement?->getAttribute('lang'));
        self::assertSame($expected, self::byLanguage($document, 'link[rel="alternate"]'), 'the alternates');
        self::assertSame($expected, self::byLanguage($document, '.language-switch a'), 'the switch');
        self::assertCount(2, $document->querySelectorAll('[data-language-bound]'), 'the header and the footer');

        foreach ($document->querySelectorAll('a[href^="/"]:not([hreflang])') as $link) {
            self::assertStringEndsWith(
                '.' . $language->value . '.html',
                (string) $link->getAttribute('href'),
                "$address links out of its language",
            );
        }
    }

    /**
     * Every subheading links to its own anchor, and the anchors are the same in every language — a
     * link to `#five-habits` finds the German heading as well as the English one.
     *
     * @return void
     */
    public function testEverySubheadingLinksToAnAnchorItKeepsInEveryLanguage(): void
    {
        $headings = 0;

        foreach (Page::cases() as $page) {
            $anchors = [];

            foreach (Site::current()->languages()->offered() as $language) {
                $address  = $page->path()->inLanguage($language);
                $document = self::render($address, $language);
                $seen     = [];

                foreach ($document->querySelectorAll('h2, h3') as $heading) {
                    $id    = (string) $heading->getAttribute('id');
                    $label = trim((string) $heading->textContent);
                    $link  = $heading->firstElementChild;

                    self::assertMatchesRegularExpression(self::ID, $id, "$address: \"$label\" has no anchor.");
                    self::assertNotContains($id, $seen, "$address: #$id is on two headings.");
                    self::assertSame(1, $heading->childElementCount, "$address: \"$label\" holds more than its link.");
                    self::assertSame('a', $link?->localName, "$address: \"$label\" is not a link.");
                    self::assertSame("#$id", $link->getAttribute('href'), "$address: \"$label\" links elsewhere.");

                    $seen[] = $id;
                    $headings++;
                }

                $anchors[] = $seen;
            }

            self::assertSame($anchors[0], $anchors[1], "{$page->value}: the anchors differ between languages.");
        }

        self::assertGreaterThan(0, $headings, 'No page has a subheading to check.');
    }

    /**
     * A language the site does not offer names no page: `rules.fr.html` is the 404 it would be.
     *
     * @return void
     */
    public function testALanguageTheSiteDoesNotOfferIsNoAddress(): void
    {
        $answer = Site::current()->handle(Request::synthetic('/rules.fr.html', Language::English));

        self::assertSame(HttpStatusCode::NotFound, $answer->status());
    }

    /**
     * Every word is written in every language the site offers, with the same placeholders in each,
     * and no brace a sentence would refuse.
     *
     * @return void
     */
    public function testEveryWordIsWrittenInEveryLanguage(): void
    {
        foreach (self::CATALOGS as $catalog) {
            foreach ($catalog::cases() as $case) {
                $name     = self::nameOf($case);
                $patterns = [];

                foreach (Site::current()->languages()->offered() as $language) {
                    self::assertTrue($case->translation()->has($language), "$name has no {$language->name}.");

                    $text = $case->in($language);

                    preg_match_all(self::PLACEHOLDER, $text, $found);
                    sort($found[1]);
                    $patterns[] = $found[1];

                    self::assertDoesNotMatchRegularExpression(
                        '/[{}]/',
                        (string) preg_replace(self::PLACEHOLDER, '', $text),
                        "$name has a stray brace in {$language->name}.",
                    );
                }

                self::assertSame($patterns[0], $patterns[1], "$name names different parts in each language.");
            }
        }
    }

    /**
     * Nothing a reader reads is left in one language on the page in the other: every heading,
     * paragraph, list item, navigation link and the footer differ between the two, but for a name.
     *
     * @return void
     */
    public function testNothingOnAPageIsLeftUntranslated(): void
    {
        $readable = 'title, h1, h2, p, li, .site-nav a, .site-footer p';

        foreach (Page::cases() as $page) {
            $english = self::texts(self::inLanguage($page, Language::English), $readable);
            $german  = self::texts(self::inLanguage($page, Language::German), $readable);

            self::assertCount(count($english), $german, "{$page->value}: the languages have different shapes.");

            foreach ($english as $at => $text) {
                if (!in_array($text, self::NAMES, true)) {
                    self::assertNotSame($text, $german[$at], "{$page->value}: \"$text\" is the same in German.");
                }
            }
        }
    }

    /**
     * The page at $address, answered as the export asks for it, parsed.
     *
     * @param string $address
     * @param Language $language
     * @return HTMLDocument
     */
    private static function render(string $address, Language $language): HTMLDocument
    {
        $answer = Site::current()->handle(Request::synthetic($address, $language));

        self::assertSame(HttpStatusCode::Ok, $answer->status(), "$address did not answer with a page.");

        return HTMLDocument::createFromString($answer->body(), LIBXML_NOERROR);
    }

    /**
     * $page at the address that names $language, parsed.
     *
     * @param Page $page
     * @param Language $language
     * @return HTMLDocument
     */
    private static function inLanguage(Page $page, Language $language): HTMLDocument
    {
        return self::render($page->path()->inLanguage($language), $language);
    }

    /**
     * Each element $selector finds, as its `hreflang` → its `href`.
     *
     * @param HTMLDocument $document
     * @param string $selector
     * @return array<string, string>
     */
    private static function byLanguage(HTMLDocument $document, string $selector): array
    {
        $found = [];

        foreach ($document->querySelectorAll($selector) as $element) {
            $found[(string) $element->getAttribute('hreflang')] = (string) $element->getAttribute('href');
        }

        return $found;
    }

    /**
     * The text of each element $selector finds, whitespace collapsed, in document order.
     *
     * @param HTMLDocument $document
     * @param string $selector
     * @return list<string>
     */
    private static function texts(HTMLDocument $document, string $selector): array
    {
        $texts = [];

        /** @var DomElement $element */
        foreach ($document->querySelectorAll($selector) as $element) {
            $texts[] = trim((string) preg_replace('/\s+/', ' ', (string) $element->textContent));
        }

        return $texts;
    }

    /**
     * How a failure names a catalog case.
     *
     * @param Translatable&UnitEnum $case
     * @return string
     */
    private static function nameOf(Translatable&UnitEnum $case): string
    {
        return substr($case::class, (int) strrpos($case::class, '\\') + 1) . '::' . $case->name;
    }
}
