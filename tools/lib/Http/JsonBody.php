<?php

declare(strict_types=1);

namespace Phpanta\Tool\Http;

use BackedEnum;

/**
 * The JsonBody class. A decoded JSON object, read by name rather than by literal.
 *
 * What an `array<string, mixed>` was, and what every reader of one had to write around it:
 *
 * ```php
 * is_string($body['permalink_url'] ?? null) ? $body['permalink_url'] : ''
 * ```
 *
 * That shape appeared nine times across the first API client's response readers, spelling the key
 * twice each time, and it is the kind of repetition {@link \Phpanta\Support\TypedItems}'s `guard()`
 * was written to end elsewhere.
 *
 * **The key is a {@link BackedEnum} and never a string**, which is the whole point rather than a
 * convenience: `FormField::of()` accepts either because a field name is sometimes an OAuth parameter
 * a client deliberately leaves as a literal, but a response key has no such case — every one is
 * named by a case of the client's own key enum. Accepting a string would reopen exactly the hole
 * those enums are written to close.
 *
 * **Two readers, not three.** An `enum()` would need a `class-string` argument to say what it
 * returns and would still hand back something the caller had to narrow, which reads worse than the
 * `tryFrom()` it would replace. So `Visibility::tryFrom($body->string(PostKey::Visibility))` stays
 * at its call site.
 *
 * The defaults — `''` and `0` — are what every caller was already collapsing an absent, a null and a
 * wrongly-typed value to. Nothing about a malformed response reads differently than it did.
 */
final readonly class JsonBody
{
    /**
     * Constructs an instance of {@link self}.
     *
     * @param array<string, mixed> $values The decoded object, as `json_decode(…, true)` left it.
     */
    public function __construct(private array $values) {}

    /**
     * The value under $key, or `''` where there is none that is a string.
     *
     * @param BackedEnum $key
     * @return string
     */
    public function string(BackedEnum $key): string
    {
        $value = $this->values[(string) $key->value] ?? null;

        return is_string($value) ? $value : '';
    }

    /**
     * The value under $key, or `0` where there is none that is a number.
     *
     * `is_numeric()` rather than `is_int()`, because a JSON number that arrived quoted is still the
     * number the provider meant.
     *
     * @param BackedEnum $key
     * @return int
     */
    public function int(BackedEnum $key): int
    {
        $value = $this->values[(string) $key->value] ?? null;

        return is_numeric($value) ? (int) $value : 0;
    }
}
