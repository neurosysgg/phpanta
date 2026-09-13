<?php

declare(strict_types=1);

namespace Phpanta\View\Html;

use BackedEnum;
use NoDiscard;
use Phpanta\App;
use Phpanta\Exception\ElementException;
use Phpanta\Exception\MarkupException;
use Phpanta\Support\BareCall;
use Phpanta\Support\Collection;
use Phpanta\Support\SearchableCollection;
use Phpanta\Support\UrlScheme;
use Phpanta\Text\Language;
use Phpanta\Text\Translatable;
use Uri\WhatWg\Url;

/**
 * The Element class. One element: a {@link TagName}, typed attributes, and child {@link Node}s.
 *
 * A page is a tree of these rather than string concatenation and heredocs, and four mistakes stop
 * being possible, three of which would otherwise be silent: a misspelled tag renders as an inert inline box, a
 * misspelled attribute is a null the client reads as nothing, a value that reaches the markup
 * unescaped is an injection, and a closing tag that does not match its opening one is a document
 * the browser reinterprets. The last is the one a tree removes outright — there is no closing tag
 * to get wrong, because there is no text form to write.
 *
 * Immutable, like the policies and the collections: every builder method returns a new instance.
 *
 * **Every guarantee is applied in {@link self::render()}, not in the builders.** That is what makes
 * this class the trust boundary it claims to be: `render()` is the only code on the site that turns
 * a node into markup, so a guarantee enforced there holds for *any* element however it was built —
 * including one assembled by handing the constructor its attributes outright, which the builders
 * would otherwise be the only thing standing in front of. Three are enforced:
 *
 * - **escaping**, by rendering each value as a {@link Text}, which is the site's single
 *   call to `htmlspecialchars`;
 * - **scheme**, for the attributes {@link AttributeName::isUrl()} marks, because escaping is the
 *   wrong tool for a URL and always was — `javascript:alert(1)` contains nothing to escape;
 * - **shape**: an attribute is written under its own name, whatever key it was stored under, and a
 *   void element holds nothing. The builders keep both, and the constructor, which takes a map and
 *   a list outright, is the way round them that `render()` closes.
 *
 * Rendering pretty-prints. An element whose children are all elements puts each on its own line;
 * one with any {@link Text} among them stays on a single line, because whitespace between inline
 * content is content. That rule is why `<h1>ill<span>.</span></h1>` does not gain a space.
 */
final readonly class Element implements Node
{
    /**
     * The schemes a URL attribute may name, lower-cased.
     *
     * An allowlist, so the failure mode of anything unanticipated is refusal. That matters more
     * than it looks: browsers strip tabs and newlines from inside a scheme before resolving it, so
     * a denylist has to catch `jav&#9;ascript:` and every other spelling of the same word, while an
     * allowlist simply never says yes to it.
     *
     * These two plus site-relative cover every link the site emits — `https:` for HiDrive and the
     * profiles, `mailto:` for the footer and imprint, `/…` for everything of our own. Note what is
     * absent and why: `http:` because {@link \Phpanta\Http\Security\StrictTransportSecurity} means
     * we do not emit one, and `data:` because a `data:text/html` document runs script in the
     * origin that navigated to it.
     *
     * **A list of cases rather than {@link UrlScheme::cases()}**, which would say the same thing
     * today and stop saying it the moment a scheme is added for one call site. This is what is
     * switched on; the enum is the vocabulary it may be written in — the distinction
     * {@link \Phpanta\Http\Security\CspScheme::Data} makes on the other side of the site, where
     * a case is kept for a source the policy deliberately does not allow.
     *
     * @var list<UrlScheme>
     */
    private const array URL_SCHEMES = [UrlScheme::Https, UrlScheme::Mailto];

    /**
     * The host a site-relative URL is resolved against, and that host on its own.
     *
     * Not this site's origin, and deliberately not: the question a path-shaped value has to answer
     * is "does this stay wherever the page is served from?", which no address of ours is needed to
     * ask. `.invalid` is reserved by RFC 2606 and resolves nowhere, so nothing here can be mistaken
     * for somewhere to fetch from, and the class stays uncoupled from where the site is deployed.
     *
     * The host is its own constant because {@link self::staysOnThisOrigin()} compares against it
     * rather than against a second parse — which is what keeps that method free of a null branch
     * nothing can reach.
     */
    private const string BASE_HOST     = 'relative.invalid';
    private const string RELATIVE_BASE = 'https://' . self::BASE_HOST;

    /**
     * The attributes, keyed by name.
     *
     * Keyed rather than listed, which is what keeps **the last write and the declaration order**:
     * setting `class` twice leaves one attribute, where the first one was written. Not promoted,
     * for the reason `SoundCloudEmbed::$options` is not — the default
     * is a `new`, and a parameter default has to be a constant expression.
     *
     * @var SearchableCollection<Attribute>
     */
    private SearchableCollection $attributes;

    /**
     * The element's content, in the order it was added.
     *
     * A `Collection` for the reason {@link self::$attributes} is a `SearchableCollection`, and it
     * is the same argument on the parameter beside it: {@link self::containing()} is a variadic and
     * so is checked by PHP, but the constructor is public and took a plain `array` whose `list<Node>`
     * was a docblock's promise. A string reaching it that way is not a `TypeError` naming the
     * element, it is a fatal in {@link self::renderChildren()} calling `render()` on a string.
     *
     * Listed rather than keyed, unlike the attributes: children have order and no names, and
     * nothing here overwrites one. Not promoted, for the same reason — the default is a `new`, and
     * a parameter default has to be a constant expression.
     *
     * @var Collection<Node>
     */
    private Collection $children;

    /**
     * Constructs an instance of {@link self}.
     *
     * @param TagName $tag The element to build.
     * @param SearchableCollection<Attribute>|null $attributes Normally left null and built with
     *                                         {@link self::attr()}. Keyed by the attribute's name,
     *                                         which {@link self::render()} holds it to.
     * @param Collection<Node>|null $children The element's content. Normally left null and built
     *                                        with {@link self::containing()}. None for a void tag,
     *                                        which {@link self::render()} holds it to.
     */
    public function __construct(
        private TagName $tag,
        ?SearchableCollection $attributes = null,
        ?Collection $children = null,
    ) {
        $this->attributes = $attributes ?? new SearchableCollection(Attribute::class);
        $this->children   = $children   ?? new Collection(Node::class);
    }

    /**
     * Returns a copy carrying the given attribute.
     *
     * One method for every shape an attribute takes, because three near-identical builders was
     * three chances to reach for the wrong one. What `$value` is decides what gets rendered:
     *
     * | `$value`      | rendered              |
     * |---------------|-----------------------|
     * | `'visual'`, 5 | `player-style="visual"`, `height="5"` |
     * | `CssClass::Hero`, any backed enum | its value — `class="hero"` |
     * | `new ViewportContent(…)`, any {@link AttributeValue} | what it renders |
     * | `Texts::Releases::CoverArt`, any {@link Translatable} | its text, in the element's language, at render |
     * | `''`          | `options=""` — an empty value, which is not the same as no attribute |
     * | `true`        | `narrow` — a bare boolean attribute |
     * | `false`, null | nothing at all        |
     *
     * The `''` and `null` rows are the distinction worth keeping straight: a public SoundCloud
     * track has no secret token, and `secret-token=""` is not the same thing to the client as no
     * attribute — so an absent value is `null`, and `''` stays a real empty value.
     *
     * This only normalises and stores. Escaping and the URL check both happen in
     * {@link self::render()}, so neither can be got around by building an element another way.
     *
     * @param AttributeName $attribute
     * @param string|int|bool|BackedEnum|AttributeValue|Translatable|null $value
     * @return self
     */
    #[NoDiscard('attr() returns a copy carrying the attribute; the element it was called on is unchanged')]
    public function attr(
        AttributeName $attribute,
        string|int|bool|BackedEnum|AttributeValue|Translatable|null $value = true,
    ): self {
        if ($value === false || $value === null) {
            return $this;
        }

        // Both of these normalise; neither guarantees anything. That is why they are here and not
        // in render(), where the escaping and the scheme check live: what those two protect has to
        // hold for an element built any way at all, and what these two do is only ever shorthand
        // for the string a call site would otherwise have written out.
        //
        // A value with parts renders itself, so the grammar lives in one class instead of in the
        // call — see AttributeValue.
        if ($value instanceof AttributeValue) {
            $value = $value->render();
        }

        // Ahead of the backed-enum arm below, and the order is the point: a catalog case is a
        // backed enum too, and read as one it would render its key — `cover-art` in an alt text —
        // rather than its words. It stays unresolved until render(), the one place that knows which
        // language it is in.
        if ($value instanceof Translatable) {
            return new self(
                $this->tag,
                $this->attributes->with($attribute->attribute(), new Attribute($attribute, $value)),
                $this->children,
            );
        }

        // A backed enum stands for its value, so a call site passes CssClass::Hero rather than
        // remembering ->value — one fewer thing to get right at twenty call sites.
        if ($value instanceof BackedEnum) {
            $value = $value->value;
        }

        return new self(
            $this->tag,
            $this->attributes->with(
                $attribute->attribute(),
                new Attribute($attribute, $value === true ? null : (string) $value),
            ),
            $this->children,
        );
    }

    /**
     * Returns a copy containing the given children, appended in order.
     *
     * A bare string is content, not markup: it becomes a {@link Text} and is escaped. That is the
     * safe reading of the ambiguous case — markup passed as a string shows up as visible `&lt;b&gt;`
     * rather than as markup — and getting real markup in takes {@link self::containingHtml()},
     * which parses it rather than trusting it.
     *
     * A {@link Translatable} becomes a {@link TranslatedText}: escaped the same way, and put into
     * the language of whichever `lang` it ends up under when the tree is rendered.
     *
     * @param Node|string|Translatable ...$children
     * @return self
     * @throws ElementException if the element is void; `<img>` cannot contain anything.
     */
    #[NoDiscard('containing() returns a copy holding the children; the element it was called on is unchanged')]
    #[BareCall(
        'array_map',
        'maps the variadic PHP has already guarded, straight into with() — so a Collection here '
        . 'would be constructed only to be spread back out on the same line. This is also the '
        . 'hottest path on the site: every element of every page is built through it, and '
        . "docs/collections.md's note that renderChildren() is the one place to spend a foreach is about "
        . 'these two lines.',
    )]
    public function containing(Node|string|Translatable ...$children): self
    {
        if ($this->tag->isVoid() && $children !== []) {
            throw $this->voidRefusal();
        }

        return new self($this->tag, $this->attributes, $this->children->with(
            ...array_map(
                static fn(Node|string|Translatable $child): Node => match (true) {
                    $child instanceof Node         => $child,
                    $child instanceof Translatable => new TranslatedText($child),
                    default                        => new Text($child),
                },
                $children,
            ),
        ));
    }

    /**
     * Returns a copy containing $html, parsed into nodes.
     *
     * The safe twin of {@link self::containing()}, and the pair is worth reading together:
     * `containing('<b>x</b>')` puts visible `&lt;b&gt;` on the page, because a string is content;
     * this parses the same argument into a real `<b>` — after checking that `b` is an element this
     * site emits, that everything on it is an attribute this site emits, and that the parser had to
     * repair nothing to read it. See {@link MarkupParser}, which is where all of that lives.
     *
     * **This is the one door for markup authored outside PHP**, and it carries a standing
     * instruction: never hand it anything a request can influence. The refusals mean it would not be an
     * injection, but the vocabulary being this site's own means a visitor would otherwise get to
     * choose which of our elements to build.
     *
     * The parsed nodes become children of *this* element rather than being wrapped in a
     * {@link Fragment}, which is what keeps a document coming back out as it went in: a parse keeps
     * the source's own whitespace as {@link Text}, and a `Text` among the children is what puts
     * {@link self::renderChildren()} on its single-line branch, where nothing is re-indented and no
     * whitespace is invented between inline content.
     *
     * @param string $html Markup, hand-authored and read from a file next to the code.
     * @return self
     * @throws MarkupException if the element is void, or if $html names anything outside the two
     *                         vocabularies, or if it does not parse cleanly.
     */
    #[NoDiscard('containingHtml() returns a copy holding the parsed markup; the element it was called on is unchanged')]
    public function containingHtml(string $html): self
    {
        // toValues() rather than a bare spread, because a spread of string keys is named arguments —
        // the rule every spreading call site here follows. A list has none, and says so anyway.
        return $this->containing(...MarkupParser::parse($html)->toValues());
    }

    /**
     * Renders this element as markup.
     *
     * @param int           $depth
     * @param Language|null $language The language in scope above this element. Its own `lang`, where
     *                                it names one of ours, replaces it for this element and
     *                                everything under it — the attribute's meaning in HTML, applied
     *                                to the text this tree translates.
     * @return string
     * @throws ElementException if a URL attribute names a scheme {@link self::URL_SCHEMES} does not
     *                         allow, if an attribute is stored under a key other than its name, or
     *                         if a void element holds children. Loud on purpose, and at the boundary
     *                         on purpose: a link the site refuses to draw is a missing link, which
     *                         somebody notices, and a `javascript:` href that renders is one nobody
     *                         does.
     */
    public function render(int $depth = 0, ?Language $language = null): string
    {
        $language = $this->ownLanguage() ?? $language;

        $open = '<' . $this->tag->tagName() . $this->renderAttributes($language) . '>';

        if ($this->tag->isVoid()) {
            // containing() refuses this already. The constructor takes its children outright, and a
            // void element has no closing tag to put them before — so they would be written after
            // it, as siblings nobody placed there.
            if (!$this->children->isEmpty()) {
                throw $this->voidRefusal();
            }

            return $open;
        }

        $close = '</' . $this->tag->tagName() . '>';

        if ($this->children->isEmpty()) {
            return $open . $close;
        }

        return $open . $this->renderChildren($depth, $language) . $close;
    }

    /**
     * The language this element's own `lang` names, or null where it names none this app offers.
     *
     * Asked of the app's {@link \Phpanta\Text\Languages} rather than of {@link Language} itself,
     * because the framework knows languages an app may not be written in. A `lang` this app does not
     * offer — `fr` on a quotation, or a language the framework has and this site does not write —
     * leaves the language in scope as it was, rather than switching every translation under it into
     * a language whose half this app never wrote.
     *
     * @return Language|null
     */
    private function ownLanguage(): ?Language
    {
        $lang = $this->attributes->find(HtmlAttribute::Lang->attribute())?->value;

        return is_string($lang) ? App::current()->languages()->tryFrom($lang) : null;
    }

    /**
     *
     * @param Language|null $language
     * @return string
     * @throws ElementException if a URL attribute carries a scheme that is not allowed, or an
     *                          attribute is stored under a key other than its own name.
     */
    private function renderAttributes(?Language $language): string
    {
        $rendered = '';

        foreach ($this->attributes as $key => $attribute) {
            $name = $attribute->name->attribute();

            // What is written is the attribute's own name, and the key has to be it: the key is how
            // the map keeps one attribute per name, so a map built with the two disagreeing is an
            // element that writes one attribute twice, and the browser keeps whichever came first.
            if ($key !== $name) {
                throw new ElementException(sprintf(
                    "<%s> holds its %s attribute under the key '%s'. An attribute is keyed by its own name.",
                    $this->tag->tagName(),
                    $name,
                    $key,
                ));
            }

            if ($attribute->isBoolean()) {
                $rendered .= ' ' . $name;
                continue;
            }

            $value = $attribute->value instanceof Translatable
                ? TranslatedText::resolve($attribute->value, $language)
                : (string) $attribute->value;

            if ($attribute->isUrl()) {
                $this->verifyUrl($name, $value);
            }

            // Escaped by rendering a Text, so the site has one call to htmlspecialchars, not two.
            // Correct here because an attribute value is always emitted inside double quotes.
            $rendered .= ' ' . $name . '="' . new Text($value)->render() . '"';
        }

        return $rendered;
    }

    /**
     *
     * @param string $name
     * @param string $value
     * @return void
     * @throws ElementException if $value names a scheme {@link self::URL_SCHEMES} does not allow.
     */
    #[BareCall(
        'array_map',
        'maps a class constant for the reason Layout::modulePreloads() does, and does it on the '
        . 'throwing branch — the schemes are being listed into a refusal, so this is work done '
        . 'only on the path where the site is already wrong.',
    )]
    private function verifyUrl(string $name, string $value): void
    {
        if (self::isAllowedUrl($value)) {
            return;
        }

        throw new ElementException(sprintf(
            '<%s %s="%s"> is not a URL this site may emit. Allowed: a site-relative path, or %s.',
            $this->tag->tagName(),
            $name,
            $value,
            implode(' / ', array_map(
                static fn(UrlScheme $scheme): string => $scheme->value,
                self::URL_SCHEMES,
            )),
        ));
    }

    /**
     * The refusal a void element with children earns, from whichever door it came in by.
     *
     * @return ElementException
     */
    private function voidRefusal(): ElementException
    {
        return new ElementException(sprintf(
            '<%s> is a void element and cannot contain anything.',
            $this->tag->tagName(),
        ));
    }

    /**
     * True if $value is a site-relative path or names an allowed scheme.
     *
     * @param string $value
     * @return bool
     */
    private static function isAllowedUrl(string $value): bool
    {
        // A leading slash is not the same claim as "somewhere on this site", so it is asked rather
        // than assumed — see staysOnThisOrigin(). Everything else has to name a scheme we allow.
        if (str_starts_with($value, '/')) {
            return self::staysOnThisOrigin($value);
        }

        $lower = strtolower($value);

        return array_any(
            self::URL_SCHEMES,
            static fn(UrlScheme $scheme): bool => str_starts_with($lower, $scheme->value),
        );
    }

    /**
     * True if a path-shaped $value resolves to the origin it was resolved against.
     *
     * **Never answer this with a list of the prefixes an authority can open with** (`//`, `/\`). A
     * list of the spellings that occurred to us is exactly the shape of mistake this class is
     * arranged to avoid: the WHATWG parser strips tab, CR and LF from a URL *before* parsing it, so
     * `/\r\n/evil.example` is `//evil.example` is `https://evil.example`, and every "starts with a
     * slash" test in the world says it is a path on this site.
     *
     * PHP 8.5 ships that parser, so the question is put to it instead of pattern-matched: the value
     * is resolved the way a browser would resolve it, and the answer is whether it landed where it
     * started. `Navigation.ts` runs the same check on the client. See docs/history/markup.md.
     *
     * **The one thing the parse cannot tell is a value that names the base's own host.**
     * `//relative.invalid/x` lands exactly where it started — on the base's host — and is still a
     * protocol-relative URL, which a browser sends to that host rather than to whichever one served
     * the page. So the value is first put through the parser's own preprocessing — tab, CR and LF
     * removed, leading C0 controls and spaces trimmed — and refused if two slashes of either kind
     * then open it, which is the parser's own rule for where an authority begins, not a list of
     * spellings. Everything else is still the parser's to answer.
     *
     * **Public**, because a redirect asks the same question of the path it sends a visitor to —
     * {@link \Phpanta\Http\Location} and the language switch — and two answers to it would be two
     * chances to get the one hazard above wrong.
     *
     * @param string $value
     * @return bool
     */
    public static function staysOnThisOrigin(string $value): bool
    {
        $preprocessed = ltrim(str_replace(["\t", "\n", "\r"], '', $value), "\x00..\x20");

        if (strspn($preprocessed, '/\\') >= 2) {
            return false;
        }

        // A null base makes a relative reference unparseable, so the null this returns fails the
        // comparison below rather than needing a branch of its own. The constant is a literal
        // origin; it parses.
        $resolved = Url::parse($value, Url::parse(self::RELATIVE_BASE));

        return $resolved?->getAsciiHost() === self::BASE_HOST;
    }

    /**
     * Renders the children, on one line or on several.
     *
     * Any {@link Text} among them forces one line: a newline before or after inline content is a
     * space the browser renders, so breaking `<p>E-Mail: <a>…</a></p>` across lines would change
     * the page rather than just its source. A {@link TranslatedText} is text too, and so is a
     * {@link Fragment} holding either — see {@link Fragment::writesText()}. And a fragment among
     * children on one line is rendered on it, rather than breaking its own nodes onto lines of their
     * own.
     *
     * @param int           $depth
     * @param Language|null $language
     * @return string
     */
    private function renderChildren(int $depth, ?Language $language): string
    {
        $inline = $this->children->first(Fragment::writesText(...));

        if ($inline !== null) {
            return $this->children
                ->map(static fn(Node $child): string => $child instanceof Fragment
                    ? $child->renderInline($depth, $language)
                    : $child->render($depth, $language))
                ->join('');
        }

        $pad      = str_repeat('  ', $depth);
        $rendered = '';

        foreach ($this->children as $child) {
            $rendered .= "\n" . $pad . '  ' . $child->render($depth + 1, $language);
        }

        return $rendered . "\n" . $pad;
    }
}
