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
 * A form that sends a file and has a field PHP would rename in the multipart body it sends — which
 * {@link \Phpanta\Form\Form} refuses to be built with.
 */
enum RenamedFieldFixture: string implements Field
{
    case File   = 'file';
    case Dotted = 'a.b';

    /**
     * @return Translatable
     */
    public function label(): Translatable
    {
        return new Verbatim($this->value);
    }

    /**
     * @return InputType
     */
    public function type(): InputType
    {
        return $this === self::File ? InputType::File : InputType::Text;
    }

    /**
     * @return Collection<Rule>
     */
    public function rules(): Collection
    {
        return new Collection(Rule::class);
    }
}
