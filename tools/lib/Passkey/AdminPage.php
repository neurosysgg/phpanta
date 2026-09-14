<?php

declare(strict_types=1);

namespace Phpanta\Tool\Passkey;

use Dom\HTMLDocument;
use Phpanta\Http\CsrfField;
use Phpanta\View\Html\PasskeyAttribute;

/**
 * The AdminPage class. What a browser reads off one of the admin's pages: the form token, the
 * challenge a passkey form carries, an enrolment code, and what the page says.
 *
 * Read with PHP's HTML5 parser, the way a browser reads it, and asked by the names the server writes
 * them under — {@link CsrfField} and {@link PasskeyAttribute} — so a renamed field is a missing
 * answer here, not a guess.
 */
final readonly class AdminPage
{
    /** The enrolment command's code, as the enrolment page writes the command out. */
    private const string CODE = '/--code\s+(\S+)/';

    /**
     * Constructs an instance of {@link self}.
     *
     * @param HTMLDocument $document
     */
    private function __construct(private HTMLDocument $document) {}

    /**
     * $html, read.
     *
     * @param string $html
     * @return self
     */
    public static function of(string $html): self
    {
        return new self(HTMLDocument::createFromString($html === '' ? '<!DOCTYPE html>' : $html, LIBXML_NOERROR));
    }

    /**
     * The form token the page's forms carry, or null where it has no form.
     *
     * @return string|null
     */
    public function token(): ?string
    {
        return $this->document->querySelector('input[name="' . CsrfField::Token->value . '"]')?->getAttribute('value');
    }

    /**
     * The challenge a passkey form on the page carries, or null where there is none.
     *
     * @return string|null
     */
    public function challenge(): ?string
    {
        $attribute = PasskeyAttribute::Challenge->value;

        return $this->document->querySelector('[' . $attribute . ']')?->getAttribute($attribute);
    }

    /**
     * The enrolment code the page shows, or null where it shows none.
     *
     * @return string|null
     */
    public function enrolmentCode(): ?string
    {
        $command = (string) $this->document->querySelector('textarea')?->textContent;

        return preg_match(self::CODE, $command, $match) === 1 ? $match[1] : null;
    }

    /**
     * The page's text, its whitespace collapsed and cut at $length characters — enough to say what
     * a page that was not the expected one was.
     *
     * @param int $length
     * @return string
     */
    public function text(int $length = 240): string
    {
        $text = trim((string) preg_replace('/\s+/', ' ', (string) $this->document->body?->textContent));

        return mb_strlen($text) > $length ? mb_substr($text, 0, $length) . '…' : $text;
    }

    /**
     * What the page says in bold — the entrance's messages, the enrolment page's fingerprint — one
     * line each.
     *
     * @return string
     */
    public function said(): string
    {
        $said = '';

        foreach ($this->document->querySelectorAll('strong') as $strong) {
            $said .= trim((string) $strong->textContent) . "\n";
        }

        return $said;
    }
}
