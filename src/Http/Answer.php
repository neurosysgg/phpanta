<?php

declare(strict_types=1);

namespace Phpanta\Http;

use NoDiscard;
use Phpanta\Exception\SecurityPolicyException;
use Phpanta\Support\Collection;

/**
 * The Answer class. What goes on the wire: a status, the headers in the order they are sent, and a
 * body.
 *
 * **A {@link Response} is what a controller says; an answer is what that comes to for one
 * request.** A page's response is the same object whoever asks, and its answer is a 200 for one
 * visitor and a 304 for the next, in German for a third — which is why {@link Response::answer()}
 * takes the request, and why this is a second type rather than more of the first.
 *
 * **Nothing is sent until {@link self::send()}, and nothing else sends.** Every response, every
 * gate and the router return; {@link \Phpanta\App::handle()} answers a request with one of these,
 * and {@link \Phpanta\App::run()} is the one caller of `send()`. That is what lets a test assert
 * on a 401 or a 303 exactly as a browser would receive it, in-process: the refusal that used to end
 * the process is now a value.
 */
final readonly class Answer
{
    /**
     * Constructs an instance of {@link self}.
     *
     * @param HttpStatusCode     $status
     * @param Collection<Header> $headers In the order they go out.
     * @param Body               $body
     */
    public function __construct(
        private HttpStatusCode $status,
        private Collection     $headers = new Collection(Header::class),
        private Body           $body = new TextBody(),
    ) {}

    /**
     * @return HttpStatusCode
     */
    public function status(): HttpStatusCode
    {
        return $this->status;
    }

    /**
     * @return Collection<Header>
     */
    public function headers(): Collection
    {
        return $this->headers;
    }

    /**
     * The first header named $name, or null where there is none.
     *
     * @param HeaderName $name
     * @return Header|null
     */
    #[NoDiscard('header() only looks; a call whose result goes nowhere asked nothing')]
    public function header(HeaderName $name): ?Header
    {
        return $this->headers->first(static fn(Header $header): bool => $header->name === $name);
    }

    /**
     * The body, whole — see {@link Body::contents()} for why nothing on the way to the wire asks.
     *
     * @return string
     */
    public function body(): string
    {
        return $this->body->contents();
    }

    /**
     * This answer with $headers ahead of its own.
     *
     * What {@link \Phpanta\App::handle()} puts the security headers on every answer with, so they
     * lead on the wire exactly as they did when they were sent before anything else.
     *
     * **A name both carry is refused.** {@link self::send()} replaces with the first header of a name
     * and appends the rest, so the answer's own would go out beside the leading one: two
     * `Cross-Origin-Opener-Policy` values are one list to a browser, which parses as neither and is
     * dropped, and a second `Content-Security-Policy` quietly narrows the first. What leads every
     * answer is the app's to set, and a response that sets it too is written wrong.
     *
     * @param Collection<Header> $headers
     * @return self
     * @throws SecurityPolicyException naming a header both carry.
     */
    #[NoDiscard('withHeadersFirst() copies rather than adds, so a call whose result goes nowhere sends nothing')]
    public function withHeadersFirst(Collection $headers): self
    {
        foreach ($headers as $leading) {
            $name = strtolower($leading->name->headerName());

            $clash = $this->headers->first(
                static fn(Header $own): bool => strtolower($own->name->headerName()) === $name,
            );

            if ($clash !== null) {
                throw new SecurityPolicyException(sprintf(
                    '%s leads every answer, and this one carries its own as well; the two would go out as'
                    . ' one list a browser reads as neither. Set it where the app sets it, not on a response.',
                    $leading->name->headerName(),
                ));
            }
        }

        return new self($this->status, $headers->with(...$this->headers), $this->body);
    }

    /**
     * This answer with $headers after its own — what {@link WithHeaders} adds to a response a layer
     * stands behind.
     *
     * @param Collection<Header> $headers
     * @return self
     */
    #[NoDiscard('withHeaders() copies rather than adds, so a call whose result goes nowhere sends nothing')]
    public function withHeaders(Collection $headers): self
    {
        return new self($this->status, $this->headers->with(...$headers), $this->body);
    }

    /**
     * Sends the answer: the status, the headers, the body. The one place anything is.
     *
     * **The status goes first**, because PHP rewrites it on the way past: a `Location` header sent
     * while the status is still 200 makes it a 302, whatever the answer said.
     *
     * **The first header of each name replaces, and any after it append.** Replacing is what makes
     * the security headers {@link \Phpanta\App::run()} sends early harmless to send again —
     * identical values, one of each on the wire. Appending after the first is what keeps a second
     * header of a name that may repeat, `Set-Cookie` above all, from quietly eating the first.
     *
     * @return void
     */
    public function send(): void
    {
        http_response_code($this->status->value);

        $sent = [];

        foreach ($this->headers as $header) {
            $name = strtolower($header->name->headerName());

            header($header->line(), !isset($sent[$name]));
            $sent[$name] = true;
        }

        $this->body->emit();
    }
}
