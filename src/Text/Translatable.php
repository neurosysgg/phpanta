<?php

declare(strict_types=1);

namespace Phpanta\Text;

/**
 * The Translatable interface. Text that exists in every language the site is written in, and is
 * put into one at the last moment.
 *
 * **It carries no language of its own**, and that is the whole design. A view writes
 * `->containing(Texts::Releases::Downloads)` and never says which language; the markup tree
 * decides at render, from the nearest `lang` above the text — see
 * {@link \Phpanta\View\Html\Node}. So a view cannot pick the wrong language, because it never
 * picks one, and the same tree renders in either.
 *
 * Three things implement it: a catalog case ({@link Translated}), an inline {@link Translation},
 * and a {@link Phrase} — either of those with its arguments bound. A response that is not a tree —
 * a plain-text 503 — calls {@link self::in()} itself, with the request's language.
 */
interface Translatable
{
    /**
     * This text in $language.
     *
     * @param Language $language
     * @return string
     */
    public function in(Language $language): string;
}
