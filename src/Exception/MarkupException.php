<?php

declare(strict_types=1);

namespace Phpanta\Exception;

use LogicException;

/**
 * The MarkupException class. What the markup tree throws, in three kinds.
 *
 * **Abstract, because nothing throws this one.** It is what {@link ElementException},
 * {@link ParserException} and {@link TerminalException} have in common, and saying so in the
 * language is what stops a fourth kind arriving as a bare `MarkupException` — which would read as
 * "one of those three" and be none of them. A `catch` or an `@throws` naming this still means what
 * it always did: any of the three.
 *
 * The split is along the one line worth drawing here — an element being *built* against markup
 * being *read* — and it was a split before it was three classes:
 *
 * - {@link ElementException} — {@link \Phpanta\View\Html\Element} refusing to be something no
 *   element can be: a void element with children, a URL naming a scheme the site does not emit.
 * - {@link ParserException} — {@link \Phpanta\View\Html\MarkupParser} refusing markup that names an
 *   element or an attribute this site does not have, or that does not parse cleanly at all.
 * - {@link TerminalException} — {@link \NeuroSYS\View\Terminal\Terminal} unable to hand its rows to
 *   the element that draws them.
 *
 * **The parser's kind is the same category as the others, which is worth saying rather than
 * assuming.** A parse failure looks at first like bad input, and bad input is a condition a caller
 * recovers from — but the only markup this parser is ever handed is `data/privacy.*.html`, which is
 * checked into this repository beside the code that reads it. A refusal there means a file in this
 * repo is written wrong, exactly as a void element with children does.
 *
 * **Extends `LogicException`, and that is the classification rather than a detail.** Nothing on
 * this site recovers from it and nothing should try: this is not a condition a caller acts on, it is
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
