<?php

declare(strict_types=1);

namespace Phpanta\Tool\Export;

use Dom\HTMLDocument;

/**
 * The Anchors class. The ids a written page has, and the links on it that name one.
 *
 * A link to `/guide#setup` can name a page the export wrote and still land nowhere: the file is
 * there and the id is not, and a browser shows the top of the page without a word. Whether the file
 * is there is {@link BasePath}'s half of the check; this is the other half, so that the export fails
 * on a dangling anchor the way it fails on a dangling page.
 *
 * Read with a real HTML parser, as {@link BasePath::addresses()} reads, and only from `href`s: a
 * fragment on a `src` names a part of a resource, not an element on a page.
 */
final readonly class Anchors
{
    /**
     * Every id on $markup's elements.
     *
     * @param string $markup
     * @return list<string>
     */
    public static function idsIn(string $markup): array
    {
        $ids = [];

        foreach (self::document($markup)->querySelectorAll('[id]') as $element) {
            $ids[] = (string) $element->getAttribute('id');
        }

        return $ids;
    }

    /**
     * Every link on $markup that names an anchor, as its address without the fragment — `''` for
     * one on the same page — and the fragment as written. A bare `#` names none: it is the top.
     *
     * @param string $markup
     * @return list<array{string, string}>
     */
    public static function fragmentLinks(string $markup): array
    {
        $links = [];

        foreach (self::document($markup)->querySelectorAll('[href*="#"]') as $element) {
            $href     = (string) $element->getAttribute('href');
            $at       = (int) strpos($href, '#');
            $fragment = substr($href, $at + 1);

            if ($fragment !== '') {
                $links[] = [substr($href, 0, $at), $fragment];
            }
        }

        return $links;
    }

    /**
     * Whether one of $ids is the element $fragment names: the fragment as written, then
     * percent-decoded — the order a browser looks in, and the one `Navigation` follows after a swap.
     *
     * @param list<string> $ids
     * @param string       $fragment
     * @return bool
     */
    public static function names(array $ids, string $fragment): bool
    {
        return in_array($fragment, $ids, true) || in_array(rawurldecode($fragment), $ids, true);
    }

    /**
     * @param string $markup
     * @return HTMLDocument
     */
    private static function document(string $markup): HTMLDocument
    {
        return HTMLDocument::createFromString($markup, LIBXML_NOERROR);
    }
}
