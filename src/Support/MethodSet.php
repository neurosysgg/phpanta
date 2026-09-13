<?php

declare(strict_types=1);

namespace Phpanta\Support;

use Phpanta\Exception\RouteException;
use Phpanta\Http\Allow;
use Phpanta\Http\HttpMethod;

/**
 * The MethodSet class. The methods a route answers on, named outright — for a route that also
 * writes: a form that shows on `GET` and is sent by `POST`.
 *
 * Its 405 names exactly this set, because nothing about such a route is hidden: the page with the
 * form already said it accepts a `POST`. The route that *must* hide its set — the API — is
 * {@link MethodPolicy::Delegated}, and never a set; see that enum.
 *
 * **`GET` brings `HEAD` with it.** RFC 9110 §9.3.2 has a server answer a `HEAD` wherever it
 * answers a `GET`, and a set that forgot it would 405 every link checker and every `curl -I`. The
 * set is kept in the enum's own order, each method once, so its `Allow` reads the same however the
 * route listed it.
 */
final readonly class MethodSet implements MethodGate
{
    /**
     * @param Collection<HttpMethod> $methods
     */
    private function __construct(private Collection $methods) {}

    /**
     * The set of $methods, and `HEAD` if `GET` is among them.
     *
     * @param HttpMethod ...$methods
     * @return self
     * @throws RouteException if no method is named: a route no method reaches is a route to delete.
     */
    public static function of(HttpMethod ...$methods): self
    {
        if ($methods === []) {
            throw new RouteException(
                'A method set names at least one method: a route no method reaches is not a route.',
            );
        }

        $set = new Collection(HttpMethod::class);

        foreach (HttpMethod::cases() as $method) {
            $implied = $method === HttpMethod::Head && in_array(HttpMethod::Get, $methods, true);

            if ($implied || in_array($method, $methods, true)) {
                $set = $set->with($method);
            }
        }

        return new self($set);
    }

    /**
     * @param HttpMethod|null $method
     * @return bool
     */
    public function accepts(?HttpMethod $method): bool
    {
        return $method !== null && $this->methods->first(static fn(HttpMethod $in): bool => $in === $method) !== null;
    }

    /**
     * @return Allow
     */
    public function allow(): Allow
    {
        return Allow::of(...$this->methods);
    }
}
