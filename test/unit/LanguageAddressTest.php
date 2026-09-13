<?php

declare(strict_types=1);

namespace Phpanta\Test\Unit;

use Phpanta\Support\FillsPlaceholders;
use Phpanta\Text\Language;
use Phpanta\Text\LanguageAddress;
use Phpanta\Text\LanguageAddresses;
use Phpanta\Text\Languages;
use Phpanta\Text\LocalisedAddress;
use Phpanta\View\Html\Element;
use Phpanta\View\Html\HtmlAttribute;
use Phpanta\View\Html\HtmlTag;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversTrait;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * An address per language: `/rules.de.html` is `/rules` in German, in an app that says so.
 *
 * Both modes are asserted here directly, whatever the booted app says, because the enum is the one
 * place either is decided. What the booted {@link \Phpanta\Test\TestApp} — which says `Suffixed` —
 * makes of it on a request is {@link RequestTest}'s, and in an export {@link ExportTest}'s.
 */
#[CoversClass(LanguageAddress::class)]
#[CoversClass(LanguageAddresses::class)]
#[CoversClass(LocalisedAddress::class)]
#[CoversTrait(FillsPlaceholders::class)]
final class LanguageAddressTest extends TestCase
{
    /**
     * An address that names an offered language is its page in that language; anything else is no
     * language address at all, and stays the path it was.
     *
     * @param string $address
     * @param string|null $page
     * @param Language|null $language
     * @return void
     */
    #[DataProvider('addressProvider')]
    public function testAnAddressIsReadAsItsPageInItsLanguage(string $address, ?string $page, ?Language $language): void
    {
        $read = LanguageAddresses::Suffixed->read($address, self::languages());

        self::assertSame($page, $read?->page);
        self::assertSame($language, $read?->language);
        self::assertSame($page === null ? null : $address, $read?->address);
    }

    /**
     * @return iterable<string, array{string, string|null, Language|null}>
     */
    public static function addressProvider(): iterable
    {
        yield 'a page'                   => ['/rules.de.html', '/rules', Language::German];
        yield 'the root'                 => ['/index.en.html', '/', Language::English];
        yield 'a nested, dotted page'    => ['/docs/a.b.de.html', '/docs/a.b', Language::German];
        yield 'a page written encoded'   => ['/caf%C3%A9.de.html', '/caf%C3%A9', Language::German];
        yield 'a language not offered'   => ['/rules.fr.html', null, null];
        yield 'a word that is no tag'    => ['/app.min.html', null, null];
        yield 'a tag in capitals'        => ['/rules.DE.html', null, null];
        yield 'no language'              => ['/rules.html', null, null];
        yield 'no page'                  => ['/.de.html', null, null];
        yield 'a plain page'             => ['/rules', null, null];
        yield 'a line break after it'    => ["/rules.de.html\n", null, null];
    }

    /**
     * Shared, the default: no address names a language, every link is the page itself, and a page is
     * exported once, in the default language.
     *
     * @return void
     */
    public function testSharedAddressesAreThePageItself(): void
    {
        self::assertNull(LanguageAddresses::Shared->read('/rules.de.html', self::languages()));
        self::assertSame('/rules', LanguageAddresses::Shared->address('/rules', Language::German));
        self::assertSame(
            [['/rules', '/rules', 'en']],
            self::rows(LanguageAddresses::Shared->exported('/rules', self::languages())),
        );
    }

    /**
     * Suffixed: each language is written at an address of its own, the root under `index`, and
     * every address the export writes reads back as the page and language it was written for.
     *
     * @return void
     */
    public function testSuffixedAddressesNameTheirLanguageAndReadBack(): void
    {
        self::assertSame('/rules.de.html', LanguageAddresses::Suffixed->address('/rules', Language::German));
        self::assertSame('/index.en.html', LanguageAddresses::Suffixed->address('/', Language::English));

        $exported = LanguageAddresses::Suffixed->exported('/rules', self::languages());

        self::assertSame(
            [['/rules', '/rules', 'en'], ['/rules.en.html', '/rules', 'en'], ['/rules.de.html', '/rules', 'de']],
            self::rows($exported),
        );

        foreach ($exported as $address) {
            $read = LanguageAddresses::Suffixed->read($address->address, self::languages());

            self::assertSame(
                $address->address === $address->page ? null : [$address->page, $address->language],
                $read === null ? null : [$read->page, $read->language],
            );
        }
    }

    /**
     * A link written once leads to the page in the language it is rendered in, and a switch names
     * the language it leads to — in the booted app, whose languages have addresses of their own.
     *
     * @return void
     */
    public function testALinkLeadsToThePageInTheLanguageItIsRenderedIn(): void
    {
        $link = new Element(HtmlTag::A)
            ->attr(HtmlAttribute::Href, ExportFixturePath::Guide->inEachLanguage())
            ->containing('guide');

        self::assertSame('<a href="/guide.de.html">guide</a>', $link->render(0, Language::German));
        self::assertSame('<a href="/guide.en.html">guide</a>', $link->render(0, Language::English));
        self::assertSame('/index.de.html', ExportFixturePath::Home->inLanguage(Language::German));
        self::assertSame('/pages/caf%C3%A9.de.html', ExportFixturePath::Page->inLanguage(Language::German, 'café'));
        self::assertSame('/guide.en.html', new LocalisedAddress('/guide')->in(Language::English));
    }

    /**
     * English first, then German — the booted app's two.
     *
     * @return Languages
     */
    private static function languages(): Languages
    {
        return new Languages(Language::English, Language::German);
    }

    /**
     * Each address as its address, page and language tag, in order.
     *
     * @param iterable<LanguageAddress> $addresses
     * @return list<array{string, string, string}>
     */
    private static function rows(iterable $addresses): array
    {
        $rows = [];

        foreach ($addresses as $address) {
            $rows[] = [$address->address, $address->page, $address->language->value];
        }

        return $rows;
    }
}
