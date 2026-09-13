<?php

declare(strict_types=1);

namespace Phpanta\Model\Api;

/**
 * The VerifiedRequest class. What {@link \Phpanta\Service\ApiGate} proved, and what it proved it
 * about.
 *
 * Three fields that are one decision, which is why they travel together: an envelope without the
 * bytes it was read from cannot be re-read by the action that owns the rest of them, and a body
 * without the envelope that named its digest has not been checked against anything.
 *
 * **It is a class rather than the tuple its predecessor returned**, and that is the ordinary reason
 * rather than a special one — `array{UpdateManifest, string}` needed a `#[BareArray]` excuse
 * explaining that a tuple is the one shape a homogeneous collection cannot hold, and the excuse was
 * true. Three named fields need no excuse at all, and `$verified->body` reads where
 * `[$manifest, $archive] = $verified` only reads correctly if you already know the answer.
 *
 * Nothing on it is nullable and nothing on it is optional: an instance existing at all means the
 * signature passed, which is why {@link \Phpanta\Http\Api\ApiHandler} takes no `Request` and can
 * act on every one of these fields without asking again.
 */
final readonly class VerifiedRequest
{
    /**
     * Constructs an instance of {@link self}.
     *
     * @param ApiEnvelope $envelope What the credential claimed about this request, all of it
     *                              checked against the request itself.
     * @param string $manifest The manifest's raw bytes, kept so the action named by the path can
     *                         read its own fields out of **the same signed document** rather than
     *                         out of a re-encoding of the part already parsed.
     * @param string $body The request body, exactly as it arrived and already matched against the
     *                     envelope's digest and size. `''` for a read.
     */
    public function __construct(
        public ApiEnvelope $envelope,
        public string      $manifest,
        public string      $body,
    ) {}
}
