<?php

declare(strict_types=1);

namespace Phpanta\Form;

use BackedEnum;
use NoDiscard;
use Phpanta\Exception\FormException;
use Phpanta\Exception\InputException;
use Phpanta\Exception\RouteException;
use Phpanta\Http\CsrfField;
use Phpanta\Http\Request;
use Phpanta\Support\Collection;
use Phpanta\Support\Path;
use Phpanta\Support\SearchableCollection;
use Phpanta\Text\FrameworkText;
use Phpanta\Text\Translatable;
use Phpanta\View\Html\ButtonType;
use Phpanta\View\Html\Element;
use Phpanta\View\Html\FormMethod;
use Phpanta\View\Html\HtmlAttribute;
use Phpanta\View\Html\HtmlTag;
use Phpanta\View\Html\InputType;
use Phpanta\View\Html\Node;

/**
 * The Form class. A form: the fields a {@link Field} enum declares, and the address it posts to —
 * read from a request into a {@link Submission}, and rendered back out of one.
 *
 * ```php
 * $form       = new Form(ContactField::class, AppPath::Contact);
 * $submission = $request->method() === HttpMethod::Post ? $form->read($request) : $form->blank();
 * $session    = Session::of($request, $seal)->withToken();
 *
 * $page = $form->render($submission, $session->token(), ContactText::Send);
 * ```
 *
 * **It writes the form token itself**, as a hidden `_csrf` field, rather than leaving each page to
 * add one. A page that forgot would render a form that looks right and that
 * {@link \Phpanta\Service\Layer\CsrfGuard} refuses on every send — noticed only after a visitor has
 * typed everything — and the field's name would be one more spelling of {@link CsrfField::Token}
 * to keep in step with the guard's. Written here, a form that renders is a form the guard accepts.
 * The token is the page's to hand in, because keeping it is the session's: the page asks
 * `Session::withToken()` and attaches the session to its answer.
 *
 * **It always posts.** A form that writes is the only kind worth a token, and a `get` puts every
 * field — a password among them — into the address bar, the history and the server's log. Where it
 * may post is bound twice: `action` is checked by {@link Element::render()} like any `href`, and
 * the Content-Security-Policy's `form-action 'self'` refuses anything but this origin in the browser.
 *
 * What it renders, per field: a `<label for>` naming the id of the control, then the control — an `<input>`
 * of the type of the field, or a `<select>` for a {@link OneOf} field — carrying `required` and
 * `maxlength` where the rules say so, and, when the field has an error, the error after it, which
 * the control names in `aria-describedby`. All of it through the tree, so a value a visitor typed
 * comes back escaped like any other text.
 */
final readonly class Form
{
    /** Where it posts, filled in from the path when the form is built. */
    private string $action;

    /**
     * Constructs an instance of {@link self}.
     *
     * @param class-string<Field> $fields The form's fields: an enum of {@link Field} cases.
     * @param Path                $path   The address it posts to.
     * @param string|int          ...$values The path's placeholders, filled in as {@link Path::to()} fills them.
     * @throws FormException if $fields is not a Field enum, or one of its fields is named like the form token.
     * @throws RouteException if the values do not fit the path.
     */
    public function __construct(private string $fields, Path $path, string|int ...$values)
    {
        if (!is_subclass_of($fields, Field::class)) {
            throw new FormException(sprintf('%s is not an enum of Field cases, so it is no form.', $fields));
        }

        // The token's field is the form's, and a field of the same name would be sent twice — which
        // Input refuses, so every send would be a 400 with the form looking right.
        $token = $this->fields()->first(
            static fn(Field $field): bool => (string) $field->value === CsrfField::Token->value,
        );

        if ($token !== null) {
            throw new FormException(sprintf(
                "%s::%s is named '%s', which is the form token's field.",
                $fields,
                $token->name,
                CsrfField::Token->value,
            ));
        }

        $this->action = $path->to(...$values);
    }

    /**
     * Nothing sent yet: every field empty, none in error — what a form's first render shows.
     *
     * @return Submission
     */
    #[NoDiscard('blank() builds a submission; a call whose result goes nowhere built nothing')]
    public function blank(): Submission
    {
        return new Submission(
            $this->fields,
            $this->entries(static fn(Field $field): FieldEntry => new FieldEntry('')),
            false,
        );
    }

    /**
     * What $request's form sent, with every field's rules asked of its value.
     *
     * A field not sent is `''` — which is how an unticked checkbox arrives, since a browser sends
     * nothing for one — and its rules are asked of that. The first rule to refuse is the field's
     * error; the rest are not asked, so a field shows one thing to fix at a time. A field the form
     * does not have is ignored, as `_csrf` is.
     *
     * @param Request $request
     * @return Submission
     * @throws InputException if the body cannot be read as a form, or a field was sent twice. The
     *                        router answers it with a 400; a form never has to.
     */
    #[NoDiscard('read() reads the submission; a call whose result goes nowhere checked nothing')]
    public function read(Request $request): Submission
    {
        $input = $request->form();

        return new Submission(
            $this->fields,
            $this->entries(static function (Field $field) use ($input): FieldEntry {
                $value = $input->text($field) ?? '';

                return new FieldEntry($value, self::firstError($field, $value));
            }),
            true,
        );
    }

    /**
     * The form, holding $submission's values and errors.
     *
     * **A password field is never given its value back**, even when the form comes back for a
     * mistake in another field. The value would be written into the page — into a response a
     * browser may keep for its back button, a proxy may hold, and a saved copy of the page carries
     * — and typing it again is the smaller cost.
     *
     * @param Submission   $submission What to fill it with: {@link self::blank()} or {@link self::read()}.
     * @param string       $token      The visitor's form token, from their session.
     * @param Translatable $submit     What the submit button says.
     * @return Element
     * @throws FormException if $submission is another form's.
     */
    #[NoDiscard('render() builds the form; a call whose result goes nowhere drew nothing')]
    public function render(Submission $submission, string $token, Translatable $submit): Element
    {
        return new Element(HtmlTag::Form)
            ->attr(HtmlAttribute::Method, FormMethod::Post)
            ->attr(HtmlAttribute::Action, $this->action)
            ->containing(
                new Element(HtmlTag::Input)
                    ->attr(HtmlAttribute::Type, InputType::Hidden)
                    ->attr(HtmlAttribute::Name, CsrfField::Token->value)
                    ->attr(HtmlAttribute::Value, $token),
            )
            ->containing(
                ...$this->fields()
                    ->map(static fn(Field $field): Node => self::field($field, $submission))
                    ->toValues(),
            )
            ->containing(
                new Element(HtmlTag::Button)->attr(HtmlAttribute::Type, ButtonType::Submit)->containing($submit),
            );
    }

    /**
     * The fields, in declaration order.
     *
     * @return Collection<Field>
     */
    private function fields(): Collection
    {
        return new Collection(Field::class)->with(...$this->fields::cases());
    }

    /**
     * One entry per field, keyed by its name.
     *
     * @param callable(Field): FieldEntry $entry
     * @return SearchableCollection<FieldEntry>
     */
    private function entries(callable $entry): SearchableCollection
    {
        $entries = new SearchableCollection(FieldEntry::class);

        foreach ($this->fields() as $field) {
            $entries = $entries->with((string) $field->value, $entry($field));
        }

        return $entries;
    }

    /**
     * The first of $field's rules to refuse $value, in their order, or null.
     *
     * @param Field  $field
     * @param string $value
     * @return Translatable|null
     */
    private static function firstError(Field $field, string $value): ?Translatable
    {
        foreach ($field->rules() as $rule) {
            $error = $rule->check($value);

            if ($error !== null) {
                return $error;
            }
        }

        return null;
    }

    /**
     * One field: its label, its control and its error — or, for a hidden one, the control alone,
     * since there is nothing to label and nobody to show an error to.
     *
     * A checkbox's label follows it, as a box and its words conventionally read; every other
     * control's precedes it.
     *
     * @param Field      $field
     * @param Submission $submission
     * @return Node
     * @throws FormException if $submission is another form's.
     */
    private static function field(Field $field, Submission $submission): Node
    {
        $value = $submission->value($field);

        if ($field->type() === InputType::Hidden) {
            return new Element(HtmlTag::Input)
                ->attr(HtmlAttribute::Type, InputType::Hidden)
                ->attr(HtmlAttribute::Name, (string) $field->value)
                ->attr(HtmlAttribute::Value, $value);
        }

        $error   = $submission->error($field);
        $control = self::control($field, $value, $error !== null);
        $label   = new Element(HtmlTag::Label)
            ->attr(HtmlAttribute::For, FieldId::control($field))
            ->containing($field->label());

        $row = $field->type() === InputType::Checkbox
            ? new Element(HtmlTag::Div)->containing($control, $label)
            : new Element(HtmlTag::Div)->containing($label, $control);

        return $error === null ? $row : $row->containing(
            new Element(HtmlTag::P)->attr(HtmlAttribute::Id, FieldId::error($field))->containing($error),
        );
    }

    /**
     * $field's control, holding $value.
     *
     * The name is written as the case's value, never as the case: a site's field enum may well be
     * its own catalog too, and {@link Element::attr()} would render a translatable case as its words.
     *
     * @param Field  $field
     * @param string $value
     * @param bool   $inError
     * @return Element
     */
    private static function control(Field $field, string $value, bool $inError): Element
    {
        $rules  = $field->rules();
        $choice = $rules->first(static fn(Rule $rule): bool => $rule instanceof OneOf);
        $length = $rules->first(static fn(Rule $rule): bool => $rule instanceof MaxLength);

        $required = $rules->first(static fn(Rule $rule): bool => $rule instanceof Required) !== null;

        // A length is the browser's to hold only where something is typed; a choice and a box have none.
        $typed = !($choice instanceof OneOf) && $field->type() !== InputType::Checkbox;

        $control = $choice instanceof OneOf
            ? new Element(HtmlTag::Select)
            : new Element(HtmlTag::Input)->attr(HtmlAttribute::Type, $field->type());

        $control = $control
            ->attr(HtmlAttribute::Id, FieldId::control($field))
            ->attr(HtmlAttribute::Name, (string) $field->value)
            ->attr(HtmlAttribute::Required, $required)
            ->attr(HtmlAttribute::MaxLength, $typed ? $length?->max : null)
            ->attr(HtmlAttribute::Autocomplete, $field instanceof Autocompleting ? $field->autocomplete() : null)
            ->attr(HtmlAttribute::AriaDescribedBy, $inError ? FieldId::error($field) : null);

        if ($choice instanceof OneOf) {
            return self::options($control, $choice, $value);
        }

        return match ($field->type()) {
            InputType::Checkbox => $control->attr(HtmlAttribute::Checked, $value !== ''),
            // Never given its value back — see render().
            InputType::Password => $control,
            default             => $control->attr(HtmlAttribute::Value, $value === '' ? null : $value),
        };
    }

    /**
     * $select, holding an empty first option and then one per choice, the one sent selected.
     *
     * The empty option is what makes a required choice ask: without it the first case is selected
     * from the start, and `required` is satisfied by a choice nobody made.
     *
     * @param Element $select
     * @param OneOf   $choice
     * @param string  $value
     * @return Element
     */
    private static function options(Element $select, OneOf $choice, string $value): Element
    {
        $none = new Element(HtmlTag::Option)->attr(HtmlAttribute::Value, '')->containing(FrameworkText::FieldChoose);

        return $select
            ->containing($none)
            ->containing(...$choice->cases()->map(
                static fn(BackedEnum $case): Node => new Element(HtmlTag::Option)
                    ->attr(HtmlAttribute::Value, (string) $case->value)
                    ->attr(HtmlAttribute::Selected, (string) $case->value === $value)
                    ->containing($case instanceof Translatable ? $case : (string) $case->value),
            )->toValues());
    }
}
