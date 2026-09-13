<?php

declare(strict_types=1);

namespace Phpanta\Text;

use MessageFormatter;
use Phpanta\Exception\TranslationException;
use Phpanta\Support\BareArray;

/**
 * The Phrase class. A translation with its arguments bound, formatted by ICU in whichever language
 * it is rendered in.
 *
 * What `Texts::Stats::Total->with(count: $n)` returns. The arguments are bound where the view knows
 * them; the language is not known until render, and it decides as much as the words do. ICU picks
 * the plural form by the language's own rules, and writes a number the way that language writes
 * one — `1.000` in German against `1,000` in English. That is what ext/intl is for here; see
 * {@link \Phpanta\Model\Health\PhpExtension::Intl}.
 */
final readonly class Phrase implements Translatable
{
    /**
     * The arguments, by the names the message uses.
     *
     * @var array<int|string, int|float|string>
     */
    #[BareArray(
        'MessageFormatter::formatMessage() takes its arguments as an array keyed by name, and this '
        . 'holds exactly that: the named variadic Translated::with() collected, passed through.',
    )]
    private array $arguments;

    /**
     * Constructs an instance of {@link self}.
     *
     * @param Translation                          $translation
     * @param array<int|string, int|float|string> $arguments
     */
    #[BareArray('takes what Translated::with() collected, for the reason stated on the property')]
    public function __construct(private Translation $translation, array $arguments)
    {
        $this->arguments = $arguments;
    }

    /**
     * @param Language $language
     * @return string
     * @throws TranslationException if ICU cannot format the message — a pattern that does not parse.
     */
    public function in(Language $language): string
    {
        $pattern   = $this->translation->pattern($language);
        $formatted = MessageFormatter::formatMessage($language->value, $pattern, $this->arguments);

        if ($formatted === false) {
            throw new TranslationException(sprintf(
                "'%s' is not a message ICU can format in %s: %s",
                $pattern,
                $language->name,
                intl_get_error_message(),
            ));
        }

        return $formatted;
    }
}
