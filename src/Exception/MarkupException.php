<?php

declare(strict_types=1);

namespace Phpanta\Exception;

use LogicException;

/**
 * The MarkupException class. What the markup tree throws, in two kinds, and what a site's own
 * markup refusals extend.
 *
 * **Abstract, because nothing throws this one.** It is what {@link ElementException} and
 * {@link ParserException} have in common, and saying so in the language is what stops a further
 * kind arriving as a bare `MarkupException` — which would read as "one of those" and be none of
 * them. A site whose own code refuses to build markup adds its kind as a subclass here. A `catch`
 * or an `@throws` naming this still means what it always did: any of them.
 *
 * The framework's split is along the one line worth drawing here — an element being *built*
 * against markup being *read*:
 *
 * - {@link ElementException} — {@link \Phpanta\View\Html\Element} refusing to be something no
 *   element can be: a void element with children, a URL naming a scheme the tree does not emit.
 * - {@link ParserException} — {@link \Phpanta\View\Html\MarkupParser} refusing markup that names an
 *   element or an attribute the app does not have, or that does not parse cleanly at all.
 *
 * **The parser's kind is the same category as the other, which is worth saying rather than
 * assuming.** A parse failure looks at first like bad input, and bad input is a condition a caller
 * recovers from — but the only markup the parser may be handed is hand-authored, checked into the
 * repository beside the code that reads it. A refusal there means a file in the repository is
 * written wrong, exactly as a void element with children does.
 *
 * **Extends `LogicException`, and that is the classification rather than a detail.** Nothing
 * recovers from it and nothing should try: this is not a condition a caller acts on, it is
 * "something in this repository is written wrong, go and fix it" — which is what SPL's
 * `LogicException` means. Saying it in the type rather than only the prose also settles a question
 * that would otherwise follow it around: whether every `containing()` and every `render()` owes an
 * `@throws` for a failure that only happens when the site is already broken. It does not.
 *
 * The handler at the door in `public/index.php` does catch it, which is not the contradiction it
 * reads as: it recovers nothing, it turns what would have been a PHP fatal into a 500 with an empty
 * body and a line in the log. See {@link SiteException}.
 */
abstract class MarkupException extends LogicException implements SiteException
{
}
