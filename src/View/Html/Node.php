<?php

declare(strict_types=1);

namespace Phpanta\View\Html;

use Phpanta\Text\Language;

/**
 * The Node interface. Anything that can render itself as markup.
 *
 * The point of the interface is that {@link Element} takes children of this type and nothing else,
 * so a document is a tree of objects rather than a string built by concatenation. Everything that
 * reaches the page is one of five things: an {@link Element}, escaped {@link Text}, a
 * {@link TranslatedText} — text put into a language when it is rendered — a {@link Sentence}, which
 * is that text with nodes placed in it, or a {@link Fragment} of those.
 *
 * **The language travels down the tree the way the depth does.** A view never says which language
 * its text is in; an element that carries a `lang` names it for everything under it, and passes it
 * on through {@link self::render()}. On a page that element is `<html lang>`, which the request
 * decided; on the German half of a legal document it is that half, `<section lang="de">`, which
 * is what keeps it German on an English page. It is HTML's own meaning of the attribute, applied
 * to the text this tree translates — and above the first `lang` there is no language at all, where
 * a {@link TranslatedText} refuses to render rather than guessing.
 *
 * **Hand-authored markup is not a fifth.** A hand-authored document is read *into* these by
 * {@link MarkupParser}, so markup authored outside PHP is a thing the tree can be built from rather
 * than an exception to it, and an element or an attribute the app does not emit is a refusal
 * rather than a string nobody read. See docs/history/markup.md.
 *
 * **There is a second tree in the framework, and it is deliberately not this one.** The tooling
 * emits PHP data files through an expression tree of its own, `Phpanta\Tool\Php\Expression`,
 * which answers for PHP source the objection this answers for markup — nothing builds a language by
 * concatenating it — and which states the same indentation contract as {@link self::render()} does,
 * in a parameter of its own shape.
 *
 * They stay two types, on the test this codebase already applies to `Support\TypedItems`: nothing
 * anywhere holds "either kind of node", so a common parent would announce a type nothing wants. It
 * would also have to live under `src/` to be reachable from both — shipped to the host and inside
 * `phpunit.xml.dist`'s coverage source, for a tool that never runs there — which is the arrangement
 * the rule that no tooling class goes under `src/` exists to refuse. The kinship is real and it is
 * prose, which is the most a language can carry across a boundary the deployment draws.
 */
interface Node
{
    /**
     * Renders this node as markup.
     *
     * @param int $depth How deep this node sits, in two-space indents. The first line is returned
     *                   unindented — whoever places it already put it at that column — and every
     *                   line after it is indented to $depth. Same contract at every level, which is
     *                   what makes the tree pretty-print without any node knowing where it is.
     * @param Language|null $language The language in scope: the nearest `lang` above this node,
     *                   handed down by the element that carries it. Null above the first one. A node
     *                   with nothing to translate ignores it and passes it on.
     *
     * @return string
     */
    public function render(int $depth = 0, ?Language $language = null): string;
}
