<?php

declare(strict_types=1);

namespace Phpanta\Test\Unit;

use Phpanta\Form\Field;
use Phpanta\Form\Rule;
use Phpanta\Support\Collection;
use Phpanta\Text\Translatable;
use Phpanta\Text\Verbatim;
use Phpanta\View\Html\InputType;

/**
 * A second form's fields, for the two mistakes a form refuses: a field asked of the wrong form's
 * submission ({@link self::Name}, spelled like {@link FieldFixture::Name}), and a field named like
 * the form token ({@link self::Token}).
 */
enum OtherFieldFixture: string implements Field
{
    case Name  = 'name';
    case Token = '_csrf';

    /**
     * @return Translatable
     */
    public function label(): Translatable
    {
        return new Verbatim($this->name);
    }

    /**
     * @return InputType
     */
    public function type(): InputType
    {
        return InputType::Text;
    }

    /**
     * @return Collection<Rule>
     */
    public function rules(): Collection
    {
        return new Collection(Rule::class);
    }
}
