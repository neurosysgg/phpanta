<?php

declare(strict_types=1);

namespace Phpanta\Exception;

/**
 * The ElementException class. Thrown when an element is asked to be something no element can be.
 *
 * Two refusals, both from {@link \Phpanta\View\Html\Element} and both about a tree being *built*:
 * a void element handed children, and an attribute the browser dereferences whose value names a
 * scheme this site does not emit.
 *
 * The second is the one that matters. `htmlspecialchars` touches not one character of
 * `javascript:alert(1)`, so escaping was never the tool for a URL — the scheme allowlist is, and
 * this is what it says no with.
 *
 * @see ParserException for the same complaint from the other direction — markup being *read*.
 */
class ElementException extends MarkupException
{
}
