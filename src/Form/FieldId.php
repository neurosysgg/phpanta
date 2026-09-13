<?php

declare(strict_types=1);

namespace Phpanta\Form;

use Phpanta\View\Html\AttributeValue;

/**
 * The FieldId class. The ids a form writes for a field: one on its control, which its `<label for>`
 * names, and one on its error, which the control's `aria-describedby` names.
 *
 * A class rather than two concatenations for the reason each pair of attributes needs one: the
 * `for` and the `id` it names are two spellings of one fact, and a label whose `for` names nothing
 * is not an error anywhere — it is a label a click on does nothing, and a control a screen reader
 * announces without its name. Built in one place, the two halves cannot disagree.
 *
 * The field's name is `rawurlencode`d into the id, since a name on the wire may hold a space and an
 * id may not. What that leaves is letters, digits, `-_.~` and `%`, never a separator of its own, so
 * `field-` and `error-` cannot run into each other whatever the fields are called. **Two forms of
 * the same fields on one page would share ids**; a page that shows two gives them different enums.
 */
final readonly class FieldId implements AttributeValue
{
    /**
     * @param string $id
     */
    private function __construct(private string $id) {}

    /**
     * The id of $field's control.
     *
     * @param Field $field
     * @return self
     */
    public static function control(Field $field): self
    {
        return new self('field-' . rawurlencode((string) $field->value));
    }

    /**
     * The id of $field's error.
     *
     * @param Field $field
     * @return self
     */
    public static function error(Field $field): self
    {
        return new self('error-' . rawurlencode((string) $field->value));
    }

    /**
     * @return string
     */
    public function render(): string
    {
        return $this->id;
    }
}
