<?php

declare(strict_types=1);

namespace Phpanta\Http;

use Phpanta\Support\Collection;

/**
 * The RedirectResponse class. Issues an HTTP redirect to the given location and terminates.
 */
readonly class RedirectResponse implements Response
{
    /**
     * Constructs an instance of {@link self}.
     *
     * @param Location           $location Where to send the visitor. A {@link Location} rather than
     *                                     the string it was, so an address that type refuses throws
     *                                     where it is written — in the controller that chose it —
     *                                     and not in send(), after the controller has returned.
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
     * Sends the redirect and ends the request.
     *
     * `never` rather than `void`, and that is the whole declaration: the caller cannot have code
     * after this, and the engine knows it. It carried a `#[JetBrains\PhpStorm\NoReturn]` alongside
     * for a while, from a package this project does not require and does not have — so it was an
     * undefined class in the one place a reader looks for a type, restating what the native return
     * type already says. {@link \Phpanta\Service\Auth::challenge()} has always been plain `never`.
     *
     * @param Request $request
     * @return never
     */
    public function send(Request $request): never
    {
        foreach ($this->headers as $header) {
            header($header->line());
        }

        header(new Header(ResponseHeader::Location, $this->location)->line(), true, $this->status->value);
        exit;
    }
}
