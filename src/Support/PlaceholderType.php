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
     * `{name:path}`: one segment or several, with the slashes between them kept — a file's place
     * under a root, which is how the admin's `machine` service addresses one. Handed over decoded,
     * whole.
     *
     * **The one type that spans a slash, and so the one whose value is a walk rather than a key.**
     * Matching refuses an empty segment — `a//b` is no match — and {@link self::accepts()} refuses a
     * `.` or a `..` on the way out, but a segment sent as `%2e%2e` still decodes to one. Whatever
     * resolves the value against a filesystem therefore refuses dot segments itself, and asks where
     * the result landed, which {@link \Phpanta\Model\Machine\MachinePath} does.
     */
    case Path = 'path';

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
            self::Path    => '[^/]+(?:/[^/]+)*',
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
            self::Int                              => (int) $captured,
            self::Segment, self::Slug, self::Path  => rawurldecode($captured),
        };
    }

    /**
     * $value as it is written into an address — each segment `rawurlencode`d, so that this and
     * {@link self::decode()} are inverses.
     *
     * One call to `rawurlencode()` for every type but {@link self::Path}, whose slashes are what
     * separate its segments and so are kept, with each segment between them encoded.
     *
     * @param string $value A value {@link self::accepts()} has taken.
     * @return string
     */
    public function encode(string $value): string
    {
        if ($this !== self::Path) {
            return rawurlencode($value);
        }

        $segments = [];

        foreach (explode('/', $value) as $segment) {
            $segments[] = rawurlencode($segment);
        }

        return implode('/', $segments);
    }

    /**
     * Whether $value may fill a placeholder of this type — asked by {@link FillsPlaceholders::to()}.
     *
     * A {@link self::Segment} takes anything it can be a segment of, since it is encoded on the way
     * in — but not nothing, which writes an address its own route cannot match, and not `.` or `..`,
     * which encoding leaves as they are and a browser resolves as a dot-segment, to another page. A
     * {@link self::Path} takes what every one of its segments would take as a segment.
     *
     * @param string $value
     * @return bool
     */
    public function accepts(string $value): bool
    {
        if ($this === self::Segment) {
            return !in_array($value, ['', '.', '..'], true);
        }

        if ($this === self::Path) {
            foreach (explode('/', $value) as $segment) {
                if (!self::Segment->accepts($segment)) {
                    return false;
                }
            }

            return true;
        }

        return preg_match('#\A' . $this->pattern() . '\z#', $value) === 1;
    }
}
