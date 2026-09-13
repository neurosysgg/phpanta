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
 * *is*, and {@link self::matches()} can read a validator back only because this class wrote it.
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
     * One entity-tag in an `If-None-Match` list: an optional weak prefix, then the opaque tag in its
     * quotes, captured without them.
     *
     * Found rather than split on commas, because a comma is a legal byte inside the quotes.
     */
    private const string ENTITY_TAG = '#(?:W/)?"([^"]*)"#';

    /**
     * What a compressing module appends inside the quotes of every body it encodes: `-gzip` from
     * Apache's mod_deflate, `-br` from mod_brotli, and `-deflate` where a configuration asks for it.
     */
    private const string CODING_SUFFIX = '/-(?:gzip|br|deflate)\z/';

    /** `If-None-Match: *` — whatever the current representation is. */
    private const string ANY = '*';

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
     * True if $ifNoneMatch names this ETag.
     *
     * Read as the list the header is, and compared the way RFC 9110 §13.1.2 compares `If-None-Match`
     * — **weakly**:
     *
     * - `*` matches, because there is a current representation whenever this is asked;
     * - any entry of a comma-separated list may be the one, because a cache holding several copies
     *   sends every validator it has;
     * - a `W/` prefix is dropped rather than refused, because weak comparison ignores it, and a
     *   proxy that changes a body on the way — compressing it, say — is required to add one;
     * - a `-gzip`, `-br` or `-deflate` at the end of the tag is dropped. Apache's compression
     *   modules append one to the `ETag` of every body they encode, so the tag a browser holds is
     *   `"…-gzip"` and never `"…"`. Compared verbatim, as it once was, a compressed page never
     *   validated: every return visit fetched the whole page again, and nothing anywhere said why.
     *   The fingerprint is hex, so no tag this class writes can end in one of them.
     *
     * @param string $ifNoneMatch The raw `If-None-Match` value, or `''` if the request carried none.
     * @return bool
     */
    public function matches(string $ifNoneMatch): bool
    {
        if (trim($ifNoneMatch) === self::ANY) {
            return true;
        }

        preg_match_all(self::ENTITY_TAG, $ifNoneMatch, $tags);

        foreach ($tags[1] as $tag) {
            if (preg_replace(self::CODING_SUFFIX, '', $tag) === $this->fingerprint) {
                return true;
            }
        }

        return false;
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
