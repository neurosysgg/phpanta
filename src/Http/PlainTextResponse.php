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
     * A refusal: a sentence in the request's language that no cache may keep.
     *
     * The router's 400, 405 and 413 are put into the caller's language, so one URL answers two
     * callers with two bodies — and a 405 is cacheable by default, so a cache that was not told would
     * hand one caller the other's. Nothing about a refusal is worth keeping, so it says
     * `no-store, private` rather than naming everything it varies on.
     *
     * @param HttpStatusCode $status
     * @param string         $body
     * @param Header         ...$headers Extra headers, e.g. `Allow:` on a 405.
     * @return self
     */
    public static function refusing(HttpStatusCode $status, string $body, Header ...$headers): self
    {
        return new self(
            $status,
            $body,
            new Collection(Header::class)->with(
                new Header(ResponseHeader::CacheControl, CacheControl::doNotStore()),
                ...$headers,
            ),
        );
    }

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
