<?php

declare(strict_types=1);

namespace Phpanta\Exception;

/**
 * The ParserException class. Thrown when hand-authored markup is not something the app's own
 * vocabulary can hold.
 *
 * Six throws in {@link \Phpanta\View\Html\MarkupParser}, between them refusing an element the app
 * does not emit, an attribute it does not emit — which is what turns away an `onerror=` — a comment,
 * a CDATA section, an element from another namespace, content hoisted into `<head>`, a `<script>`
 * whose raw-text content `Text` would escape into meaning something else, and any HTML5 parse error
 * at all.
 *
 * **That last one is the check a verbatim emitter could never make.**
 * `Dom\HTMLDocument` reports a stray `</div>` as a warning and then recovers silently, which for a
 * hand-edited legal document means the rest of the policy disappears with nothing anywhere saying
 * so. A document written with care parses with zero errors, which is what makes refusing on any of
 * them affordable.
 *
 * Separate from {@link ElementException} because a caller can tell them apart usefully: one says a
 * view is written wrong, the other says a file in `data/` is. Under the same base because both are
 * this repository being wrong rather than a request being odd.
 */
class ParserException extends MarkupException
{
}
