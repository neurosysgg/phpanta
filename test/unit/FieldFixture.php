<?php

declare(strict_types=1);

namespace Phpanta\Test\Unit;

use Phpanta\Form\Autocompleting;
use Phpanta\Form\Email;
use Phpanta\Form\MaxLength;
use Phpanta\Form\OneOf;
use Phpanta\Form\Required;
use Phpanta\Form\Rule;
use Phpanta\Form\WholeNumber;
use Phpanta\Support\Collection;
use Phpanta\Text\Translatable;
use Phpanta\Text\Translation;
use Phpanta\View\Html\Autocomplete;
use Phpanta\View\Html\InputType;

/**
 * A form for the tests, the shape a site's own field enum takes: one field of each kind a form
 * renders differently.
 */
enum FieldFixture: string implements Autocompleting
{
    case Name     = 'name';
    case Email    = 'email';
    case Password = 'password';
    case Age      = 'age';
    case Colour   = 'colour';
    case Agree    = 'agree';
    case Note     = 'a note';
    case Ref      = 'ref';

    /**
     * @return Translatable
     */
    public function label(): Translatable
    {
        return match ($this) {
            self::Name     => new Translation(en: 'Your name', de: 'Dein Name'),
            self::Email    => new Translation(en: 'Email', de: 'E-Mail'),
            self::Password => new Translation(en: 'Password', de: 'Passwort'),
            self::Age      => new Translation(en: 'Age', de: 'Alter'),
            self::Colour   => new Translation(en: 'Colour', de: 'Farbe'),
            self::Agree    => new Translation(en: 'I agree', de: 'Einverstanden'),
            self::Note     => new Translation(en: 'Note', de: 'Notiz'),
            self::Ref      => new Translation(en: 'Reference', de: 'Referenz'),
        };
    }

    /**
     * @return InputType
     */
    public function type(): InputType
    {
        return match ($this) {
            self::Email    => InputType::Email,
            self::Password => InputType::Password,
            self::Age      => InputType::Number,
            self::Agree    => InputType::Checkbox,
            self::Ref      => InputType::Hidden,
            default        => InputType::Text,
        };
    }

    /**
     * @return Collection<Rule>
     */
    public function rules(): Collection
    {
        $rules = new Collection(Rule::class);

        return match ($this) {
            self::Name     => $rules->with(new Required(), new MaxLength(5)),
            self::Email    => $rules->with(new Required(), new Email()),
            self::Password => $rules->with(new Required(), new MaxLength(64)),
            self::Age      => $rules->with(new WholeNumber()),
            self::Colour   => $rules->with(new OneOf(ChoiceFixture::class)),
            self::Agree    => $rules->with(new Required()),
            default        => $rules,
        };
    }

    /**
     * @return Autocomplete|null
     */
    public function autocomplete(): ?Autocomplete
    {
        return match ($this) {
            self::Email    => Autocomplete::Email,
            self::Password => Autocomplete::CurrentPassword,
            default        => null,
        };
    }
}
