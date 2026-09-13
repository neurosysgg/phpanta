<?php

declare(strict_types=1);

namespace Phpanta\Tool\Php;

/**
 * The Argument class. One item inside a call's parentheses.
 *
 * Carries three things the emitted entry needs and a bare expression could not: the parameter
 * **name**, because `data/releases.php` is written with named arguments; a trailing **comment**,
 * which is the column naming the file whose share id is wanted; and whether the whole thing is
 * **commented out**, which is how the half-state is written down — an `embed:` that cannot exist
 * until the track is uploaded is a line to uncomment, not a line to write.
 *
 * A bare comment line is this with no value at all, so `Call` has one kind of item rather than two.
 */
final readonly class Argument
{
    /**
     * Constructs an instance of {@link self}.
     *
     * @param Expression|null $value      Null for a bare comment line.
     * @param string|null     $name       The parameter name, for a named argument.
     * @param string|null     $comment    Rendered after the value, aligned with its siblings.
     * @param bool            $commentedOut Whether the line itself is commented out.
     */
    public function __construct(
        public ?Expression $value,
        public ?string $name = null,
        public ?string $comment = null,
        public bool $commentedOut = false,
    ) {}

    /**
     * A bare `// …` line between arguments.
     *
     * @param string $comment
     * @return self
     */
    public static function comment(string $comment): self
    {
        return new self(null, comment: $comment);
    }

    /**
     * An argument written out but commented, for a fact that does not exist yet.
     *
     * @param Expression $value
     * @param string     $name
     * @return self
     */
    public static function pending(Expression $value, string $name): self
    {
        return new self($value, $name, commentedOut: true);
    }
}
