<?php

declare(strict_types=1);

namespace Phpanta\Http;

use NoDiscard;
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
     * Every method that only reads — which is every method a read-only route answers.
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
     * **The router calls this only for a {@link \Phpanta\Support\MethodSet}**, a route whose form
     * already shows what it takes. A route under a {@link \Phpanta\Support\MethodPolicy} is refused
     * with {@link self::readOnly()}, always, and the reason is the whole argument on that enum: an
     * admin route naming its own set would make `PUT /admin/update/v1/patch` answer
     * `Allow: GET, HEAD, POST` to a caller nobody has verified, and that `POST` says which depth is
     * an action — precisely what the admin keeps from a stranger.
     *
     * Its other caller is {@link \Phpanta\Controller\ApiController}, past the signature check —
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
     * This set, and $methods after it — what an `OPTIONS` names: the route's own methods, then the
     * one it was asked by.
     *
     * @param HttpMethod ...$methods
     * @return self
     */
    #[NoDiscard('with() copies rather than adds, so a call whose result goes nowhere names nothing')]
    public function with(HttpMethod ...$methods): self
    {
        return new self($this->methods->with(...$methods));
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
