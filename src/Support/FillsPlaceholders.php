<?php

declare(strict_types=1);

namespace Phpanta\Support;

use Phpanta\App;
use Phpanta\Exception\RouteException;
use Phpanta\Text\Language;
use Phpanta\Text\LocalisedAddress;

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
     * A case `'/posts/{slug}'` fills as `->to('hello')`, which is `/posts/hello`; a case `'/'` as
     * `->to()`, which is `/`.
     *
     * **Refusing the wrong number of values is what the method is for.** A concatenation cannot
     * make that check — `'/posts/' . $slug . '/'` is a perfectly good string and a URL that
     * matches nothing — so this is the one thing typing the paths buys that naming them does not.
     * It throws rather than returning null because a caller cannot do anything useful with the
     * answer: the page is already being rendered, and a link to nowhere is not a fallback.
     *
     * Each value is `rawurlencode`d. That is a no-op for the usual slug — letters, digits and
     * hyphens are nothing the encoding cares about — and it is the right answer for the first
     * value that is not, rather than a `%` appearing in a path segment
     * where the router will read it as content.
     *
     * @param string|int ...$values One per placeholder, left to right.
     * @return string
     * @throws RouteException if the count does not match the placeholders, or a value is not what
     *                        its placeholder's type takes.
     */
    public function to(string|int ...$values): string
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
        // `/posts/hello/hello` — a URL that is well formed, matches a route, and is the wrong page.
        //
        // No null check on the result, the way Route::matches() does not check its own: the pattern
        // is a constant and the subject is a string, so there is no failure for one to report.
        //
        // A typed placeholder is asked whether it takes the value before it is written in, so a
        // link to `{id:int}` with `abc` is refused here, where it was written — not a 404 later,
        // for a visitor who followed it.
        return preg_replace_callback(
            Route::PLACEHOLDER_PATTERN,
            static function (array $placeholder) use (&$values): string {
                $value = (string) array_shift($values);
                $type  = PlaceholderType::named($placeholder[2] ?? '');

                if (!$type->accepts($value)) {
                    throw new RouteException(sprintf(
                        "'%s' is not a %s, which {%s} takes.",
                        $value,
                        $type->value,
                        $placeholder[1],
                    ));
                }

                return rawurlencode($value);
            },
            $this->value,
        );
    }

    /**
     * This path, as a link that leads to the page in the language it is rendered in.
     *
     * An `href` value, resolved like translated text in the nearest `lang`: `/rules.de.html` on the
     * German page of an app whose languages have addresses of their own, and `/rules` wherever they
     * share one. See {@link \Phpanta\Text\LanguageAddresses}.
     *
     * @param string|int ...$values One per placeholder, as {@link self::to()} takes them.
     * @return LocalisedAddress
     * @throws RouteException as {@link self::to()} does.
     */
    public function inEachLanguage(string|int ...$values): LocalisedAddress
    {
        return new LocalisedAddress($this->to(...$values));
    }

    /**
     * This path in $language — for the one link that names a language rather than following the
     * page it is on. A language switch is one, and so is an alternate link with an `hreflang`.
     *
     * @param Language $language
     * @param string|int ...$values One per placeholder, as {@link self::to()} takes them.
     * @return string
     * @throws RouteException as {@link self::to()} does.
     */
    public function inLanguage(Language $language, string|int ...$values): string
    {
        return App::current()->languageAddresses()->address($this->to(...$values), $language);
    }
}
