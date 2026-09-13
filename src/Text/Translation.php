<?php

declare(strict_types=1);

namespace Phpanta\Text;

use Attribute;
use Phpanta\Exception\TranslationException;

/**
 * The Translation class. One text, as written in each language the app is written in.
 *
 * **Two uses, one class**, and that is the design rather than a coincidence:
 *
 * - **On a catalog case**, as an attribute, so both languages sit beside the case they belong to:
 *   `#[Translation(en: 'downloads', de: 'Downloads')] case Downloads = 'downloads';`. See
 *   {@link Translated}.
 * - **Inline**, as a value, for text that belongs to one entry rather than to the app — what a
 *   data file writes: `summary: new Translation(en: 'hello', de: 'hallo')`.
 *
 * **English is required and German is not.** A missing German text falls back to the English,
 * which is honest — the page says what it has — where an empty string would be a gap nobody sees.
 * {@link self::has()} answers whether a language was actually written, which is what the catalog's
 * own test asks of every case.
 *
 * **Literal until it takes arguments.** {@link self::in()} answers the text exactly as written.
 * ICU's message syntax — `{title}`, plurals, a doubled apostrophe — is in force only once a
 * {@link Phrase} binds arguments to it, so a data file's description cannot fail to render for
 * containing a brace.
 */
#[Attribute(Attribute::TARGET_CLASS_CONSTANT)]
final readonly class Translation implements Translatable
{
    /**
     * Constructs an instance of {@link self}.
     *
     * @param string      $en The English text. Required: it is the one language every translation
     *                        has, and what every other falls back to.
     * @param string|null $de The German text, or null where there is none yet.
     * @throws TranslationException if $en is blank.
     */
    public function __construct(public string $en, public ?string $de = null)
    {
        if (trim($en) === '') {
            throw new TranslationException(
                'A translation needs its English text: it is the site\'s own language, and what '
                . 'every other falls back to.',
            );
        }
    }

    /**
     * @param Language $language
     * @return string
     */
    public function in(Language $language): string
    {
        return $this->pattern($language);
    }

    /**
     * The text written for $language, or the English where that language has none.
     *
     * The same answer as {@link self::in()}, under the name {@link Phrase} reads it by: to a phrase
     * this is an ICU pattern, to be formatted rather than shown.
     *
     * @param Language $language
     * @return string
     */
    public function pattern(Language $language): string
    {
        return match ($language) {
            Language::English => $this->en,
            Language::German  => $this->de ?? $this->en,
        };
    }

    /**
     * Whether $language has a text of its own, rather than falling back to the English.
     *
     * @param Language $language
     * @return bool
     */
    public function has(Language $language): bool
    {
        return match ($language) {
            Language::English => true,
            Language::German  => $this->de !== null,
        };
    }
}
