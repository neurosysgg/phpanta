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
 * What `Catalog::Total->with(count: $n)` returns. The arguments are bound where the view knows
 * them; the language is not known until render, and it decides as much as the words do. ICU picks
 * the plural form by the language's own rules, and writes a number the way that language writes
 * one — `1.000` in German against `1,000` in English. That is what ext/intl is for here; see
 * {@link \Phpanta\Model\Health\PhpExtension::Intl}.
 *
 * **ICU forgives what this refuses.** An argument nobody bound comes out as its own placeholder, an
 * argument with no name is never read, and a string that is not a number comes out as 0 where the
 * message formats a number — each a sentence on the page that is wrong, and nothing in any log.
 * An argument the message never uses is not refused: it changes nothing on the page.
 */
final readonly class Phrase implements Translatable
{
    /**
     * The arguments, by the names the message uses.
     *
     * @var array<string, int|float|string>
     */
    #[BareArray(
        'MessageFormatter::format() takes its arguments as an array keyed by name, and this '
        . 'holds exactly that: the named variadic Translated::with() collected, passed through.',
    )]
    private array $arguments;

    /**
     * Constructs an instance of {@link self}.
     *
     * @param Translation                          $translation
     * @param array<int|string, int|float|string> $arguments
     * @throws TranslationException if an argument has no name — `with(3)` rather than `with(count: 3)`,
     *                              which ICU would read as argument 0 and no message here names.
     */
    #[BareArray('takes what Translated::with() collected, for the reason stated on the property')]
    public function __construct(private Translation $translation, array $arguments)
    {
        foreach ($arguments as $name => $value) {
            if (is_int($name)) {
                throw new TranslationException(
                    'An argument to a phrase is named, the way its message names it: with(count: 3), not with(3).',
                );
            }
        }

        $this->arguments = $arguments;
    }

    /**
     * @param Language $language
     * @return string
     * @throws TranslationException if ICU cannot format the message — a pattern that does not parse —
     *                              or the message uses an argument nobody bound, or formats one as a
     *                              number that was bound a string which is not one.
     */
    public function in(Language $language): string
    {
        $pattern   = $this->translation->pattern($language);
        $formatter = MessageFormatter::create($language->value, $pattern)
            ?? throw self::unformattable($pattern, $language, intl_get_error_message());
        $formatted = $formatter->format($this->arguments);

        if ($formatted === false) {
            throw self::unformattable($pattern, $language, $formatter->getErrorMessage());
        }

        $this->refuseUnbound($formatter, $formatted, $pattern);
        $this->refuseMistyped($formatter, $formatted, $pattern);

        return $formatted;
    }

    /**
     * Refuses a message that uses an argument nobody bound.
     *
     * ICU writes such an argument as its own placeholder — `Up to {max} characters.` — and says
     * nothing. A placeholder in what came out is that, or a brace the message quotes, `'{max}'`, and
     * binding the name tells the two apart: only an argument the message uses changes what comes
     * out. ICU is asked rather than the pattern read, so there is one grammar here, and it is ICU's.
     *
     * @param MessageFormatter $formatter
     * @param string           $formatted What it made of the arguments as bound.
     * @param string           $pattern
     * @return void
     * @throws TranslationException
     */
    private function refuseUnbound(MessageFormatter $formatter, string $formatted, string $pattern): void
    {
        preg_match_all('/\{([^\s{}\',#]+)\}/u', $formatted, $placeholders);

        foreach ($placeholders[1] as $name) {
            if (!isset($this->arguments[$name]) && $this->probe($formatter, $name, 0) !== $formatted) {
                throw new TranslationException(sprintf(
                    "'%s' uses {%s}, and nothing was bound to it: with(%s: …).",
                    $pattern,
                    $name,
                    $name,
                ));
            }
        }
    }

    /**
     * Refuses a string bound where the message formats a number, and which is not one.
     *
     * ICU formats such a string as 0 — `with(max: 'abc')` is `Up to 0 characters.` — and says
     * nothing. So a string that is not a number is tried against another: an argument the message
     * writes out as it is changes what comes out, and one it formats as a number — or chooses a
     * branch by, or does not use at all — does not. A number in its place then tells the number from
     * the rest. Nothing is tried for a number, or for a string that is one.
     *
     * @param MessageFormatter $formatter
     * @param string           $formatted What it made of the arguments as bound.
     * @param string           $pattern
     * @return void
     * @throws TranslationException
     */
    private function refuseMistyped(MessageFormatter $formatter, string $formatted, string $pattern): void
    {
        foreach ($this->arguments as $name => $value) {
            if (
                !is_string($value)
                || is_numeric($value)
                || $this->probe($formatter, $name, $value . 'x') !== $formatted
            ) {
                continue;
            }

            $numbered = $this->probe($formatter, $name, 7);

            if ($numbered !== false && $numbered !== $formatted) {
                throw new TranslationException(sprintf(
                    "'%s' formats {%s} as a number, and '%s' is not one.",
                    $pattern,
                    $name,
                    $value,
                ));
            }
        }
    }

    /**
     * What $formatter makes of the arguments with $name bound to $value instead — false where ICU
     * cannot format that at all.
     *
     * @param MessageFormatter $formatter
     * @param string           $name
     * @param int|string       $value
     * @return string|false
     */
    private function probe(MessageFormatter $formatter, string $name, int|string $value): string|false
    {
        return $formatter->format([...$this->arguments, $name => $value]);
    }

    /**
     * The refusal for a message ICU cannot format at all.
     *
     * @param string   $pattern
     * @param Language $language
     * @param string   $reason What ICU said.
     * @return TranslationException
     */
    private static function unformattable(string $pattern, Language $language, string $reason): TranslationException
    {
        return new TranslationException(sprintf(
            "'%s' is not a message ICU can format in %s: %s",
            $pattern,
            $language->name,
            $reason,
        ));
    }
}
