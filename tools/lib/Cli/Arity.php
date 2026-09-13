<?php

declare(strict_types=1);

namespace Phpanta\Tool\Cli;

/**
 * The Arity class. How many operands a command takes.
 *
 * Declared by every {@link Command} and checked by {@link Input::parse()} before the command runs,
 * for the reason options are declared: a word nobody asked for is refused rather than dropped. The
 * case that made it necessary was `push-update -n` — a short flag nothing declared, taken as an
 * operand, ignored by a command that reads none, and so a real push where a dry run was meant.
 */
final readonly class Arity
{
    /**
     * Constructs an instance of {@link self}.
     *
     * @param int      $least The fewest operands the command takes.
     * @param int|null $most  The most, or null where there is no most.
     */
    private function __construct(public int $least, public ?int $most) {}

    /**
     * A command that takes no operands at all — only options.
     *
     * @return self
     */
    public static function none(): self
    {
        return new self(0, 0);
    }

    /**
     * A command that takes exactly $count operands.
     *
     * @param int $count
     * @return self
     */
    public static function exactly(int $count): self
    {
        return new self($count, $count);
    }

    /**
     * A command that takes $count operands or more.
     *
     * @param int $count
     * @return self
     */
    public static function atLeast(int $count): self
    {
        return new self($count, null);
    }

    /**
     * A command that takes from $least to $most operands.
     *
     * @param int $least
     * @param int $most
     * @return self
     */
    public static function between(int $least, int $most): self
    {
        return new self($least, $most);
    }

    /**
     * Whether $count operands is a count this command takes.
     *
     * @param int $count
     * @return bool
     */
    public function allows(int $count): bool
    {
        return $count >= $this->least && ($this->most === null || $count <= $this->most);
    }

    /**
     * What is wrong with $count, in words, for a count {@link self::allows()} refuses.
     *
     * @param int $count
     * @return string
     */
    public function refusal(int $count): string
    {
        $wanted = match (true) {
            $this->most === 0            => 'no operands',
            $this->most === $this->least => sprintf('exactly %s', self::operands($this->least)),
            $this->most === null         => sprintf('at least %s', self::operands($this->least)),
            default                      => sprintf('%d to %d operands', $this->least, $this->most),
        };

        return sprintf('takes %s, and %d %s given', $wanted, $count, $count === 1 ? 'was' : 'were');
    }

    /**
     * `1 operand`, `2 operands`.
     *
     * @param int $count
     * @return string
     */
    private static function operands(int $count): string
    {
        return sprintf('%d operand%s', $count, $count === 1 ? '' : 's');
    }
}
