<?php

declare(strict_types=1);

namespace Phpanta\Form;

use BackedEnum;
use NoDiscard;
use Phpanta\Exception\FormException;
use Phpanta\Support\Collection;
use Phpanta\Text\FrameworkText;
use Phpanta\Text\Translatable;

/**
 * The OneOf rule. The field names one case of a backed enum, by its backing value — and the field
 * is a `<select>` of those cases, which {@link Form::render()} writes from {@link self::cases()}.
 *
 * An int-backed enum works too: the value is compared as text with each case's, since what a form
 * sends is text, and `tryFrom()` on an int enum would be handed a string it refuses to take.
 */
final readonly class OneOf implements Rule
{
    /**
     * Constructs an instance of {@link self}.
     *
     * @param class-string<BackedEnum> $enum The choices.
     * @throws FormException if $enum is not a backed enum.
     */
    public function __construct(private string $enum)
    {
        if (!is_subclass_of($enum, BackedEnum::class)) {
            throw new FormException(sprintf('%s is not a backed enum, so it has no values to choose from.', $enum));
        }
    }

    /**
     * The choices, in declaration order.
     *
     * @return Collection<BackedEnum>
     */
    #[NoDiscard('cases() only reads; a call whose result goes nowhere read nothing')]
    public function cases(): Collection
    {
        return new Collection(BackedEnum::class)->with(...$this->enum::cases());
    }

    /**
     * @param string $value
     * @return Translatable|null
     */
    #[NoDiscard('check() only asks; a call whose result goes nowhere checked nothing')]
    public function check(string $value): ?Translatable
    {
        if ($value === '') {
            return null;
        }

        $chosen = $this->cases()->first(static fn(BackedEnum $case): bool => (string) $case->value === $value);

        return $chosen === null ? FrameworkText::FieldNotAChoice : null;
    }
}
