<?php

declare(strict_types=1);

namespace Phpanta\Http;

use Phpanta\Text\Language;

/**
 * The SetCookie class. The value of a `Set-Cookie` header — the one cookie the framework sets.
 *
 * It is set only when a visitor clicks a language switch, and it holds only a language code. Every
 * attribute is there for a reason, and none is optional:
 *
 * - `Path=/` — every page answers in the language, so every page is sent the cookie.
 * - `Max-Age` of a year — a choice worth remembering, and a number rather than a date, so no two
 *   clocks have to agree on when it runs out.
 * - `SameSite=Lax` — sent when a visitor navigates here, not with another site's requests.
 * - `Secure` — over HTTPS only. A browser counts `http://localhost` as secure too, so local runs
 *   work unchanged.
 * - `HttpOnly` — no script reads it, and none needs to: the server decides the language, and the
 *   page states it on its root element.
 *
 * It is storage a site's privacy policy has to name; see docs/language.md.
 */
final readonly class SetCookie implements HeaderValue
{
    /** A year, in seconds. */
    private const int YEAR = 31_536_000;

    /**
     * Constructs an instance of {@link self}.
     *
     * @param CookieName $name
     * @param string     $value
     * @param int        $maxAge In seconds.
     */
    private function __construct(
        private CookieName $name,
        private string     $value,
        private int        $maxAge,
    ) {}

    /**
     * The cookie that remembers a visitor's language.
     *
     * @param Language $language
     * @return self
     */
    public static function language(Language $language): self
    {
        return new self(CookieName::Language, $language->value, self::YEAR);
    }

    /**
     * @return string
     */
    public function render(): string
    {
        return sprintf(
            '%s=%s; Path=/; Max-Age=%d; SameSite=Lax; Secure; HttpOnly',
            $this->name->value,
            $this->value,
            $this->maxAge,
        );
    }
}
