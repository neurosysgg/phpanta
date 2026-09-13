<?php

declare(strict_types=1);

namespace Phpanta\Http;

/**
 * The SignedChallenge class. The `WWW-Authenticate` value the admin answers an unsigned request for
 * data with.
 *
 * A `401` must carry a challenge, and this one names {@link AuthScheme::NS1} and nothing else: the
 * scheme has no realm and no parameters, because what it asks for is a signature over the request
 * rather than a secret typed into a prompt. A browser has no prompt for a scheme it does not know,
 * so it never shows one; the signing commands read the `401` as the refusal it is. See
 * {@link BasicChallenge} for the other gates' challenge, whose scheme this shares an enum with.
 */
final readonly class SignedChallenge implements HeaderValue
{
    /**
     * @return string
     */
    public function render(): string
    {
        return AuthScheme::NS1->value;
    }
}
