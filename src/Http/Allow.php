<?php

declare(strict_types=1);

namespace Phpanta\Http;

use Phpanta\Support\Collection;

/**
 * The Allow class. Which methods a route accepts, sent with a 405.
 *
 * Built by filtering {@link HttpMethod::cases()} on {@link HttpMethod::isReadOnly()} rather than
 * written out, so the header cannot claim something the gate does not do. That derivation is the
 * whole value of the type: a hand-written `'Allow: GET, HEAD'` beside the `isReadOnly()` question
 * {@link \Phpanta\Support\MethodPolicy::ReadOnly} asks would be two statements of one rule, and
 * marking a method read-only would mean remembering to edit both.
 */
final readonly class Allow implements HeaderValue
{
    /** @param Collection<HttpMethod> $methods */
    private function __construct(private Collection $methods) {}

    /**
     * Every method that only reads — which on this site is every method the router answers.
     *
     * @return self
     */
    public static function readOnly(): self
    {
        return new self(
            new Collection(HttpMethod::class)
                ->with(...HttpMethod::cases())
                ->where(static fn(HttpMethod $method): bool => $method->isReadOnly()),
        );
    }

    /**
     * Exactly the methods named, for a refusal that is allowed to be specific.
     *
     * **The router must never call this**, and the reason is the whole argument on
     * {@link \Phpanta\Support\MethodPolicy}: a route naming its own set would make
     * `PUT /api/update/v1/patch` answer `Allow: GET, HEAD, POST`, and that `POST` is precisely the
     * fact `/api` exists to hide. {@link self::readOnly()} is what the router sends, always.
     *
     * Its one caller is {@link \Phpanta\Controller\ApiController}, past the signature check —
     * where the caller has proved possession of the private key, so there is nothing left to hide
     * and a 405 that does not say which method would work is merely unhelpful. That is the same
     * inversion every other diagnostic makes at that line.
     *
     * @param HttpMethod ...$methods
     * @return self
     */
    public static function of(HttpMethod ...$methods): self
    {
        return new self(new Collection(HttpMethod::class)->with(...$methods));
    }

    /**
     * Returns the header value: `GET, HEAD`.
     *
     * @return string
     */
    public function render(): string
    {
        return $this->methods->map(static fn(HttpMethod $method): string => $method->value)->join(', ');
    }
}
