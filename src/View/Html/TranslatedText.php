<?php

declare(strict_types=1);

namespace Phpanta\View\Html;

use Phpanta\Exception\TranslationException;
use Phpanta\Text\Language;
use Phpanta\Text\Translatable;
use UnitEnum;

/**
 * The TranslatedText class. Text written in every language the site is, put into one when it is
 * rendered.
 *
 * What {@link Element::containing()} makes of a {@link Translatable}, the way it makes a
 * {@link Text} of a string. It carries no language: which one it renders in is decided by where it
 * sits, which is the nearest `lang` above it. See {@link Node}.
 *
 * Inline, like a `Text`: an element with one among its children keeps them on one line, for the
 * reason {@link Element} gives for text.
 */
final readonly class TranslatedText implements Node
{
    /**
     * Constructs an instance of {@link self}.
     *
     * @param Translatable $text
     */
    public function __construct(public Translatable $text) {}

    /**
     * @param int           $depth
     * @param Language|null $language
     * @return string
     * @throws TranslationException if no language is in scope.
     */
    public function render(int $depth = 0, ?Language $language = null): string
    {
        return new Text(self::resolve($this->text, $language))->render();
    }

    /**
     * $text in $language — or a refusal, where nothing has said which language this is.
     *
     * **Loud on purpose.** The quiet alternative is a default, and a default here is an English word
     * on a German page, which nothing anywhere would report. {@link Element} resolves translated
     * attribute values through here too, so the two cannot disagree about what "no language" means.
     *
     * @param Translatable  $text
     * @param Language|null $language
     * @return string
     * @throws TranslationException if $language is null.
     */
    public static function resolve(Translatable $text, ?Language $language): string
    {
        if ($language === null) {
            throw new TranslationException(sprintf(
                '%s was rendered with no language in scope. Render it inside an element that '
                . 'carries a lang attribute, or pass the language to render().',
                $text instanceof UnitEnum ? $text::class . '::' . $text->name : get_debug_type($text),
            ));
        }

        return $text->in($language);
    }
}
