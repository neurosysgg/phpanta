<?php

declare(strict_types=1);

namespace Phpanta\View\Html;

/**
 * The AttributeValue interface. What goes inside the quotes of one attribute.
 *
 * The other half of {@link AttributeName}, and it arrived second for the reason
 * {@link \Phpanta\Http\HeaderValue} did on the other side of the site: the name was typed first
 * because a misspelled attribute name is silent, and the value was left a string on the reasoning
 * that a value is just text. So is a name.
 *
 * The difference — the same one that argument turns on — is that some attribute values have a
 * **grammar**. `width=device-width, initial-scale=1.0` is a comma-separated descriptor list whose
 * every part is a name-value pair, assembled at the one place a grammar cannot be checked: the
 * `->attr(…)` call site. {@link ViewportContent} is the first and so far only one.
 *
 * **Most attribute values want no implementation of this.** A fixed vocabulary is a `BackedEnum` —
 * {@link LinkRel}, {@link LinkTarget}, {@link ScriptType}, {@link MediaPreload}, {@link MetaName},
 * {@link \Phpanta\Text\Language} — and {@link Element::attr()} unwraps one directly. A value that is *data*, like
 * a title or a `src`, is a string and always was. This is for the third kind and no other: a value
 * with parts.
 *
 * `render()` rather than `__toString()`, exactly as {@link \Phpanta\Http\HeaderValue} reasons: it
 * is the name every other object here that produces a wire form already uses, and a `Stringable`
 * would let a value be concatenated into somewhere it was never checked for.
 */
interface AttributeValue
{
    /**
     * The value as it appears between the quotes, before escaping.
     *
     * Before, not after: what comes back goes through {@link Element::render()} like any other
     * value, so it is escaped once, in the site's one call to `htmlspecialchars`, and a URL-shaped
     * attribute is scheme-checked on the same way out. An implementation that escaped its own
     * output would be escaping it twice.
     *
     * @return string
     */
    public function render(): string;
}
