<?php

declare(strict_types=1);

namespace Phpanta\Http;

/**
 * The ETag class. A validator for a response body — a fingerprint the browser hands back to ask
 * whether the copy it already holds is still current.
 *
 * The quoting is why this is a type. `ETag: abc` and `ETag: "abc"` are different headers to the
 * spec — the quotes are part of the grammar, not decoration — and a bare one is the kind of thing
 * that works against every browser that is lenient about it and fails against the one that is not.
 * Nothing builds one with a `'"' . hash(…) . '"'` at a call site; the quotes cannot be forgotten
 * because nothing else can produce one.
 *
 * The hash algorithm lives here for the same reason: it is a fact about what an ETag on this site
 * *is*, and the comparison in `ViewResponse` only works because both ends of it come through this
 * class.
 */
final readonly class ETag implements HeaderValue
{
    /**
     * How a body is fingerprinted.
     *
     * xxh128 rather than sha256, and deliberately: nothing here is a security claim. The value is
     * only ever compared against one this same code sent a moment ago — never against one an
     * attacker chose — while the hash runs over every rendered page. A collision at 1 in 2^128
     * would serve a stale document; that is not a threat model, it is a rounding error.
     */
    private const string ALGORITHM = 'xxh128';

    /**
     * Constructs an instance of {@link self}.
     *
     * @param string $fingerprint
     */
    private function __construct(private string $fingerprint) {}

    /**
     * The validator for a body: a hash of exactly the bytes about to be sent.
     *
     * @param string $body
     * @return self
     */
    public static function forBody(string $body): self
    {
        return new self(hash(self::ALGORITHM, $body));
    }

    /**
     * True if $validator is the one this ETag would have handed out.
     *
     * Compared as the rendered form, quotes included, because that is what a browser echoes back in
     * `If-None-Match`. A weak validator (`W/"…"`) is not equal to a strong one and is not accepted:
     * this site never sends one, so one arriving is not our ETag.
     *
     * @param string $validator
     * @return bool
     */
    public function matches(string $validator): bool
    {
        return $validator === $this->render();
    }

    /**
     * Returns the header value: the fingerprint, quoted as the grammar requires.
     *
     * @return string
     */
    public function render(): string
    {
        return '"' . $this->fingerprint . '"';
    }
}
