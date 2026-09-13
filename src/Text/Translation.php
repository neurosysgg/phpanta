<?php

declare(strict_types=1);

namespace Phpanta\Text;

use Attribute;
use Phpanta\App;
use Phpanta\Exception\TranslationException;

/**
 * The Translation class. One text, as written in each language the app is written in.
 *
 * **Two uses, one class**, and that is the design rather than a coincidence:
 *
 * - **On a catalog case**, as an attribute, so every language sits beside the case it belongs to:
 *   `#[Translation(en: 'downloads', de: 'Downloads')] case Downloads = 'downloads';`. See
 *   {@link Translated}.
 * - **Inline**, as a value, for text that belongs to one entry rather than to the app — what a
 *   data file writes: `summary: new Translation(en: 'hello', de: 'hallo')`.
 *
 * **Any one language is enough, and none may be blank.** A language with no text of its own falls
 * back to the app's default language, and where that has none either, to the first text written —
 * which is honest: the page says what it has, where an empty string would be a gap nobody sees. So
 * a blank text is refused rather than taken for one: a language with nothing to say is left out,
 * and falls back. {@link self::has()} answers whether a language was actually written, which is
 * what a catalog's own test asks of every case, for every language its app offers.
 *
 * **Literal until it takes arguments.** {@link self::in()} answers the text exactly as written.
 * ICU's message syntax — `{title}`, plurals, a doubled apostrophe — is in force only once a
 * {@link Phrase} binds arguments to it, so a data file's description cannot fail to render for
 * containing a brace.
 *
 * **A new language is a parameter here and an arm in {@link self::written()}**, beside its
 * {@link Language} case.
 */
#[Attribute(Attribute::TARGET_CLASS_CONSTANT)]
final readonly class Translation implements Translatable
{
    /** The first text written, in {@link Language}'s order: the fallback of last resort. */
    private string $first;

    /**
     * Constructs an instance of {@link self}. Each text is null where that language has none.
     *
     * @param string|null $en English.
     * @param string|null $de German.
     * @param string|null $fr French.
     * @param string|null $es Spanish.
     * @param string|null $it Italian.
     * @param string|null $nl Dutch.
     * @throws TranslationException if no language has a text, or one has a blank one.
     */
    public function __construct(
        public ?string $en = null,
        public ?string $de = null,
        public ?string $fr = null,
        public ?string $es = null,
        public ?string $it = null,
        public ?string $nl = null,
    ) {
        $first = null;

        foreach (Language::cases() as $language) {
            $text = $this->written($language);

            if ($text !== null && trim($text) === '') {
                throw new TranslationException(sprintf(
                    'The %s text is blank. A language with nothing to say is left out, and falls back.',
                    $language->name,
                ));
            }

            $first ??= $text;
        }

        $this->first = $first ?? throw new TranslationException(
            'A translation needs a text in at least one language: there is nothing to fall back to.',
        );
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
     * The text written for $language, or the {@link self::fallback()} for the booted app's languages
     * where that language has none.
     *
     * The same answer as {@link self::in()}, under the name {@link Phrase} reads it by: to a phrase
     * this is an ICU pattern, to be formatted rather than shown.
     *
     * @param Language $language
     * @return string
     */
    public function pattern(Language $language): string
    {
        return $this->written($language) ?? $this->fallback(App::current()->languages());
    }

    /**
     * What a language with no text of its own is shown: the text of the first of $languages that
     * has one — the default, wherever it was written — else the first text written at all.
     *
     * Public so the order can be asserted against any app's languages, not only the booted one's.
     *
     * @param Languages $languages
     * @return string
     */
    public function fallback(Languages $languages): string
    {
        foreach ($languages->offered()->toValues() as $language) {
            $text = $this->written($language);

            if ($text !== null) {
                return $text;
            }
        }

        return $this->first;
    }

    /**
     * Whether $language has a text of its own, rather than falling back.
     *
     * @param Language $language
     * @return bool
     */
    public function has(Language $language): bool
    {
        return $this->written($language) !== null;
    }

    /**
     * The text written for $language, or null.
     *
     * @param Language $language
     * @return string|null
     */
    private function written(Language $language): ?string
    {
        return match ($language) {
            Language::English => $this->en,
            Language::German  => $this->de,
            Language::French  => $this->fr,
            Language::Spanish => $this->es,
            Language::Italian => $this->it,
            Language::Dutch   => $this->nl,
        };
    }
}
