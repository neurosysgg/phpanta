<?php

declare(strict_types=1);

namespace Phpanta\Text;

use Phpanta\Exception\TranslationException;
use ReflectionEnumUnitCase;

/**
 * The Translated trait. What makes an enum a catalog: each case's words, read off its own
 * {@link Translation} attribute.
 *
 * `use Translated;` in an enum that implements {@link Translatable}, one
 * `#[Translation(en: …, de: …)]` on each case, and the case *is* the text —
 * `->containing(Catalog::Downloads)` needs nothing else. The backing value is a stable key
 * and never the words: two captions may say the same thing, and a backed enum's values must be
 * unique.
 *
 * **Read by reflection, once per case per request**, and kept in a static for the rest of it. A
 * trait's static belongs to each class that uses it, so one catalog's cache cannot answer for
 * another's case of the same name.
 *
 * A case with no attribute is a {@link TranslationException} rather than an empty string; the
 * catalog's own test finds one before a page does.
 */
trait Translated
{
    /**
     * @param Language $language
     * @return string
     */
    public function in(Language $language): string
    {
        return $this->translation()->in($language);
    }

    /**
     * This case's text with arguments bound, named the way the message names them:
     * `Catalog::Caption->with(title: $post->title)`.
     *
     * @param int|float|string ...$arguments
     * @return Phrase
     */
    public function with(int|float|string ...$arguments): Phrase
    {
        return new Phrase($this->translation(), $arguments);
    }

    /**
     * The words on this case.
     *
     * @return Translation
     * @throws TranslationException if the case carries no `#[Translation]`.
     */
    public function translation(): Translation
    {
        static $read = [];

        return $read[$this->name] ??= (new ReflectionEnumUnitCase($this, $this->name)
            ->getAttributes(Translation::class)[0] ?? null)
            ?->newInstance()
            ?? throw new TranslationException(sprintf(
                '%s::%s has no #[Translation]. Every case of a catalog carries one, with its English '
                . 'text at least.',
                $this::class,
                $this->name,
            ));
    }
}
