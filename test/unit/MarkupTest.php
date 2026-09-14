<?php

declare(strict_types=1);

namespace Phpanta\Test\Unit;

use BackedEnum;
use Phpanta\Exception\ElementException;
use Phpanta\Exception\MarkupException;
use Phpanta\Exception\TranslationException;
use Phpanta\Http\FormEncoding;
use Phpanta\Support\Collection;
use Phpanta\Support\SearchableCollection;
use Phpanta\Support\UrlScheme;
use Phpanta\Test\SourceTree;
use Phpanta\Text\Language;
use Phpanta\View\Html\Attribute;
use Phpanta\View\Html\AttributeName;
use Phpanta\View\Html\AttributeValue;
use Phpanta\View\Html\Autocomplete;
use Phpanta\View\Html\ButtonType;
use Phpanta\View\Html\Doctype;
use Phpanta\View\Html\Document;
use Phpanta\View\Html\Element;
use Phpanta\View\Html\FormMethod;
use Phpanta\View\Html\Fragment;
use Phpanta\View\Html\HtmlAttribute;
use Phpanta\View\Html\HtmlTag;
use Phpanta\View\Html\InputType;
use Phpanta\View\Html\LinkAttribute;
use Phpanta\View\Html\LinkRel;
use Phpanta\View\Html\LinkTarget;
use Phpanta\View\Html\MarkupParser;
use Phpanta\View\Html\MediaPreload;
use Phpanta\View\Html\MetaName;
use Phpanta\View\Html\Node;
use Phpanta\View\Html\PasskeyAttribute;
use Phpanta\View\Html\RegionAttribute;
use Phpanta\View\Html\ScriptType;
use Phpanta\View\Html\Sentence;
use Phpanta\View\Html\TagName;
use Phpanta\View\Html\Text;
use Phpanta\View\Html\TranslatedText;
use Phpanta\View\Html\ViewportContent;
use Phpanta\View\Html\ViewportWidth;
use Phpanta\View\Html\Vocabulary;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TypeError;

/**
 * The markup tree: every page is one of these, so what it can and cannot do is what any site built
 * on the framework can and cannot emit.
 *
 * Written against the standard vocabulary {@link \Phpanta\Test\TestApp} offers and the fixtures
 * beside this file — {@link TagFixture}, {@link AttributeFixture}, {@link ClassFixture},
 * {@link TextFixture} — so nothing here holds only because some site's own enums happen to exist.
 * What a site adds to the vocabulary, and whether its own names keep these rules, is its suite's.
 */
#[CoversClass(Element::class)]
#[CoversClass(Attribute::class)]
#[CoversClass(ViewportContent::class)]
#[CoversClass(UrlScheme::class)]
#[CoversClass(Text::class)]
#[CoversClass(TranslatedText::class)]
#[CoversClass(Sentence::class)]
#[CoversClass(MarkupParser::class)]
#[CoversClass(Vocabulary::class)]
#[CoversClass(Fragment::class)]
#[CoversClass(Document::class)]
#[CoversClass(Doctype::class)]
#[CoversClass(HtmlTag::class)]
#[CoversClass(HtmlAttribute::class)]
#[CoversClass(LinkAttribute::class)]
#[CoversClass(PasskeyAttribute::class)]
#[CoversClass(RegionAttribute::class)]
#[CoversClass(LinkRel::class)]
#[CoversClass(MetaName::class)]
#[CoversClass(MediaPreload::class)]
#[CoversClass(InputType::class)]
#[CoversClass(FormMethod::class)]
#[CoversClass(Autocomplete::class)]
#[CoversClass(ButtonType::class)]
final class MarkupTest extends TestCase
{
    // ───────────────────────────── translated text ─────────────────────────────

    /**
     * A translatable child is put into the language in scope, and escaped like any other text.
     *
     * @return void
     */
    public function testATranslatableChildRendersInTheLanguageInScope(): void
    {
        $paragraph = new Element(HtmlTag::P)->containing(TextFixture::Plain);

        self::assertSame('<p>downloads</p>', $paragraph->render(0, Language::English));
        self::assertSame('<p>Downloads</p>', $paragraph->render(0, Language::German));
        self::assertSame(
            '<p>&lt;b&gt;fett&lt;/b&gt; &amp; „zitiert“</p>',
            new Element(HtmlTag::P)->containing(TextFixture::Markup)->render(0, Language::German),
        );
    }

    /**
     * An element's own `lang` is the language for everything under it. That is how `<html lang>`
     * sets a page's, with no language passed at all, and how a German passage stays German on an
     * English page.
     *
     * @return void
     */
    public function testAnElementsOwnLangIsTheLanguageUnderIt(): void
    {
        $german = new Element(HtmlTag::Section)
            ->attr(HtmlAttribute::Lang, Language::German)
            ->containing(new Element(HtmlTag::P)->containing(TextFixture::Plain));

        self::assertStringContainsString('<p>Downloads</p>', $german->render(0, Language::English));

        $document = new Document(
            new Element(HtmlTag::Html)
                ->attr(HtmlAttribute::Lang, Language::German)
                ->containing(new Element(HtmlTag::Body)->containing(
                    new Fragment(new Element(HtmlTag::P)->containing(TextFixture::Plain)),
                )),
        );

        self::assertStringContainsString('<p>Downloads</p>', $document->render());
    }

    /**
     * A `lang` the app is not written in leaves the language in scope as it was, rather than taking
     * every translation under it away.
     *
     * @return void
     */
    public function testALangThatIsNotOneOfOursKeepsTheLanguageInScope(): void
    {
        $quotation = new Element(HtmlTag::P)->attr(HtmlAttribute::Lang, 'fr')->containing(TextFixture::Plain);

        self::assertSame('<p lang="fr">Downloads</p>', $quotation->render(0, Language::German));
    }

    /**
     * An attribute takes a translatable too, resolved in its own element's language — and a
     * catalog case is never read as the backed enum it also is, which would render its key.
     *
     * @return void
     */
    public function testATranslatableAttributeRendersItsWordsAndNotItsKey(): void
    {
        self::assertSame(
            '<img alt="Downloads">',
            new Element(HtmlTag::Img)->attr(HtmlAttribute::Alt, TextFixture::Plain)->render(0, Language::German),
        );
        self::assertSame(
            '<img alt="1.000 Downloads" lang="de">',
            new Element(HtmlTag::Img)
                ->attr(HtmlAttribute::Alt, TextFixture::Counted->with(count: 1000))
                ->attr(HtmlAttribute::Lang, Language::German)
                ->render(),
        );
    }

    /**
     * A translated child is text, so the element holding it stays on one line.
     *
     * @return void
     */
    public function testATranslatedChildKeepsItsElementOnOneLine(): void
    {
        self::assertSame(
            '<p>downloads<span>!</span></p>',
            new Element(HtmlTag::P)
                ->containing(TextFixture::Plain, new Element(HtmlTag::Span)->containing('!'))
                ->render(0, Language::English),
        );
    }

    /**
     * Nothing said which language: a refusal, never a default — the default would be an English
     * word on a German page, with nothing anywhere to say so.
     *
     * @return void
     */
    public function testATranslatableChildWithNoLanguageInScopeIsLoud(): void
    {
        $this->expectException(TranslationException::class);
        $this->expectExceptionMessage('TextFixture::Plain was rendered with no language in scope');

        new Element(HtmlTag::P)->containing(TextFixture::Plain)->render();
    }

    /**
     * @return void
     */
    public function testATranslatableAttributeWithNoLanguageInScopeIsLoudToo(): void
    {
        $this->expectException(TranslationException::class);
        $this->expectExceptionMessage('Phrase was rendered with no language in scope');

        new Element(HtmlTag::Img)->attr(HtmlAttribute::Alt, TextFixture::Counted->with(count: 1))->render();
    }

    // ───────────────────────────── sentences ─────────────────────────────

    /**
     * A sentence places its parts where each language's text puts them — German puts the code first
     * — escapes the text between them, and keeps its paragraph on one line.
     *
     * @return void
     */
    public function testASentencePlacesItsPartsWhereEachLanguagePutsThem(): void
    {
        $paragraph = new Element(HtmlTag::P)->containing(self::placed());

        self::assertSame(
            '<p>Read <a href="/guide">the &lt;guide&gt;</a> first, then <strong>Collection</strong>'
            . ' &amp; the rest.</p>',
            $paragraph->render(0, Language::English),
        );
        self::assertSame(
            '<p>Lies <strong>Collection</strong>, bevor du <a href="/guide">the &lt;guide&gt;</a>'
            . ' &amp; den Rest liest.</p>',
            $paragraph->render(0, Language::German),
        );
    }

    /**
     * A part that is a fragment is written inline, where a fragment on its own would break its nodes
     * onto lines of their own.
     *
     * @return void
     */
    public function testAFragmentPartIsWrittenInline(): void
    {
        $sentence = new Sentence(
            TextFixture::Placed,
            guide: new Fragment(new Text('a '), new Element(HtmlTag::Em)->containing('guide')),
            code: new Element(HtmlTag::Strong)->containing('x'),
        );

        self::assertSame(
            '<div>Read a <em>guide</em> first, then <strong>x</strong> &amp; the rest.</div>',
            new Element(HtmlTag::Div)->containing($sentence)->render(0, Language::English),
        );
    }

    /**
     * A part handed over by position has no name to be placed by.
     *
     * @return void
     */
    public function testASentencePartGivenByPositionIsRefused(): void
    {
        $this->expectException(ElementException::class);
        $this->expectExceptionMessage('TextFixture::Placed was given one by position');

        (void) new Sentence(TextFixture::Placed, new Text('x'));
    }

    /**
     * A placeholder with no part would render as nothing, and a part no placeholder names would be
     * dropped; both are refused in the language being rendered.
     *
     * @return void
     */
    public function testAPlaceholderWithoutAPartAndAPartWithoutAPlaceholderAreRefused(): void
    {
        $missing = new Sentence(TextFixture::Placed, guide: new Text('the guide'));

        try {
            $missing->render(0, Language::German);
            self::fail('a placeholder with no part rendered');
        } catch (TranslationException $refused) {
            self::assertStringContainsString('names {code} in German', $refused->getMessage());
        }

        $unused = new Sentence(
            TextFixture::Placed,
            guide: new Text('the guide'),
            code: new Text('x'),
            other: new Text('never named'),
        );

        $this->expectException(TranslationException::class);
        $this->expectExceptionMessage('was given the part other, which it never names in English');

        $unused->render(0, Language::English);
    }

    /**
     * A brace that is no placeholder is refused, in either direction and in either language.
     *
     * @param Language $language
     * @param string $stray
     * @return void
     */
    #[DataProvider('strayBraceProvider')]
    public function testABraceThatIsNoPlaceholderIsRefused(Language $language, string $stray): void
    {
        $this->expectException(TranslationException::class);
        $this->expectExceptionMessage(
            sprintf("holds a brace that is no placeholder in %s: '%s'", $language->name, $stray),
        );

        new Sentence(TextFixture::Stray, brace: new Text('x'))->render(0, $language);
    }

    /**
     * @return iterable<string, array{Language, string}>
     */
    public static function strayBraceProvider(): iterable
    {
        yield 'an opening one' => [Language::English, ' and a { stray one'];
        yield 'a closing one'  => [Language::German, ' und eine } lose'];
    }

    /**
     * A sentence with no language in scope refuses, as the text in it would.
     *
     * @return void
     */
    public function testASentenceWithNoLanguageInScopeIsLoud(): void
    {
        $this->expectException(TranslationException::class);
        $this->expectExceptionMessage('TextFixture::Placed was rendered with no language in scope');

        new Element(HtmlTag::P)->containing(self::placed())->render();
    }

    /**
     * The sentence the tests above render: a link whose text needs escaping, and a piece of code.
     *
     * @return Sentence
     */
    private static function placed(): Sentence
    {
        return new Sentence(
            TextFixture::Placed,
            guide: new Element(HtmlTag::A)->attr(HtmlAttribute::Href, '/guide')->containing('the <guide>'),
            code: new Element(HtmlTag::Strong)->containing('Collection'),
        );
    }

    // ───────────────────────────── attributes ─────────────────────────────

    /**
     * @return void
     */
    public function testAnElementRendersItsTagAndAttributes(): void
    {
        self::assertSame(
            '<img src="/a.png" alt="a">',
            new Element(HtmlTag::Img)
                ->attr(HtmlAttribute::Src, '/a.png')
                ->attr(HtmlAttribute::Alt, 'a')
                ->render(),
        );
    }

    /**
     * Any tag enum renders, not only HTML's own: a custom element is its name, opened and closed.
     *
     * @return void
     */
    public function testACustomElementRendersItsTagAndAttributes(): void
    {
        self::assertSame(
            '<x-widget source="/a.png" caption="a"></x-widget>',
            new Element(TagFixture::Widget)
                ->attr(AttributeFixture::Source, '/a.png')
                ->attr(AttributeFixture::Caption, 'a')
                ->render(),
        );
    }

    /**
     * The reason this class exists. A htmlspecialchars() call per attribute at every call site is
     * an injection the first time one is forgotten — so escaping happens here, once, or not at all.
     *
     * @return void
     */
    public function testAnAttributeValueCannotBreakOutOfItsAttribute(): void
    {
        self::assertSame(
            '<img alt="&quot; onload=&quot;alert(1)">',
            new Element(HtmlTag::Img)->attr(HtmlAttribute::Alt, '" onload="alert(1)')->render(),
        );
    }

    /**
     * true is a bare attribute, '' is a real empty value, and the two must not collapse.
     *
     * @return void
     */
    public function testABooleanAttributeIsBareAndAnEmptyValueIsNot(): void
    {
        self::assertSame(
            '<audio title="" controls></audio>',
            new Element(HtmlTag::Audio)
                ->attr(HtmlAttribute::Title, '')
                ->attr(HtmlAttribute::Controls)
                ->render(),
        );
    }

    /**
     * @return void
     */
    public function testFalseAndNullBothLeaveTheAttributeOffEntirely(): void
    {
        self::assertSame(
            '<audio></audio>',
            new Element(HtmlTag::Audio)
                ->attr(HtmlAttribute::Controls, false)
                ->attr(HtmlAttribute::Title, null)
                ->render(),
        );
    }

    /**
     * @return void
     */
    public function testAnIntegerValueRendersAsItsDigits(): void
    {
        self::assertSame(
            '<img height="56">',
            new Element(HtmlTag::Img)->attr(HtmlAttribute::Height, 56)->render(),
        );
    }

    /**
     * @return void
     */
    public function testTheSameAttributeTwiceKeepsTheLastValueAndItsPosition(): void
    {
        self::assertSame(
            '<img src="/b.png" alt="a">',
            new Element(HtmlTag::Img)
                ->attr(HtmlAttribute::Src, '/a.png')
                ->attr(HtmlAttribute::Alt, 'a')
                ->attr(HtmlAttribute::Src, '/b.png')
                ->render(),
        );
    }

    /**
     * Immutable like the policies and the collections — every builder method returns a copy.
     *
     * @return void
     */
    public function testBuildingDoesNotMutateTheElementBuiltFrom(): void
    {
        $empty = new Element(HtmlTag::Section);
        (void) $empty->attr(HtmlAttribute::Title, 'x');
        (void) $empty->containing('x');

        self::assertSame('<section></section>', $empty->render());
    }

    /**
     * A backed enum stands for its value, so a call site passes a case rather than remembering
     * ->value — one fewer thing to get right at twenty call sites.
     *
     * @return void
     */
    public function testABackedEnumValueRendersAsItsBackingValue(): void
    {
        self::assertSame(
            '<p class="accent"></p>',
            new Element(HtmlTag::P)->attr(HtmlAttribute::ClassName, ClassFixture::Accent)->render(),
        );
    }

    /**
     * An enum value is escaped on the same path a string is; nothing gets in around it.
     *
     * @return void
     */
    public function testABackedEnumValueGoesThroughTheSameEscaping(): void
    {
        $enum = new Element(HtmlTag::P)->attr(HtmlAttribute::ClassName, ClassFixture::Accent)->render();
        $text = new Element(HtmlTag::P)->attr(HtmlAttribute::ClassName, 'accent')->render();

        self::assertSame($text, $enum);
    }

    // ───────────────────────── the names themselves ─────────────────────────

    /**
     * Every attribute name is a case rather than a string typed out at each call site, and
     * {@link AttributeName} is what lets {@link Element} take any of them without knowing which
     * element it is building. A name that renders as something other than its backing value would
     * be a silent null on the client, so assert the two are the same thing.
     *
     * @param AttributeName&BackedEnum $name
     * @return void
     */
    #[DataProvider('attributeNameProvider')]
    public function testAnAttributeNameRendersAsItsBackingValue(AttributeName&BackedEnum $name): void
    {
        // A URL attribute is scheme-checked on the way out, so it gets a value that is one. The
        // name is what this test is about either way; that the check fires is asserted below.
        $value = $name->isUrl() ? '/x' : 'x';

        self::assertSame($name->value, $name->attribute());
        self::assertStringContainsString(
            $name->attribute() . '="' . $value . '"',
            new Element(HtmlTag::P)->attr($name, $value)->render(),
        );
    }

    /**
     * Every attribute enum the framework ships.
     *
     * @return iterable<string, array{AttributeName&BackedEnum}>
     */
    public static function attributeNameProvider(): iterable
    {
        $enums = [LinkAttribute::class, HtmlAttribute::class, PasskeyAttribute::class, RegionAttribute::class];

        foreach ($enums as $enum) {
            foreach ($enum::cases() as $case) {
                yield $enum . '::' . $case->name => [$case];
            }
        }
    }

    /**
     * `rel` is the one attribute value that is a set rather than a single fact, so the enum builds
     * the list and the call site does not. Pinned in the order the markup reads.
     *
     * @return void
     */
    public function testLinkRelationsJoinIntoOneAttributeValue(): void
    {
        self::assertSame(
            'noopener noreferrer external',
            LinkRel::tokens(LinkRel::NoOpener, LinkRel::NoReferrer, LinkRel::External),
        );
        self::assertSame('stylesheet', LinkRel::tokens(LinkRel::Stylesheet));
        self::assertSame('', LinkRel::tokens());
    }

    /**
     * A value enum reaches the markup as its backing value with nothing in between —
     * {@link Element::attr()} unwraps any BackedEnum, which is what lets these be passed as cases
     * rather than as `->value` at every call site.
     *
     * @param AttributeName $attribute
     * @param BackedEnum    $value
     * @return void
     */
    #[DataProvider('attributeValueProvider')]
    public function testAnAttributeValueRendersAsItsBackingValue(
        AttributeName $attribute,
        BackedEnum $value,
    ): void {
        self::assertSame(
            '<a ' . $attribute->attribute() . '="' . $value->value . '"></a>',
            new Element(HtmlTag::A)->attr($attribute, $value)->render(),
        );
    }

    /** @return iterable<string, array{AttributeName, BackedEnum}> */
    public static function attributeValueProvider(): iterable
    {
        foreach (LinkRel::cases() as $case) {
            yield 'LinkRel::' . $case->name => [HtmlAttribute::Rel, $case];
        }
        foreach (LinkTarget::cases() as $case) {
            yield 'LinkTarget::' . $case->name => [HtmlAttribute::Target, $case];
        }
        foreach (ScriptType::cases() as $case) {
            yield 'ScriptType::' . $case->name => [HtmlAttribute::Type, $case];
        }
        foreach (MetaName::cases() as $case) {
            yield 'MetaName::' . $case->name => [HtmlAttribute::Name, $case];
        }
        foreach (MediaPreload::cases() as $case) {
            yield 'MediaPreload::' . $case->name => [HtmlAttribute::Preload, $case];
        }
        foreach (InputType::cases() as $case) {
            yield 'InputType::' . $case->name => [HtmlAttribute::Type, $case];
        }
        foreach (ButtonType::cases() as $case) {
            yield 'ButtonType::' . $case->name => [HtmlAttribute::Type, $case];
        }
        foreach (FormMethod::cases() as $case) {
            yield 'FormMethod::' . $case->name => [HtmlAttribute::Method, $case];
        }
        foreach (FormEncoding::cases() as $case) {
            yield 'FormEncoding::' . $case->name => [HtmlAttribute::Enctype, $case];
        }
        foreach (Autocomplete::cases() as $case) {
            yield 'Autocomplete::' . $case->name => [HtmlAttribute::Autocomplete, $case];
        }
    }

    // ───────────────────────────── content ─────────────────────────────

    /**
     * @return void
     */
    public function testTextIsEscaped(): void
    {
        self::assertSame('rock &amp; &lt;roll&gt;', new Text('rock & <roll>')->render());
    }

    /**
     * The safe reading of the ambiguous case: a string child is content, never markup. Markup
     * passed as a string shows up as visible &lt;b&gt; — wrong on the page, but visibly wrong,
     * which is the failure mode to prefer.
     *
     * @return void
     */
    public function testAStringChildIsEscapedTextRatherThanMarkup(): void
    {
        self::assertSame(
            '<p>&lt;b&gt;bold&lt;/b&gt;</p>',
            new Element(HtmlTag::P)->containing('<b>bold</b>')->render(),
        );
    }

    /**
     * @return void
     */
    public function testAVoidElementHasNoClosingTag(): void
    {
        self::assertSame(
            '<meta charset="UTF-8">',
            new Element(HtmlTag::Meta)->attr(HtmlAttribute::Charset, 'UTF-8')->render(),
        );
    }

    /**
     * <img>text</img> is not markup the browser fixes — it is markup it reinterprets.
     *
     * @return void
     */
    public function testAVoidElementRefusesChildren(): void
    {
        $this->expectException(MarkupException::class);
        $this->expectExceptionMessageIsOrContains('<img>');

        (void) new Element(HtmlTag::Img)->containing('x');
    }

    /**
     * The children are a `Collection<Node>`, so the constructor is checked and not merely annotated.
     *
     * `containing()` is a variadic and PHP guards it; the constructor takes a collection for the
     * same reason its attributes do. A plain `array` whose `list<Node>` lived in a docblock would
     * let a string in, and that is not a TypeError naming the element — it is a fatal in
     * `renderChildren()` calling `render()` on a string, at whatever depth of the tree it sits.
     *
     * @return void
     */
    public function testTheChildrenAreTypeCheckedAndNotJustDocumented(): void
    {
        $this->expectException(TypeError::class);
        $this->expectExceptionMessageIsOrContains(Node::class);

        (void) new Collection(Node::class)->with('<b>not a node</b>');
    }

    /**
     * The element carries whatever children it is handed, and renders them in order.
     *
     * @return void
     */
    public function testTheConstructorTakesAChildCollection(): void
    {
        $children = new Collection(Node::class)->with(new Text('a'), new Element(HtmlTag::Br));

        self::assertSame(
            '<p>a<br></p>',
            new Element(HtmlTag::P, null, $children)->render(),
        );
    }

    // ───────────────────────────── layout ─────────────────────────────

    /**
     * Whitespace between inline content is content, so an element with any text in it stays on one
     * line. Breaking this would put a space inside a word — between a name and the mark after it.
     *
     * @return void
     */
    public function testAnElementWithTextInItStaysOnOneLine(): void
    {
        self::assertSame(
            '<h1>name<span class="accent">.</span></h1>',
            new Element(HtmlTag::H1)->containing(
                'name',
                new Element(HtmlTag::Span)->attr(HtmlAttribute::ClassName, 'accent')->containing('.'),
            )->render(),
        );
    }

    /**
     * @return void
     */
    public function testAnElementOfOnlyElementsPutsEachOnItsOwnLine(): void
    {
        self::assertSame(
            "<section>\n  <p>a</p>\n  <p>b</p>\n</section>",
            new Element(HtmlTag::Section)->containing(
                new Element(HtmlTag::P)->containing('a'),
                new Element(HtmlTag::P)->containing('b'),
            )->render(),
        );
    }

    /**
     * Every node is handed its depth and indents its own continuation lines, at any nesting.
     *
     * @return void
     */
    public function testNestingIndentsAllTheWayDown(): void
    {
        self::assertSame(
            "<section>\n  <nav>\n    <p>a</p>\n  </nav>\n</section>",
            new Element(HtmlTag::Section)->containing(
                new Element(HtmlTag::Nav)->containing(new Element(HtmlTag::P)->containing('a')),
            )->render(),
        );
    }

    /**
     * @return void
     */
    public function testAnEmptyElementIsOpenedAndClosedOnOneLine(): void
    {
        self::assertSame('<section></section>', new Element(HtmlTag::Section)->render());
    }

    // ───────────────────────────── fragments and documents ─────────────────────────────

    /**
     * @return void
     */
    public function testAFragmentRendersItsNodesWithNoWrapper(): void
    {
        self::assertSame(
            "<p>a</p>\n<p>b</p>",
            new Fragment(
                new Element(HtmlTag::P)->containing('a'),
                new Element(HtmlTag::P)->containing('b'),
            )->render(),
        );
    }

    /**
     * @return void
     */
    public function testAFragmentInsideAnElementIndentsWithIt(): void
    {
        self::assertSame(
            "<section>\n  <p>a</p>\n  <p>b</p>\n</section>",
            new Element(HtmlTag::Section)->containing(
                new Fragment(
                    new Element(HtmlTag::P)->containing('a'),
                    new Element(HtmlTag::P)->containing('b'),
                ),
            )->render(),
        );
    }

    /**
     * @return void
     */
    public function testFragmentEachMapsItemsToNodes(): void
    {
        self::assertSame(
            "<p>a</p>\n<p>b</p>",
            Fragment::each(
                ['a', 'b'],
                static fn(string $t): Element => new Element(HtmlTag::P)->containing($t),
            )->render(),
        );
    }

    /**
     * A fragment holding text is inline content, so it keeps to one line: a newline between its
     * nodes would be a space on the page.
     *
     * @return void
     */
    public function testAFragmentHoldingTextStaysOnOneLine(): void
    {
        self::assertSame(
            'a<span>b</span>',
            new Fragment(new Text('a'), new Element(HtmlTag::Span)->containing('b'))->render(),
        );
    }

    /**
     * And it puts the element around it on one line too, the way a text child does.
     *
     * @return void
     */
    public function testAFragmentHoldingTextPutsItsElementOnOneLine(): void
    {
        self::assertSame(
            '<p>a<span>b</span></p>',
            new Element(HtmlTag::P)->containing(
                new Fragment(new Text('a'), new Element(HtmlTag::Span)->containing('b')),
            )->render(),
        );
    }

    /**
     * A fragment of elements among inline content is rendered on its parent's line, however deep it
     * nests. It broke its nodes onto lines of their own, and each newline was a space between two
     * links on the page.
     *
     * @return void
     */
    public function testAFragmentAmongInlineContentAddsNoWhitespace(): void
    {
        $spans = new Fragment(
            new Element(HtmlTag::Span)->containing('a'),
            new Fragment(new Element(HtmlTag::Span)->containing('b')),
        );

        self::assertSame(
            '<p>see <span>a</span><span>b</span></p>',
            new Element(HtmlTag::P)->containing('see ', $spans)->render(),
        );
    }

    // ───────────────────────────── shape ─────────────────────────────

    /**
     * An attribute is written under its own name, so one stored under another key is refused — a
     * map built that way is an element that would write one attribute twice.
     *
     * @return void
     */
    public function testAnAttributeKeyedByAnotherNameIsRefused(): void
    {
        $attributes = new SearchableCollection(Attribute::class)
            ->with('title', new Attribute(HtmlAttribute::Lang, 'en'))
            ->with('lang', new Attribute(HtmlAttribute::Lang, 'de'));

        $this->expectException(MarkupException::class);
        $this->expectExceptionMessageIsOrContains('keyed by its own name');

        new Element(HtmlTag::P, $attributes)->render();
    }

    /**
     * containing() refuses a void element's children; the constructor takes them outright, and
     * render() is where the refusal has to hold for an element however it was built.
     *
     * @return void
     */
    public function testAVoidElementBuiltWithChildrenIsRefusedWhenItRenders(): void
    {
        $element = new Element(HtmlTag::Meta, null, new Collection(Node::class)->with(new Text('x')));

        $this->expectException(MarkupException::class);
        $this->expectExceptionMessageIsOrContains('void element');

        $element->render();
    }

    /**
     * A path stays on this origin only when it names no host — including the base's own, which a
     * parse lands on exactly where it started and which is still a protocol-relative URL.
     *
     * @param string $value
     * @param bool   $expected
     * @return void
     */
    #[DataProvider('ownHostProvider')]
    public function testAPathStaysOnThisOriginOnlyWhenItNamesNoHost(string $value, bool $expected): void
    {
        self::assertSame($expected, Element::staysOnThisOrigin($value));
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function ownHostProvider(): iterable
    {
        yield 'a path'                       => ['/posts/first', true];
        yield 'an empty segment inside it'   => ['/a//b', true];
        yield 'another host'                 => ['//evil.example/x', false];
        yield 'a backslash for a slash'      => ['/\\evil.example/x', false];
        yield 'a newline between the two'    => ["/\r\n/evil.example", false];
        yield "the base's own host"          => ['//relative.invalid/x', false];
        yield 'the same, with a backslash'   => ['/\\relative.invalid/x', false];
        yield 'the same, backslash first'    => ['\\/relative.invalid/x', false];
        yield 'the same, a tab between'      => ["/\t/relative.invalid/x", false];
        yield 'the same, after a space'      => [' //relative.invalid/x', false];
        yield 'the same, after a control'    => ["\x01//relative.invalid/x", false];
    }

    /**
     * A document's own elements are refused wherever they appear. At the top of a fragment the
     * parser hoists a title into the head, which was always checked; inside content it leaves one
     * where it found it, and a view would render document metadata into the middle of a page.
     *
     * @param string $html
     * @return void
     */
    #[DataProvider('insideContentProvider')]
    public function testADocumentsOwnElementsAreRefusedInsideContent(string $html): void
    {
        $this->expectException(MarkupException::class);
        $this->expectExceptionMessageIsOrContains('belongs to the document');

        (void) MarkupParser::parse($html);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function insideContentProvider(): iterable
    {
        yield 'a title' => ['<div><title>x</title></div>'];
        yield 'a meta'  => ['<div><meta></div>'];
        yield 'a link'  => ['<p><link></p>'];
    }

    /**
     * @return void
     */
    public function testADocumentLeadsWithTheDoctype(): void
    {
        self::assertSame(
            "<!DOCTYPE html>\n<html></html>",
            new Document(new Element(HtmlTag::Html))->render(),
        );
    }

    /**
     * Quirks mode is what a wrong one buys, silently, on every layout calculation on the page.
     *
     * @return void
     */
    public function testTheDoctypeIsHtml5AndThereIsOnlyOne(): void
    {
        self::assertSame([Doctype::Html5], Doctype::cases());
        self::assertSame('<!DOCTYPE html>', Doctype::Html5->render());
    }

    // ─────────────────── an attribute value with parts of its own ───────────────────

    /**
     * A value with a grammar renders itself, and `attr()` takes it like any other.
     *
     * @return void
     */
    public function testAnAttributeValueRendersIntoTheAttribute(): void
    {
        self::assertSame(
            '<meta content="width=device-width, initial-scale=1.0">',
            new Element(HtmlTag::Meta)
                ->attr(HtmlAttribute::Content, new ViewportContent())
                ->render(),
        );
    }

    /**
     * The value is escaped on the way out like any other, rather than trusted for having a type.
     *
     * {@link ViewportContent} cannot produce anything needing it — its two parts are an enum case
     * and a number — so this builds the interface's worst case directly. That is the point of
     * enforcing in `render()` rather than in the builders: the guarantee holds for whatever an
     * implementation returns, not only for the one the framework ships.
     *
     * @return void
     */
    public function testAnAttributeValueIsEscapedLikeAnyOther(): void
    {
        $hostile = new class () implements AttributeValue {
            /**
             * @return string
             */
            public function render(): string
            {
                return '" onload="alert(1)';
            }
        };

        self::assertSame(
            '<meta content="&quot; onload=&quot;alert(1)">',
            new Element(HtmlTag::Meta)->attr(HtmlAttribute::Content, $hostile)->render(),
        );
    }

    /**
     * `1.0`, not `1`.
     *
     * `(string) 1.0` is `'1'` in PHP, which is a legal viewport scale and would quietly change
     * bytes a page has always sent. A whole number keeps one decimal place and anything finer keeps
     * the digits it has, so no scale is rounded to fit a format.
     *
     * @param float  $scale
     * @param string $expected
     * @return void
     */
    #[DataProvider('viewportScaleProvider')]
    public function testTheViewportScaleKeepsItsDecimal(float $scale, string $expected): void
    {
        self::assertSame(
            'width=device-width, initial-scale=' . $expected,
            new ViewportContent(initialScale: $scale)->render(),
        );
    }

    /**
     * @return iterable<string, array{float, string}>
     */
    public static function viewportScaleProvider(): iterable
    {
        yield 'life size'    => [1.0, '1.0'];
        yield 'half'         => [0.5, '0.5'];
        yield 'two decimals' => [1.25, '1.25'];
        yield 'double'       => [2.0, '2.0'];
        yield 'three'        => [3.0, '3.0'];
    }

    /**
     * The scale carries no separator of its own.
     *
     * The separator in this grammar is a comma, so a decimal comma would turn one descriptor list
     * into two malformed ones — and `%f` writes exactly that under a German locale. `%F` is what
     * keeps the number out of whatever locale the machine happens to be in.
     *
     * Asserted as a comma count rather than by setting a locale, deliberately: `setlocale` needs
     * that locale to be installed, so the version that switches to `de_DE` skips on every machine
     * that has not got one — including, most likely, the machine where this would actually break.
     * Counting holds everywhere and fails there.
     *
     * @return void
     */
    public function testTheViewportScaleCarriesNoSeparatorOfItsOwn(): void
    {
        self::assertSame(1, substr_count(new ViewportContent(initialScale: 1.0)->render(), ','));
        self::assertSame(1, substr_count(new ViewportContent(initialScale: 1.25)->render(), ','));
    }

    /**
     * @return void
     */
    public function testTheViewportWidthIsTheOnlyOneOffered(): void
    {
        self::assertSame('device-width', ViewportWidth::Device->value);
        self::assertSame([ViewportWidth::Device], ViewportWidth::cases());
    }

    // ─────────────────────────────── url schemes ───────────────────────────────

    /**
     * The scheme keeps its colon, which is what separates it from a host.
     *
     * @return void
     */
    public function testASchemeBuildsAnAddressWithItsColon(): void
    {
        self::assertSame('mailto:a@b.test', UrlScheme::Mailto->url('a@b.test'));
        self::assertSame('https://example.test', UrlScheme::Https->url('//example.test'));
    }

    /**
     * Both cases are addresses {@link Element} will actually emit, which is the whole point of the
     * enum being narrower than the set of schemes that exist.
     *
     * @return void
     */
    public function testEverySchemeCaseIsOneAnElementWillEmit(): void
    {
        foreach (UrlScheme::cases() as $scheme) {
            $href = $scheme === UrlScheme::Mailto
                ? $scheme->url('a@b.test')
                : $scheme->url('//example.test');

            self::assertStringContainsString(
                $href,
                new Element(HtmlTag::A)->attr(HtmlAttribute::Href, $href)->render(),
            );
        }
    }

    // ─────────────────────── urls, which escaping cannot help with ───────────────────────

    /**
     * The mistake escaping cannot catch, and the reason {@link AttributeName::isUrl()} exists.
     *
     * htmlspecialchars() does its job perfectly on `javascript:alert(document.cookie)` — there is
     * not a single character in it to escape — and the browser then runs it. Whether a URL is safe
     * is a question about its *scheme*, so that is asked separately, and asked at render, where
     * every element passes through however it was built.
     *
     * @param string $url
     * @return void
     */
    #[DataProvider('refusedUrlProvider')]
    public function testAUrlAttributeRefusesASchemeNoPageMayEmit(string $url): void
    {
        $this->expectException(MarkupException::class);

        new Element(HtmlTag::A)->attr(HtmlAttribute::Href, $url)->render();
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function refusedUrlProvider(): iterable
    {
        yield 'javascript'         => ['javascript:alert(1)'];
        yield 'javascript, cased'  => ['JaVaScRiPt:alert(1)'];
        yield 'javascript, spaced' => ['  javascript:alert(1)'];

        // Browsers strip tabs and newlines from inside a scheme before resolving it, which is how
        // this arrives at the parser as `javascript:` regardless. An allowlist never needs to know
        // that, because it is not in the business of recognising the bad ones.
        yield 'javascript, split'  => ["jav\tascript:alert(1)"];

        yield 'data'               => ['data:text/html,alert()'];
        yield 'vbscript'           => ['vbscript:msgbox(1)'];

        // Starts with a slash exactly as a path does, and is a different origin. This is the same
        // trap the client-side navigation has on the other side.
        yield 'protocol-relative'  => ['//evil.example/x'];

        // The same URL, spelled the way that does not look like it. The WHATWG parser treats `\`
        // as `/` for as long as it is hunting for an authority, so both of these resolve to
        // https://evil.example — `new URL('/\evil.example/x', 'https://example.test/')` says so.
        // Listed separately from the one above because guarding `//` alone is how this gets missed.
        yield 'backslash authority'      => ['/\evil.example/x'];
        yield 'backslash authority, deep' => ['/\\\\evil.example'];

        // And the two an enumerated prefix list misses, which is why there is no list. The WHATWG
        // parser strips tab, CR and LF from a URL *before* parsing it, so by the time anything
        // resolves these they are `//evil.example` — while every "starts with a slash" test says
        // they are paths on this origin. See docs/history/markup.md.
        // Element asks the parser, so it refuses whatever the parser calls an authority rather
        // than whatever somebody thought to write down.
        yield 'authority behind a newline' => ["/\r\n/evil.example"];
        yield 'authority behind a tab'     => ["/\t/evil.example"];

        yield 'plaintext http'     => ['http://evil.example/x'];
        yield 'no scheme at all'   => ['evil.example/x'];
        yield 'empty'              => [''];
    }

    /**
     * @param string $url
     * @return void
     */
    #[DataProvider('allowedUrlProvider')]
    public function testAUrlAttributeAllowsWhatAPageActuallyEmits(string $url): void
    {
        self::assertStringContainsString(
            'href="' . $url . '"',
            new Element(HtmlTag::A)->attr(HtmlAttribute::Href, $url)->render(),
        );
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function allowedUrlProvider(): iterable
    {
        yield 'root'          => ['/'];
        yield 'a page'        => ['/about'];
        yield 'a download'    => ['/posts/first/download'];
        yield 'an asset'      => ['/assets/css/style.css'];
        yield 'an anchor'     => ['#five-habits'];
        yield 'an anchor on another page' => ['/rules#five-habits'];
        yield 'mailto'        => ['mailto:someone@example.test'];
        yield 'a file host'   => ['https://files.example.test/download?id=abc123'];
    }

    /**
     * Which of the framework's attributes are checked, pinned in both directions.
     *
     * An attribute the browser dereferences that nobody marked is a hole with nothing to report it:
     * the check simply would not run, and the page would look right. So the set is asserted here
     * rather than left to each enum's own good judgement, and adding a case that carries an address
     * means adding it to this list too. A site pins its own attributes the same way.
     *
     * @return void
     */
    public function testExactlyTheAddressCarryingAttributesAreCheckedAsUrls(): void
    {
        $urls = [];

        foreach (self::attributeNameProvider() as $name => [$case]) {
            if ($case->isUrl()) {
                $urls[] = $name;
            }
        }

        self::assertSame(
            [HtmlAttribute::class . '::Href', HtmlAttribute::class . '::Src', HtmlAttribute::class . '::Action'],
            $urls,
        );
    }

    /**
     * The check keys on {@link AttributeName::isUrl()}, not on HTML's own names, so an attribute a
     * site declares as an address — one a custom element hands to the browser a layer later — is
     * refused exactly as `href` is, and one it does not is left alone.
     *
     * @return void
     */
    public function testACustomAttributeThatCarriesAnAddressIsCheckedLikeHref(): void
    {
        self::assertSame(
            '<x-widget caption="javascript:alert(1)"></x-widget>',
            new Element(TagFixture::Widget)->attr(AttributeFixture::Caption, 'javascript:alert(1)')->render(),
        );

        $this->expectException(MarkupException::class);

        new Element(TagFixture::Widget)->attr(AttributeFixture::Source, 'javascript:alert(1)')->render();
    }

    /**
     * The attributes an element would hold, built the way the constructor takes them.
     *
     * @param AttributeName $name
     * @param string        $value
     * @return SearchableCollection<Attribute>
     */
    private static function attributes(AttributeName $name, string $value): SearchableCollection
    {
        return new SearchableCollection(Attribute::class)
            ->with($name->attribute(), new Attribute($name, $value));
    }

    /**
     * Both guarantees live in render(), which is what makes this class the boundary it claims to be.
     *
     * An element assembled by handing the constructor its attributes outright gets exactly the same
     * treatment as one built through attr(), because both guarantees live in render(). Applied on
     * the way *in* instead, they would make the constructor a way around escaping entirely — a
     * public one.
     *
     * @return void
     */
    public function testBothGuaranteesHoldHoweverTheElementWasBuilt(): void
    {
        self::assertSame(
            '<p class="&quot; onload=&quot;alert(1)"></p>',
            new Element(HtmlTag::P, self::attributes(HtmlAttribute::ClassName, '" onload="alert(1)'))->render(),
        );

        $this->expectException(MarkupException::class);

        new Element(HtmlTag::A, self::attributes(HtmlAttribute::Href, 'javascript:alert(1)'))->render();
    }

    /**
     * The whole document's escaping is one function call, and this is what keeps it that way.
     *
     * The same audit as the parse pin below, for the same reason: a guarantee spread over two
     * call sites is a guarantee that can be half-changed. {@link Element} escapes its attribute
     * values by rendering a {@link Text} rather than reaching for htmlspecialchars() a second time,
     * so the framework has one set of flags and one place to change them. A site's suite holds its
     * own tree to having none.
     *
     * @return void
     */
    public function testEscapingHappensInExactlyOnePlace(): void
    {
        self::assertSame(['Text.php'], self::filesContaining('htmlspecialchars('));
    }

    // ───────────────────────────── markup read back in ─────────────────────────────

    /**
     * Text that arrived as markup is escaped exactly like text that arrived as a string.
     *
     * The point of a parse over a pass-through: `&amp;` in the source is one character by the time
     * it is a {@link Text}, and {@link Text::render()} writes it back as an entity rather than
     * leaving a bare `&` in the document. A `<` that the parser read as text comes back escaped for
     * the same reason, which is the half a raw pass-through could not do at all.
     *
     * @return void
     */
    public function testParsedTextIsEscapedLikeAnyOtherText(): void
    {
        self::assertSame(
            '<p>a &amp; b &lt;not a tag&gt;</p>',
            new Element(HtmlTag::P)->containingHtml('a &amp; b &lt;not a tag&gt;')->render(),
        );
    }

    /**
     * A parsed element is an element, so it is scheme-checked on the way out like any other.
     *
     * Nothing in {@link MarkupParser} looks at a URL. It does not need to: it builds through
     * {@link Element::attr()}, so the check {@link Element::render()} already makes covers markup
     * that was parsed exactly as it covers markup that was written. That composition is the claim,
     * which is why it is asserted here rather than assumed from the two halves.
     *
     * @return void
     */
    public function testAParsedUrlIsSchemeCheckedLikeAnyOther(): void
    {
        $this->expectException(MarkupException::class);

        new Element(HtmlTag::P)->containingHtml('<a href="javascript:alert(1)">x</a>')->render();
    }

    /**
     * The source's own whitespace survives, which is what keeps a document a document.
     *
     * A parse keeps the newlines and indentation between block elements as {@link Text} nodes, and
     * a `Text` among the children is what puts {@link Element::renderChildren()} on its single-line
     * branch — so nothing is re-indented and, more importantly, no newline is *invented* between
     * inline content, where it would be a space the browser renders.
     *
     * @return void
     */
    public function testAParsedDocumentKeepsItsOwnWhitespace(): void
    {
        self::assertSame(
            "<section><h1>a</h1>\n<p>b <em>c</em></p></section>",
            new Element(HtmlTag::Section)->containingHtml("<h1>a</h1>\n<p>b <em>c</em></p>")->render(),
        );
    }

    /**
     * A parsed document says the same thing it said before it was parsed.
     *
     * The bytes deliberately do *not* match: a character reference becomes the character it names,
     * so `&auml;` goes out as `ä`. What must not change is what a reader sees, so this compares the
     * text content with whitespace collapsed — the strongest claim that survives entity decoding,
     * and the one worth making. A site holds its real documents to the same comparison.
     *
     * @return void
     */
    public function testAParsedDocumentSaysWhatItSaidBefore(): void
    {
        $source = "<h2>Erkl&auml;rung</h2>\n<p>Stra&szlig;e &amp; Platz &mdash; <a href=\"#oben\">nach oben</a>"
            . "</p>\n<ul>\n  <li><strong>eins</strong> &lt;zwei&gt;</li>\n</ul>";
        $rendered = new Element(HtmlTag::Section)->containingHtml($source)->render();

        self::assertStringContainsString('Erklärung', $rendered);
        self::assertSame(self::readable($source), self::readable($rendered));
    }

    /**
     * The parser walks every enum in the vocabulary, not only the first: `data-no-spa` is a
     * {@link LinkAttribute} rather than an {@link HtmlAttribute}, so this is the row that proves the
     * attribute registry is walked. A site's custom elements prove it for tags in its own suite.
     *
     * @return void
     */
    public function testAParsedAttributeResolvesThroughTheWholeRegistry(): void
    {
        self::assertSame(
            "<section>\n  <a href=\"/x\" data-no-spa=\"\">x</a>\n</section>",
            new Element(HtmlTag::Section)->containingHtml('<a href="/x" data-no-spa>x</a>')->render(),
        );
    }

    /**
     * A vocabulary a site extends answers for the names it added as well as the standard ones, and
     * for nothing else — the lookup the parser makes, put to it directly.
     *
     * @return void
     */
    public function testAnExtendedVocabularyResolvesThroughEveryEnumItHolds(): void
    {
        $vocabulary = Vocabulary::standard()
            ->withTags(TagFixture::class)
            ->withAttributes(AttributeFixture::class);

        self::assertSame(HtmlTag::P, $vocabulary->tagNamed('p'));
        self::assertSame(TagFixture::Widget, $vocabulary->tagNamed('x-widget'));
        self::assertNull($vocabulary->tagNamed('blockquote'));
        self::assertNull(Vocabulary::standard()->tagNamed('x-widget'), 'withTags() copies');

        self::assertSame(HtmlAttribute::Href, $vocabulary->attributeNamed('href'));
        self::assertSame(LinkAttribute::NoSpa, $vocabulary->attributeNamed('data-no-spa'));
        self::assertSame(AttributeFixture::Source, $vocabulary->attributeNamed('source'));
        self::assertNull($vocabulary->attributeNamed('onclick'));
        self::assertNull(Vocabulary::standard()->attributeNamed('source'), 'withAttributes() copies');
    }

    /**
     * Everything the parser refuses, refused for a reason it can name.
     *
     * The refusals are the point, so they are pinned exhaustively rather than illustratively — the
     * same stance {@link \Phpanta\Support\TarArchive} takes about a member name off the network.
     * Each row is a thing a hand-edited document could plausibly grow, and each one is a
     * {@link MarkupException} at load time instead of markup nobody read.
     *
     * @param string $html
     * @return void
     */
    #[DataProvider('refusedProvider')]
    public function testTheParserRefusesWhatTheTreeCannotHold(string $html): void
    {
        $this->expectException(MarkupException::class);

        (void) MarkupParser::parse($html);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function refusedProvider(): iterable
    {
        yield 'an element outside the vocabulary'   => ['<blockquote>x</blockquote>'];
        yield 'an attribute outside the vocabulary' => ['<p data-whatever="x">a</p>'];
        yield 'an event handler'                    => ['<p onclick="alert(1)">a</p>'];
        yield 'a comment'                           => ['<p>a</p><!-- and a note -->'];
        yield 'an element from another namespace'   => ['<p>a</p><svg><circle/></svg>'];
        yield 'content hoisted into the head'       => ['<title>x</title><p>a</p>'];
        yield 'a closing tag that matches nothing'  => ['<p>hi</div>'];
        yield 'a script, whose text cannot escape'  => ['<p>a</p><script>x</script>'];
        yield 'a form, which would post without its token' => ['<form action="/x"><input name="a"></form>'];
    }

    /**
     * Every vocabulary enum the framework ships spells its own name as its backing value.
     *
     * This is not tidiness, it is what makes {@link MarkupParser} correct. The parser resolves a
     * name with `tryFrom()` — a native O(1) lookup — where the honest question is "which case has
     * this `tagName()`", and the two are the same question only for as long as this holds. An enum
     * that computed its name would make the parser quietly unable to find it, so the shortcut is
     * pinned rather than assumed. A site pins its own enums the same way.
     *
     * @return void
     */
    public function testEveryNameEnumSpellsItsNameAsItsBackingValue(): void
    {
        $tags       = self::implementationsOf(TagName::class);
        $attributes = self::implementationsOf(AttributeName::class);

        self::assertNotSame([], $tags, 'found no tag enums at all — the scan is broken');
        self::assertNotSame([], $attributes, 'found no attribute enums at all — the scan is broken');

        foreach ($tags as $enum) {
            foreach ($enum::cases() as $case) {
                self::assertSame($case->value, $case->tagName(), $enum . '::' . $case->name);
            }
        }

        foreach ($attributes as $enum) {
            foreach ($enum::cases() as $case) {
                self::assertSame($case->value, $case->attribute(), $enum . '::' . $case->name);
            }
        }
    }

    /**
     * The standard vocabulary names every tag and attribute enum the framework ships, and no others.
     *
     * Pinned in both directions, because the two failures are different and both are quiet. An enum
     * missing from it does not break the parser — it makes every one of its names unparseable, on
     * every site, which reads as the *markup* being wrong. An enum listed that no longer exists is a
     * fatal on the first parse. Compared as sets, because the order of a registry means nothing.
     *
     * @return void
     */
    public function testTheStandardVocabularyKnowsEveryFrameworkEnum(): void
    {
        $tags       = Vocabulary::standard()->tags()->toValues();
        $attributes = Vocabulary::standard()->attributes()->toValues();

        sort($tags);
        sort($attributes);

        self::assertSame(self::implementationsOf(TagName::class), $tags);
        self::assertSame(self::implementationsOf(AttributeName::class), $attributes);
    }

    /**
     * The audit, retargeted. The hole became a door, and the door is still watched.
     *
     * Markup the code did not assemble goes through a parse rather than around one, so a second
     * call site is no longer a way to get unescaped markup onto a page. {@link Element} is the one
     * caller of the parser, and the framework itself hands it nothing: `containingHtml()` is a site's
     * to call, from a view that reads a document written outside PHP — never from anything a request
     * can influence. A site's suite pins its own callers.
     *
     * @return void
     */
    public function testMarkupIsParsedInExactlyOnePlace(): void
    {
        self::assertSame([], self::filesContaining('->containingHtml('));
        self::assertSame(['Element.php'], self::filesContaining('MarkupParser::parse('));
    }

    /**
     * The enums under the framework's `src/` implementing $interface, sorted — the same order a
     * registry is compared in.
     *
     * Derived from the filesystem rather than from a list, because a list is the thing being
     * checked.
     *
     * @param class-string $interface
     * @return list<class-string>
     */
    private static function implementationsOf(string $interface): array
    {
        $found = array_values(array_filter(
            SourceTree::framework()->classes(),
            static fn(string $class): bool => enum_exists($class) && is_a($class, $interface, true),
        ));

        sort($found);

        return $found;
    }

    /**
     * $markup as a reader meets it: tags gone, entities resolved, runs of whitespace collapsed.
     *
     * @param string $markup
     * @return string
     */
    private static function readable(string $markup): string
    {
        return trim((string) preg_replace(
            '/\s+/u',
            ' ',
            html_entity_decode(strip_tags($markup), ENT_QUOTES | ENT_HTML5, 'UTF-8'),
        ));
    }

    /**
     * The file names under the framework's `src/` whose source contains $needle, sorted.
     *
     * Reading the source rather than the class graph is the point: what is being asserted is that a
     * second call site does not *exist*, and a call site nobody reaches is still one somebody will
     * reach later.
     *
     * @param string $needle
     * @return list<string>
     */
    private static function filesContaining(string $needle): array
    {
        $found = [];

        foreach (SourceTree::framework()->files() as $path) {
            $source = file_get_contents($path);

            if ($source !== false && str_contains($source, $needle)) {
                $found[] = basename($path);
            }
        }

        sort($found);

        return $found;
    }
}
