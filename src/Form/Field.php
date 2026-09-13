<?php

declare(strict_types=1);

namespace Phpanta\Form;

use Phpanta\Http\Parameter;
use Phpanta\Support\Collection;
use Phpanta\Text\Translatable;
use Phpanta\View\Html\InputType;

/**
 * The Field interface. One field of a form — a case of the enum a site writes for each form it has.
 *
 * ```php
 * enum ContactField: string implements Field
 * {
 *     case Email   = 'email';
 *     case Message = 'message';
 *
 *     public function label(): Translatable { return match ($this) { … }; }
 *     public function type(): InputType     { return match ($this) { self::Email => InputType::Email, … }; }
 *     public function rules(): Collection   { return new Collection(Rule::class)->with(new Required(), …); }
 * }
 * ```
 *
 * A {@link Parameter}, because a field is a name a request sends a value under — the case's value is
 * the name on the wire, and {@link \Phpanta\Http\Input} reads it by the case. The enum is the form:
 * its cases, in declaration order, are its fields, in the order {@link Form::render()} writes them.
 */
interface Field extends Parameter
{
    /**
     * What the field is called on the page, in its `<label>`.
     *
     * @return Translatable
     */
    public function label(): Translatable;

    /**
     * What kind of control it is. A field with a {@link OneOf} rule is a `<select>` whatever this
     * says, since its choices are its whole vocabulary; say {@link InputType::Text} there.
     *
     * @return InputType
     */
    public function type(): InputType;

    /**
     * What its value has to be, in the order they are asked — the first to refuse is the error the
     * field shows. `required` and `maxlength` are read off these for the browser, so the page asks
     * what the server does before anything is sent.
     *
     * @return Collection<Rule>
     */
    public function rules(): Collection;
}
