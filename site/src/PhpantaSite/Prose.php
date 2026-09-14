<?php

declare(strict_types=1);

namespace PhpantaSite;

use BackedEnum;
use Phpanta\Text\Translatable;
use Phpanta\View\Html\Element;
use Phpanta\View\Html\HtmlAttribute;
use Phpanta\View\Html\HtmlTag;
use Phpanta\View\Html\Node;

/**
 * The few shapes this site's prose is made of, each built one way.
 *
 * A page's view is a list of these, so a heading is always an anchored heading and a sample is always
 * a sample — the conventions the hand-written pages used to keep by being careful are kept here by
 * construction.
 */
final class Prose
{
    /**
     * The page's one `h1`.
     *
     * @param Translatable|string $text
     * @return Element
     */
    public static function title(Translatable|string $text): Element
    {
        return new Element(HtmlTag::H1)->containing($text);
    }

    /**
     * The paragraph that says what the page is about.
     *
     * @param Translatable|Node $text
     * @return Element
     */
    public static function lede(Translatable|Node $text): Element
    {
        return new Element(HtmlTag::P)->attr(HtmlAttribute::ClassName, 'lede')->containing($text);
    }

    /**
     * @param Translatable|Node $text
     * @return Element
     */
    public static function paragraph(Translatable|Node $text): Element
    {
        return new Element(HtmlTag::P)->containing($text);
    }

    /**
     * A subheading that links to itself: `<h2 id="five-habits"><a href="#five-habits">…</a></h2>`.
     *
     * The anchor is the catalog case's key, so it is the same in every language and survives the
     * heading being reworded; the words are the case's, in the page's language.
     *
     * @param Translatable&BackedEnum $heading
     * @return Element
     */
    public static function heading(Translatable&BackedEnum $heading): Element
    {
        // The key as a string, never the case itself: attr() reads a Translatable as its words.
        $anchor = (string) $heading->value;

        return new Element(HtmlTag::H2)
            ->attr(HtmlAttribute::Id, $anchor)
            ->containing(new Element(HtmlTag::A)->attr(HtmlAttribute::Href, '#' . $anchor)->containing($heading));
    }

    /**
     * A name, a path or a piece of syntax, set as code. The same in every language.
     *
     * @param string $code
     * @return Element
     */
    public static function code(string $code): Element
    {
        return new Element(ProseTag::Code)->containing($code);
    }

    /**
     * A code sample: a `<code-block>` of `<code-line>`s, highlighted — see {@link SampleLanguage}.
     *
     * @param CodeSample $sample
     * @return Element
     */
    public static function sample(CodeSample $sample): Element
    {
        return $sample->highlighted();
    }

    /**
     * The lead-in of a point, set in bold.
     *
     * @param Translatable $text
     * @return Element
     */
    public static function strong(Translatable $text): Element
    {
        return new Element(HtmlTag::Strong)->containing($text);
    }

    /**
     * A link. An on-site one is given an address that follows the page's language —
     * `DocsPath::Rules->inEachLanguage()`.
     *
     * @param string|Translatable $href
     * @param Translatable|string $text
     * @return Element
     */
    public static function link(string|Translatable $href, Translatable|string $text): Element
    {
        return new Element(HtmlTag::A)->attr(HtmlAttribute::Href, $href)->containing($text);
    }

    /**
     * A bulleted list, one item each.
     *
     * @param Translatable|Node ...$items
     * @return Element
     */
    public static function items(Translatable|Node ...$items): Element
    {
        $list = [];

        foreach ($items as $item) {
            $list[] = new Element(HtmlTag::Li)->containing($item);
        }

        return new Element(HtmlTag::Ul)->containing(...$list);
    }
}
