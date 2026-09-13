<?php

declare(strict_types=1);

namespace Phpanta\Test\Unit;

use Phpanta\Form\Field;
use Phpanta\Form\Rule;
use Phpanta\Support\Collection;
use Phpanta\Text\Translatable;
use Phpanta\View\Html\InputType;

/**
 * The login recipe's logout form: no field at all, so what it sends is its form token and nothing
 * else — which is the whole of what a logout needs to prove it was the visitor's own.
 *
 * With no cases, nothing ever asks the three methods below; they answer only because the interface
 * says a field has them.
 */
enum LogoutFieldFixture: string implements Field
{
    /**
     * @return Translatable
     */
    public function label(): Translatable
    {
        return LoginTextFixture::SignOut;
    }

    /**
     * @return InputType
     */
    public function type(): InputType
    {
        return InputType::Hidden;
    }

    /**
     * @return Collection<Rule>
     */
    public function rules(): Collection
    {
        return new Collection(Rule::class);
    }
}
