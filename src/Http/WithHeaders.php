<?php

declare(strict_types=1);

namespace Phpanta\Http;

use NoDiscard;
use Phpanta\Support\Collection;

/**
 * The WithHeaders class. A response, answered as it would be, with more headers after its own.
 *
 * The usual shape of a layer that works *after* the controller — see
 * {@link \Phpanta\Controller\Layer}: it takes what the controller answered and returns it wrapped,
 * so what the response decided — its status, its validator, its body, a file's bytes — is kept
 * exactly as it was, and only the headers grow.
 */
final readonly class WithHeaders implements Response
{
    /**
     * Constructs an instance of {@link self}.
     *
     * @param Response           $response
     * @param Collection<Header> $headers  Sent after the response's own, in this order.
     */
    public function __construct(
        private Response   $response,
        private Collection $headers,
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
        return $this->response->answer($request)->withHeaders($this->headers);
    }
}
