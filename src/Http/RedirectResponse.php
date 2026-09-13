<?php

declare(strict_types=1);

namespace Phpanta\Http;

use NoDiscard;
use Phpanta\Support\Collection;

/**
 * The RedirectResponse class. Sends the visitor somewhere else.
 */
readonly class RedirectResponse implements Response
{
    /**
     * Constructs an instance of {@link self}.
     *
     * @param Location           $location Where to send the visitor. A {@link Location} rather than
     *                                     the string it was, so an address that type refuses throws
     *                                     where it is written — in the controller that chose it —
     *                                     and not after the controller has returned.
     * @param HttpStatusCode     $status   The HTTP status code; defaults to 303 See Other.
     * @param Collection<Header> $headers  Extra headers, sent ahead of the redirect — the language
     *                                     switch's cookie. The same parameter
     *                                     {@link PlainTextResponse} and {@link ViewResponse} take.
     */
    public function __construct(
        private Location       $location,
        private HttpStatusCode $status = HttpStatusCode::SeeOther,
        private Collection     $headers = new Collection(Header::class),
    ) {}

    /**
     * The status, the extra headers, then `Location`, and no body.
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
            $this->headers->with(new Header(ResponseHeader::Location, $this->location)),
        );
    }
}
