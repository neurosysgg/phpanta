<?php

declare(strict_types=1);

namespace Phpanta\View\Html;

/**
 * The Attribute class. One attribute on an {@link Element}: the name it was set with, and its raw
 * value.
 *
 * Replaces an `array{AttributeName, string|null}` — a two-slot tuple, stored in a map keyed by the
 * attribute's own name, and destructured in the one place that read it. It carried the same two
 * facts this does and said nothing about which was which; `[$attribute, $value] = $pair` is a line
 * that only reads correctly if you already know the answer.
 *
 * **The name is kept beside the value even though the map is keyed by it**, and that is deliberate
 * rather than redundant: {@link Element::render()} has to ask the name whether it is a URL, and a
 * key is a string. The key is what keeps last-write-wins and declaration order; this is what keeps
 * the question askable.
 *
 * It holds the value **unescaped**, which is the whole reason `Element::render()` is where the
 * guarantees live: escaping happens once, on the way out, for any element however it was built.
 */
final readonly class Attribute
{
    /**
     * Constructs an instance of {@link self}.
     *
     * @param AttributeName                                $name  The attribute, as the enum case it
     *                                                            was set with.
     * @param string|\Phpanta\Text\Translatable|null $value The **raw, unescaped** value, or null
     *                             for a boolean attribute. `null` and `''` are different on purpose:
     *                             `narrow` is a bare attribute and `options=""` is a real empty
     *                             value, and the client reads those differently. A translatable is
     *                             kept unresolved, because which language it is in is not known
     *                             until the element renders.
     */
    public function __construct(
        public AttributeName $name,
        public string|\Phpanta\Text\Translatable|null $value,
    ) {}

    /**
     * Whether this attribute is one the browser dereferences, and so one whose scheme is checked.
     *
     * @return bool
     */
    public function isUrl(): bool
    {
        return $this->name->isUrl();
    }

    /**
     * Whether this is a bare boolean attribute — `narrow`, with no `="…"` after it.
     *
     * @return bool
     */
    public function isBoolean(): bool
    {
        return $this->value === null;
    }
}
