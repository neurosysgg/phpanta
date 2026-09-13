<?php

declare(strict_types=1);

namespace Phpanta\Support;

use Phpanta\Exception\RouteException;

/**
 * The FillsPlaceholders trait. {@link Path::to()}, written once for every vocabulary of paths.
 *
 * A trait rather than a method on each enum because the method is the one thing typing the paths
 * buys that naming them does not — refusing the wrong number of values — and two copies of it would
 * be free to disagree about that.
 */
trait FillsPlaceholders
{
    /**
     * This path with its placeholders filled, in declaration order.
     *
     * `SitePath::Release->to($slug)` is `/releases/ill`; `SitePath::Home->to()` is `/`.
     *
     * **Refusing the wrong number of values is what the method is for.** A concatenation cannot
     * make that check — `'/releases/' . $slug . '/'` is a perfectly good string and a URL that
     * matches nothing — so this is the one thing typing the paths buys that naming them does not.
     * It throws rather than returning null because a caller cannot do anything useful with the
     * answer: the page is already being rendered, and a link to nowhere is not a fallback.
     *
     * Each value is `rawurlencode`d. That is a no-op for every slug, format and label in `data/`
     * today — all of them match patterns narrower than the encoding cares about — and it is the
     * right answer for the first one that is not, rather than a `%` appearing in a path segment
     * where the router will read it as content.
     *
     * @param string ...$values One per placeholder, left to right.
     * @return string
     * @throws RouteException if the count does not match the placeholders.
     */
    public function to(string ...$values): string
    {
        $expected = preg_match_all(Route::PLACEHOLDER_PATTERN, $this->value);

        // Counted before anything is substituted, so both mistakes are one message. Too few would
        // otherwise leave an empty path segment and too many would go unnoticed entirely, and both
        // mean the same thing: this call site and this pattern disagree about the route's shape.
        if ($expected !== count($values)) {
            throw new RouteException(sprintf(
                "%s::%s takes %d value(s) for '%s', got %d.",
                substr(self::class, (int) strrpos(self::class, '\\') + 1),
                $this->name,
                $expected,
                $this->value,
                count($values),
            ));
        }

        // `function` and `use (&…)`, not an arrow function: `fn()` captures by value, so each call
        // would shift a fresh copy and every placeholder would be filled with the first value.
        // `/releases/ill/ill` — a URL that is well formed, matches a route, and is the wrong page.
        //
        // No null check on the result, the way Route::matches() does not check its own: the pattern
        // is a constant and the subject is a string, so there is no failure for one to report.
        return preg_replace_callback(
            Route::PLACEHOLDER_PATTERN,
            static function () use (&$values): string {
                return rawurlencode((string) array_shift($values));
            },
            $this->value,
        );
    }
}
