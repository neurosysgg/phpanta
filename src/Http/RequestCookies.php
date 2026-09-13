<?php

declare(strict_types=1);

namespace Phpanta\Http;

/**
 * The RequestCookies class. What a `Cookie` header carries, asked for by name.
 *
 * A class for the reason {@link AcceptedLanguages} is one: the header is a grammar — RFC 6265
 * §4.2.1, `name=value` pairs separated by `; ` — so it is read in one place rather than split with
 * an `explode()` wherever a cookie is wanted.
 *
 * **Scanned on the question, not parsed up front.** The site reads one cookie. Holding every pair a
 * browser sends — whatever else it has decided belongs to this origin — would be keeping data
 * nothing asked for, so this keeps the raw header and looks for the one name it is asked about.
 *
 * **Lenient in what it reads**, like {@link AcceptedLanguages}: a pair with no `=` is skipped, a
 * value in double quotes loses them (the grammar allows them), and the first pair with the name
 * wins — a browser sends the one with the most specific path first. What a value *means* is the
 * caller's to check: {@link Request::language()} asks {@link \Phpanta\Text\Language::tryFrom()},
 * so `lang=xx` is no language at all rather than an error.
 */
final readonly class RequestCookies
{
    /**
     * Constructs an instance of {@link self}.
     *
     * @param string $header
     */
    private function __construct(private string $header) {}

    /**
     * Reads one `Cookie` header value.
     *
     * @param string $header The raw header value, or `''` if it did not arrive.
     * @return self
     */
    public static function from(string $header): self
    {
        return new self($header);
    }

    /**
     * The value of the cookie named $name, or null if the request carries none.
     *
     * @param CookieName $name
     * @return string|null
     */
    public function value(CookieName $name): ?string
    {
        foreach (explode(';', $this->header) as $pair) {
            $equals = strpos($pair, '=');

            if ($equals === false || trim(substr($pair, 0, $equals)) !== $name->value) {
                continue;
            }

            $value = trim(substr($pair, $equals + 1));

            return strlen($value) >= 2 && str_starts_with($value, '"') && str_ends_with($value, '"')
                ? substr($value, 1, -1)
                : $value;
        }

        return null;
    }
}
