<?php

declare(strict_types=1);

namespace Phpanta\Http;

use NoDiscard;
use Phpanta\Support\Collection;

/**
 * The EmptyResponse class. A status and headers, and no body at all — not even a `Content-Type`
 * describing one.
 *
 * What an `OPTIONS` is answered with — a 204 and an `Allow` — and a CORS preflight: an answer about
 * the resource rather than a representation of it.
 */
readonly class EmptyResponse implements Response
{
    /**
     * Constructs an instance of {@link self}.
     *
     * @param HttpStatusCode     $status
     * @param Collection<Header> $headers
     */
    public function __construct(
        private HttpStatusCode $status = HttpStatusCode::NoContent,
        private Collection     $headers = new Collection(Header::class),
    ) {}

    /**
     * @param Request $request
     * @return Answer
     */
    #[NoDiscard(
        'answer() works out what would be sent and sends nothing; a call whose result goes nowhere '
        . 'answered no one',
    )]
    public function answer(Request $request): Answer
    {
        return new Answer($this->status, $this->headers);
    }
}
