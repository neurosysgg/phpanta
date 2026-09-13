<?php

declare(strict_types=1);

namespace Phpanta\View\Html;

use Phpanta\Support\Charset;
use Phpanta\Text\Language;

/**
 * The Text class. A run of text, escaped on the way out.
 *
 * Every string that reaches the page as content is one of these, so escaping is not something a
 * caller can forget — it is the only way text can get in. {@link Element::containing()} wraps bare
 * strings in one automatically, which means the unsafe thing is the thing you cannot type by
 * accident: markup in a string renders as visible `&lt;b&gt;`, and getting real markup in takes
 * {@link Element::containingHtml()}, which parses it against this site's own vocabulary rather
 * than trusting it.
 *
 * **This is the only `htmlspecialchars()` call on the site, and `HtmlTest` pins it there.**
 * {@link Element} escapes its attribute values by rendering one of these rather than calling the
 * function a second time, so the whole document's escaping is one line with one set of flags. That
 * is the point: a guarantee spread over two call sites is a guarantee that can be half-changed.
 * Attribute values are always rendered inside double quotes, so the same escaping is correct for
 * both contexts.
 */
final readonly class Text implements Node
{
    /**
     * The escaping flags, stated rather than inherited.
     *
     * These are already PHP's defaults from 8.1 on, and this project requires 8.5. Written out
     * anyway, because "the default happens to be right" is a fact about the runtime and not about
     * this code, and this is the one line the whole document's safety rests on:
     *
     * - `ENT_QUOTES` escapes **both** quote characters. Without it `'` survives, and until 8.1 that
     *   was the default — an attribute delimited with a single quote would have been a breakout.
     * - `ENT_SUBSTITUTE` replaces malformed UTF-8 with U+FFFD. Without it the function returns the
     *   *empty string* for the whole input, so bad bytes anywhere would silently erase the text
     *   rather than show a replacement character.
     * - `ENT_HTML401` is the doctype, and is what the implicit default already was; naming it keeps
     *   the output byte-identical while leaving nothing about this line implied.
     */
    public const int FLAGS = ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML401;

    /**
     * Constructs an instance of {@link self}.
     *
     * @param string $text The text, unescaped. Write the real character — `·`, not `&middot;` —
     *                     because an entity written here would come back out as `&amp;middot;`.
     */
    public function __construct(public string $text) {}

    /**
     * The encoding is the site's one {@link Charset} rather than a constant of this class, for the
     * same reason {@link self::FLAGS} is written out rather than inherited: this is the line the
     * whole document's safety rests on, and a second name for the encoding is a second thing that
     * can be changed alone.
     *
     * @param int           $depth
     * @param Language|null $language Unread: a string is already in whatever language it is in.
     * @return string
     */
    public function render(int $depth = 0, ?Language $language = null): string
    {
        return htmlspecialchars($this->text, self::FLAGS, Charset::Utf8->canonical());
    }
}
