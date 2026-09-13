<?php

declare(strict_types=1);

namespace Phpanta\Support;

use Phpanta\Http\Allow;
use Phpanta\Http\HttpMethod;

/**
 * The MethodGate interface. What a route says about the methods it answers on.
 *
 * Two kinds: {@link MethodPolicy}, which says *who* decides — the router, for a page; the controller,
 * for the one route that must never name its own set — and {@link MethodSet}, which names the set
 * outright, for a route that also writes and has nothing to hide about it.
 */
interface MethodGate
{
    /**
     * Whether a request by $method reaches the route's controller. Null — a verb nobody knows — never
     * does unless the controller is the one deciding.
     *
     * @param HttpMethod|null $method
     * @return bool
     */
    public function accepts(?HttpMethod $method): bool;

    /**
     * The methods this route's refusal names: what its 405's `Allow` says, and its `OPTIONS`.
     *
     * @return Allow
     */
    public function allow(): Allow;
}
