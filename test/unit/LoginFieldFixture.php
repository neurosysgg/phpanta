<?php

declare(strict_types=1);

namespace Phpanta\Test\Unit;

use Phpanta\Form\Autocompleting;
use Phpanta\Form\MaxLength;
use Phpanta\Form\Required;
use Phpanta\Form\Rule;
use Phpanta\Support\Collection;
use Phpanta\Text\Translatable;
use Phpanta\View\Html\Autocomplete;
use Phpanta\View\Html\InputType;

/**
 * The login recipe's form — see docs/login.md, which shows this enum as a site writes it.
 */
enum LoginFieldFixture: string implements Autocompleting
{
    case Name     = 'name';
    case Password = 'password';

    /**
     * @return Translatable
     */
    public function label(): Translatable
    {
        return match ($this) {
            self::Name     => LoginTextFixture::Name,
            self::Password => LoginTextFixture::Password,
        };
    }

    /**
     * @return InputType
     */
    public function type(): InputType
    {
        return match ($this) {
            self::Name     => InputType::Text,
            self::Password => InputType::Password,
        };
    }

    /**
     * @return Collection<Rule>
     */
    public function rules(): Collection
    {
        return new Collection(Rule::class)->with(new Required(), new MaxLength(128));
    }

    /**
     * @return Autocomplete
     */
    public function autocomplete(): Autocomplete
    {
        return match ($this) {
            self::Name     => Autocomplete::Username,
            self::Password => Autocomplete::CurrentPassword,
        };
    }
}
