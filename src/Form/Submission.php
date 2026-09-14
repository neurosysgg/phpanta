<?php

declare(strict_types=1);

namespace Phpanta\Form;

use NoDiscard;
use Phpanta\Exception\FormException;
use Phpanta\Http\Upload;
use Phpanta\Support\SearchableCollection;
use Phpanta\Text\Translatable;

/**
 * The Submission class. What a form was sent, and what its rules said of it — or, for the first
 * render, nothing at all. Immutable; {@link Form::read()} and {@link Form::blank()} build one.
 *
 * ```php
 * $submission = $form->read($request, $session->token());
 *
 * if ($submission->isValid()) {
 *     $email = $submission->value(ContactField::Email);   // …and redirect, so a reload sends nothing
 * }
 *
 * return new ViewResponse(new ContactPage($form->render($submission, $token, ContactText::Send)));
 * ```
 */
final readonly class Submission
{
    /**
     * Constructs an instance of {@link self}.
     *
     * @param class-string<Field>              $fields    The form's field enum.
     * @param SearchableCollection<FieldEntry> $entries   One per field, keyed by its name.
     * @param bool                             $submitted Whether anything was sent, which a blank has not.
     * @param Translatable|null                $refusal   What refused the whole form rather than one
     *                                                    field — a form token that did not match.
     */
    public function __construct(
        private string               $fields,
        private SearchableCollection $entries,
        private bool                 $submitted,
        private ?Translatable        $refusal = null,
    ) {}

    /**
     * Whether it was sent, the form was not refused, and every field passed every rule.
     *
     * **A blank is not valid**, though it holds no error: nothing was sent, so there is nothing to
     * act on, and a controller that asks this before it writes cannot write on a first render.
     *
     * @return bool
     */
    #[NoDiscard('isValid() only asks; a call whose result goes nowhere asked nothing')]
    public function isValid(): bool
    {
        return $this->submitted
            && $this->refusal === null
            && $this->entries->first(static fn(FieldEntry $entry): bool => $entry->error !== null) === null;
    }

    /**
     * What refused the whole form, or null — see {@link Form::read()}. {@link Form::render()} shows
     * it at the top of the form.
     *
     * @return Translatable|null
     */
    #[NoDiscard('refusal() only reads; a call whose result goes nowhere read nothing')]
    public function refusal(): ?Translatable
    {
        return $this->refusal;
    }

    /**
     * $field's value as it was sent, `''` when nothing was — valid or not.
     *
     * @param Field $field
     * @return string
     * @throws FormException if $field is not a field of this form.
     */
    #[NoDiscard('value() only reads; a call whose result goes nowhere read nothing')]
    public function value(Field $field): string
    {
        return $this->entry($field)->value;
    }

    /**
     * What is wrong with $field's value — its first rule to refuse — or null for nothing.
     *
     * @param Field $field
     * @return Translatable|null
     * @throws FormException if $field is not a field of this form.
     */
    #[NoDiscard('error() only reads; a call whose result goes nowhere read nothing')]
    public function error(Field $field): ?Translatable
    {
        return $this->entry($field)->error;
    }

    /**
     * The file $field sent, or null where it sent none — and for any field that is not a file.
     *
     * Present whether or not the submission is valid, as {@link self::value()} is; a page keeps it
     * only once {@link self::isValid()} says so.
     *
     * @param Field $field
     * @return Upload|null
     * @throws FormException if $field is not a field of this form.
     */
    #[NoDiscard('upload() only reads; a call whose result goes nowhere read nothing')]
    public function upload(Field $field): ?Upload
    {
        return $this->entry($field)->upload;
    }

    /**
     * This submission with $error on $field — a refusal no rule of the field could make, such as a
     * name and a password that do not match — shown beside the field like any other, and so no
     * longer valid.
     *
     * @param Field        $field
     * @param Translatable $error
     * @return self
     * @throws FormException if $field is not a field of this form.
     */
    #[NoDiscard('withError() returns a copy; a call whose result goes nowhere refused nothing')]
    public function withError(Field $field, Translatable $error): self
    {
        $entry = $this->entry($field);

        return new self(
            $this->fields,
            $this->entries->with((string) $field->value, new FieldEntry($entry->value, $error, $entry->upload)),
            $this->submitted,
            $this->refusal,
        );
    }

    /**
     * $field's entry.
     *
     * Asked of the enum first, not only of the name: two forms may both have an `email`, and one
     * form's submission answering for the other's field would be a value read from the wrong form.
     *
     * @param Field $field
     * @return FieldEntry
     * @throws FormException if $field is not a field of this form, or this submission holds no entry
     *                       for it.
     */
    private function entry(Field $field): FieldEntry
    {
        $entry = $field instanceof $this->fields ? $this->entries->find((string) $field->value) : null;

        return $entry ?? throw new FormException(sprintf(
            '%s::%s is not a field of this %s submission.',
            $field::class,
            $field->name,
            $this->fields,
        ));
    }
}
