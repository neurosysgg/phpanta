<?php

declare(strict_types=1);

namespace Phpanta\Http;

use NoDiscard;
use Phpanta\Support\Collection;

/**
 * The PlainTextResponse class. A status and a sentence: a 405, a 503, the API's answers, a 401.
 */
readonly class PlainTextResponse implements Response
{
    /**
     * Constructs an instance of {@link self}.
     *
     * @param HttpStatusCode $status  The HTTP status code.
     * @param string         $body    The response body.
     * @param Collection<Header> $headers Extra headers to send, e.g. `Allow:` on a 405.
     */
    public function __construct(
        private HttpStatusCode $status,
        private string         $body,
        private Collection     $headers = new Collection(Header::class),
    ) {}

    /**
     * The status, `Content-Type: text/plain; charset=utf-8`, the extra headers, and the body.
     *
     * @param Request $request
     * @return Answer
     */
    #[NoDiscard(
        'answer() works out what would be sent and sends nothing; a call whose result goes nowhere '
        . 'answered no one',
    )]
    public function answer(Request $request): Answer
    {
        return new Answer(
            $this->status,
            new Collection(Header::class)->with(
                new Header(ResponseHeader::ContentType, MimeType::plainText()),
                ...$this->headers,
            ),
            new TextBody($this->body),
        );
    }
}
