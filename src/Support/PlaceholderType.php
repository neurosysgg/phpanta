<?php

declare(strict_types=1);

namespace Phpanta\Support;

use Phpanta\Exception\RouteException;

/**
 * The PlaceholderType enum. What one `{placeholder}` in a route's pattern matches, and what the
 * controller is handed for it.
 *
 * Written after a colon — `{id:int}`, `{tag:slug}` — or not at all, for {@link self::Segment}. The
 * type is the route's first check on a value, not its last: a segment that is not an `int` is simply
 * no match, so it reaches the next route or the 404, and never a controller that would have had to
 * refuse it. {@link FillsPlaceholders::to()} asks the same question on the way out, so a link to a
 * value the route would not match is refused where it is written.
 */
enum PlaceholderType: string
{
    /** `{name}`: one whole segment, whatever is in it, handed over decoded. */
    case Segment = 'segment';

    /**
     * `{name:int}`: digits with no leading zero, at most eighteen of them, handed over as an int.
     *
     * The bound is what keeps the int honest: nineteen digits can exceed `PHP_INT_MAX`, and `(int)`
     * would clamp the value to it in silence — a request for one item answered with another.
     */
    case Int = 'int';

    /** `{name:slug}`: lower-case letters and digits, in words joined by single hyphens. */
    case Slug = 'slug';

    /**
     * The type a placeholder names after its colon — `''` for none, which is {@link self::Segment}.
     *
     * @param string $name
     * @return self
     * @throws RouteException for a type that is not one of these, where the pattern is written.
     */
    public static function named(string $name): self
    {
        return $name === ''
            ? self::Segment
            : (self::tryFrom($name) ?? throw new RouteException(sprintf(
                "'%s' is not a placeholder type; a placeholder is {name}, {name:int} or {name:slug}.",
                $name,
            )));
    }

    /**
     * The expression one such segment must match, without delimiters or anchors.
     *
     * @return string
     */
    public function pattern(): string
    {
        return match ($this) {
            self::Segment => '[^/]+',
            self::Int     => '(?:0|[1-9][0-9]{0,17})',
            self::Slug    => '[a-z0-9]+(?:-[a-z0-9]+)*',
        };
    }

    /**
     * What the controller is handed for a segment this type matched.
     *
     * @param string $captured The segment as it arrived.
     * @return string|int
     */
    public function decode(string $captured): string|int
    {
        return match ($this) {
            self::Int                  => (int) $captured,
            self::Segment, self::Slug  => rawurldecode($captured),
        };
    }

    /**
     * Whether $value may fill a placeholder of this type — asked by {@link FillsPlaceholders::to()}.
     *
     * A {@link self::Segment} takes anything, since it is encoded on the way in.
     *
     * @param string $value
     * @return bool
     */
    public function accepts(string $value): bool
    {
        return $this === self::Segment || preg_match('#\A' . $this->pattern() . '\z#', $value) === 1;
    }
}
