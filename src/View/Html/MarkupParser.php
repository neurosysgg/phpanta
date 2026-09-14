<?php

declare(strict_types=1);

namespace Phpanta\View\Html;

use Dom\HTMLDocument;
use Dom\HTMLElement;
use Dom\Node as DomNode;
use Dom\Text as DomText;
use Phpanta\App;
use Phpanta\Exception\ParserException;
use Phpanta\Support\Collection;
use Phpanta\Support\Diagnostics;

/**
 * The MarkupParser class. Reads the grammar {@link Element} writes, back into the tree.
 *
 * It exists for hand-authored documents — a legal page in two languages, say, rather than markup
 * a view assembles — so that they do not need a node that emits a trusted string verbatim,
 * guarded by nothing but a docblock and a test pinning its call sites. A convention with a test
 * behind it is not a guarantee, and it would leave one place where the four mistakes
 * {@link Element} exists to remove were all possible again. See docs/history/markup.md.
 *
 * A parse makes the guarantee structural. The document comes in through the same door as
 * everything else: an element name has to be a {@link TagName} case, an attribute name has to be an
 * {@link AttributeName} case, text is escaped by {@link Text::render()}, and a URL attribute is
 * scheme-checked by {@link Element::render()} like any other. Nothing is trusted for where it came
 * from. Because the vocabulary is closed, an `onerror=` or a `<form>` that appears in a future
 * re-export is a {@link \Phpanta\Exception\MarkupException} when the file loads rather than markup
 * nobody read.
 *
 * **The refusals are the point, so they are exhaustive rather than illustrative** — the same stance
 * {@link \Phpanta\Support\TarArchive} takes about a member name that came off the network. An
 * unknown element, an unknown attribute, a comment, a CDATA section, an element from another
 * namespace, a document's own elements wherever they turn up, and any HTML5 parse error at all are
 * each refused rather than skipped, because a document this class quietly dropped half of is worse
 * than one it would not read.
 *
 * **What it costs, measured rather than assumed.** Per half of a two-language privacy policy, with
 * no Xdebug loaded: 0.071 ms to parse, 0.242 ms to walk into the tree, 0.262 ms for
 * {@link Element::render()} to write it back out. So such a page pays about **+1.14 ms** for both
 * halves, which is a large cost to take for a guarantee. It is affordable on a legal page, which
 * is among the least-visited on any site; it would not be affordable on a page anyone loads twice.
 *
 * The walk is **three times** that under Xdebug, which is worth knowing before a profiled figure
 * is quoted against an unprofiled one: the parse itself barely moves, because it happens in C.
 *
 * **No network flag is needed and none is expressible.** `createFromString()` accepts only
 * `LIBXML_NOERROR`, `LIBXML_COMPACT`, `LIBXML_HTML_NOIMPLIED` and `Dom\HTML_NO_DEFAULT_NS`, and
 * HTML5 has no DTD entity mechanism, so there is no external-entity door here to close. That is
 * worth stating rather than leaving to be re-derived, because it is the first question anybody
 * sensible asks about a DOM parser.
 */
final readonly class MarkupParser
{
    /**
     * The elements that make a document rather than content, refused wherever they appear.
     *
     * Not only where the parser hoists them. At the top of a fragment a `<title>` is moved into the
     * head, which {@link self::parse()} checks for; inside content the parser leaves a `<title>`, a
     * `<meta>` or a `<link>` where it found it, and a view rendering one would put a document's
     * metadata in the middle of a page — a second title the browser ignores, or a stylesheet nobody
     * reviewed. `<html>`, `<head>` and `<body>` inside content are parse errors, which
     * {@link self::read()} refuses first; they are listed so the set says what it means.
     *
     * @var list<HtmlTag>
     */
    private const array DOCUMENT_TAGS = [
        HtmlTag::Html,
        HtmlTag::Head,
        HtmlTag::Body,
        HtmlTag::Title,
        HtmlTag::Meta,
        HtmlTag::Link,
    ];

    /**
     * Parses $html into nodes, refusing anything the tree cannot hold.
     *
     * Answers with a collection rather than a single node because a document is not an element: a
     * legal page can run to well over a hundred top-level nodes. {@link Element::containingHtml()}
     * is what puts them somewhere, and is the only caller. **Never parse anything a request can
     * influence.** Not because this would let it through — that is the whole point of the
     * refusals — but because the vocabulary is the app's own, so a visitor could otherwise decide
     * which of its elements to build.
     *
     * @param string $html Markup, hand-authored and read from a file next to the code.
     * @return Collection<Node>
     * @throws ParserException if the markup does not parse cleanly, or names anything outside the
     *                         two vocabularies. Loud on purpose and at load time on purpose: the
     *                         data file is part of this repository, so a refusal here means
     *                         something in it is written wrong.
     */
    public static function parse(string $html): Collection
    {
        $document = self::read($html);
        $hoisted  = $document->head?->firstChild;

        // A <title> or a <meta> in the fragment is not a parse error — the parser moves it into the
        // head, where a view cannot reach it and where it would vanish without a word. Content
        // markup leaves the head empty, which is what makes this a check rather than a guess.
        if ($hoisted !== null) {
            // strtolower() because nodeName shouts an HTML element's name — `TITLE`, not `title` —
            // and localName, which would not, is an Element member where this is still a Node.
            throw new ParserException(sprintf(
                '<%s> belongs in the document head, so parsing it here would silently drop it. '
                . 'Only content elements belong in markup a view parses.',
                strtolower($hoisted->nodeName),
            ));
        }

        // The parser implies <html>, <head> and <body> around any fragment, so the body is always
        // there — LIBXML_HTML_NOIMPLIED, the one option that would remove it, is deliberately not
        // passed. The document stands in for the null that cannot happen so that it flows into the
        // refusal below rather than needing a branch of its own, the way
        // Element::staysOnThisOrigin() handles a base URL that will not parse.
        //
        // The vocabulary is asked for once and handed down the walk: an app builds its vocabulary
        // when it is asked for one, and the walk used to ask for every element and attribute it met.
        return self::childrenOf($document->body ?? $document, App::current()->vocabulary());
    }

    /**
     * Parses $html into a document, refusing it if the parser had to repair anything.
     *
     * Two things here are load-bearing.
     *
     * **The doctype is {@link Doctype::Html5}, not a literal.** It is what puts the parser in
     * no-quirks mode, and without it *every* fragment reports `unexpected-token-in-initial-mode` and
     * the error trap below is so much noise. Reusing the one class that owns that string also means
     * this file holds no `<` literal at all, so it needs no exemption from the verify script's check
     * that {@link Element} and {@link Doctype} are the only two files that write markup out.
     *
     * **The errors are trapped rather than ignored.** `Dom\HTMLDocument` reports HTML5 tokenizer and
     * tree errors as PHP warnings and then recovers silently, which is exactly the wrong behaviour
     * for a hand-edited legal document: a stray `</div>` swallows the rest of the policy and nothing
     * anywhere says so. A document written with care parses with no errors at all, so refusing on
     * any is affordable.
     *
     * Note the one offset in what a message reports: the doctype is prepended without a newline, so
     * a reported line number matches the file, and only a column on line 1 is out by its length.
     *
     * @param string $html
     * @return HTMLDocument
     * @throws ParserException if the parser reported anything at all.
     */
    private static function read(string $html): HTMLDocument
    {
        $parsed = Diagnostics::watched(static fn(): HTMLDocument => HTMLDocument::createFromString(
            Doctype::Html5->render() . $html,
        ));

        if (!$parsed->reported->isEmpty()) {
            throw new ParserException(sprintf(
                'Markup this site emits has to parse cleanly, and this did not: %s',
                $parsed->reported->join(' / '),
            ));
        }

        return $parsed->result;
    }

    /**
     * $parent's children, in order.
     *
     * @param DomNode    $parent
     * @param Vocabulary $vocabulary What an element or an attribute may be named.
     * @return Collection<Node>
     * @throws ParserException if any descendant is something the tree cannot hold.
     */
    private static function childrenOf(DomNode $parent, Vocabulary $vocabulary): Collection
    {
        $nodes = [];

        foreach ($parent->childNodes as $child) {
            $nodes[] = self::node($child, $vocabulary);
        }

        return new Collection(Node::class)->with(...$nodes);
    }

    /**
     * One parsed node, as the {@link Node} it becomes.
     *
     * Three kinds go in and two come out, which is the whole check: an HTML element becomes an
     * {@link Element}, a run of text becomes a {@link Text}, and everything else is refused. That
     * last case is one test covering three hazards, because of how PHP's parser types what it
     * builds — an element from another namespace is a plain `Dom\Element` rather than a
     * `Dom\HTMLElement`, so SVG and MathML are refused by the same line that refuses a comment and a
     * CDATA section. None of the three has a node here to become, and inventing one is how a tree
     * starts carrying content nothing escapes.
     *
     * @param DomNode    $node
     * @param Vocabulary $vocabulary
     * @return Node
     * @throws ParserException if $node is neither an HTML element nor text.
     */
    private static function node(DomNode $node, Vocabulary $vocabulary): Node
    {
        if ($node instanceof DomText) {
            return new Text($node->data);
        }

        if (!$node instanceof HTMLElement) {
            throw new ParserException(sprintf(
                '%s is not something the markup tree can hold. Parsed markup is elements and text; '
                . 'a comment, a CDATA section and an element from another namespace each have no '
                . 'node to become.',
                $node->nodeName,
            ));
        }

        return self::element($node, $vocabulary);
    }

    /**
     * One parsed element, with its attributes and its subtree.
     *
     * Built through {@link Element::attr()} and {@link Element::containing()} rather than through
     * the constructor, deliberately: a parsed element is then exactly an element a view could have
     * written, and the void-element guard applies to it without being restated here.
     *
     * A name is looked up with `tryFrom()` on each of the vocabulary's enums rather than by scanning
     * for a case whose `tagName()` matches — a shortcut only because a test makes it one: every case
     * of every enum in both registries is asserted to spell its own name as its backing value.
     *
     * @param HTMLElement $element
     * @param Vocabulary  $vocabulary
     * @return Element
     * @throws ParserException if the element or any of its attributes is outside the vocabulary, or
     *                         the element belongs to the document rather than to its content.
     */
    private static function element(HTMLElement $element, Vocabulary $vocabulary): Element
    {
        $tag = $vocabulary->tagNamed($element->localName) ?? throw new ParserException(sprintf(
            '<%s> is not an element this site emits. Add its case to one of: %s.',
            $element->localName,
            $vocabulary->tags()->join(', '),
        ));

        // The one element refused for what it is rather than for being unknown, and the reason is
        // correctness rather than policy: a <script>'s content is raw text, and the only escaper
        // here is Text::render(), which would turn `a < b` into `a &lt; b` and change what the
        // script means. There is no node that renders raw text, so refusing is the honest answer.
        // <style> needs no line of its own — it has no HtmlTag case, so the vocabulary has it.
        if ($tag === HtmlTag::Script) {
            throw new ParserException(sprintf(
                '<%s> cannot be parsed and rendered faithfully: its content is raw text, and the '
                . 'only escaping this tree has would change what the script means.',
                $tag->tagName(),
            ));
        }

        // Refused for what it would do rather than for what it is. A form is written by
        // Phpanta\Form\Form, which puts the visitor's form token in it; one parsed out of a document
        // would post without it, and be refused by the guard on every send — a form that looks
        // right and never works.
        if ($tag === HtmlTag::Form) {
            throw new ParserException(sprintf(
                '<%s> is written by a Form, which carries the form token; a hand-authored one would post without it.',
                $tag->tagName(),
            ));
        }

        if (in_array($tag, self::DOCUMENT_TAGS, true)) {
            throw new ParserException(sprintf(
                '<%s> belongs to the document around a page, not to content a view parses.',
                $tag->tagName(),
            ));
        }

        $built = new Element($tag);

        foreach ($element->attributes as $attribute) {
            $built = $built->attr(
                $vocabulary->attributeNamed($attribute->localName) ?? throw new ParserException(sprintf(
                    '<%s %s> is not an attribute this site emits. This is what refuses an event '
                    . 'handler. Add its case to one of: %s.',
                    $element->localName,
                    $attribute->localName,
                    $vocabulary->attributes()->join(', '),
                )),
                $attribute->value,
            );
        }

        // An <iframe>'s content is raw text to the parser, so `&amp;` inside one arrives undecoded and
        // would go out as `&amp;amp;`. A browser shows none of it anyway.
        if ($tag instanceof HtmlTag && $tag->isRawText() && $element->hasChildNodes()) {
            throw new ParserException(sprintf(
                '<%s> holds raw text, which cannot be rendered back as it was written; leave it empty.',
                $tag->tagName(),
            ));
        }

        return $built->containing(...self::childrenOf($element, $vocabulary)->toValues());
    }
}
