<?php

declare(strict_types=1);

namespace Phpanta\View;

use Phpanta\Http\Api\ActionField;
use Phpanta\Http\Api\ApiAction;
use Phpanta\Http\FormEncoding;
use Phpanta\Http\RequestHeader;
use Phpanta\Model\Passkey\CeremonyType;
use Phpanta\Support\BareArray;
use Phpanta\Text\AdminText;
use Phpanta\Text\Translatable;
use Phpanta\View\Html\Autocomplete;
use Phpanta\View\Html\Element;
use Phpanta\View\Html\HtmlAttribute;
use Phpanta\View\Html\HtmlTag;
use Phpanta\View\Html\InputType;
use Phpanta\View\Html\Node;

/**
 * The ApiActionFormView class. A write, offered to a browser the admin has let in: what it does, a
 * field for each thing it takes, and two buttons — a dry run, and the real thing.
 *
 * Posted with the session's form token and answered, before it is sent, by the passkey that unlocked
 * the admin, over a challenge minted for this one address and method — so a tap is one write, the way
 * a signature is for the signing commands.
 */
final class ApiActionFormView extends View
{
    /**
     * Constructs an instance of {@link self}.
     *
     * @param ApiAction $action    The write.
     * @param string    $path      Its address.
     * @param string    $token     The session's form token.
     * @param string    $challenge The challenge minted for this write.
     */
    public function __construct(
        private readonly ApiAction $action,
        private readonly string    $path,
        private readonly string    $token,
        private readonly string    $challenge,
    ) {}

    /**
     * @return Translatable
     */
    public function pageTitle(): Translatable
    {
        return self::title($this->path);
    }

    /**
     * @return Node
     */
    public function content(): Node
    {
        $fields = $this->action->fields()
            ->where(static fn(ActionField $field): bool => $field !== ActionField::Apply)
            ->map(static fn(ActionField $field): Node => self::field($field))
            ->toValues();
        $files  = $this->action->fields()->first(static fn(ActionField $field): bool => $field->isUpload()) !== null;

        return new Element(HtmlTag::Section)->containing(
            new Element(HtmlTag::H1)->containing($this->path),
            new Element(HtmlTag::P)->containing($this->action->describe()),
            AdminForm::posting(
                $this->path,
                $this->token,
                CeremonyType::Get,
                $this->challenge,
                $files ? FormEncoding::Multipart : null,
            )->containing(
                ...$fields,
                ...[
                    AdminForm::button(AdminText::DryRun, ActionField::Apply, self::said(false)),
                    AdminForm::button(AdminText::Apply, ActionField::Apply, self::said(true)),
                ],
            ),
        );
    }

    /**
     * Answered to a page request only.
     *
     * @return list<RequestHeader>
     */
    #[BareArray('overrides View::varyOn(), whose own attribute says why it is an array')]
    public function varyOn(): array
    {
        return [RequestHeader::Accept];
    }

    /**
     * $answer as a form sends a yes or a no — `true` or `false`, which the field's reader takes.
     *
     * @param bool $answer
     * @return string
     */
    private static function said(bool $answer): string
    {
        return (string) json_encode($answer);
    }

    /**
     * A labelled control for $field — required unless the field is optional: a line of text; a file
     * control, which for {@link ActionField::Files} takes several, sent as a list, which is how PHP keeps
     * more than the last; a box of text; a password; or a box to tick, for a yes-or-no.
     *
     * `apply` is never one of these — it is the two buttons — and the only yes-or-no besides it that a
     * browser meets is a drop's `once`. A push's `mirror` belongs to an action a browser may not carry
     * out, so a form here never has it to write.
     *
     * @param ActionField $field
     * @return Element
     */
    private static function field(ActionField $field): Element
    {
        $control = match (true) {
            $field === ActionField::Files => new Element(HtmlTag::Input)
                ->attr(HtmlAttribute::Type, InputType::File)
                ->attr(HtmlAttribute::Name, $field->value . '[]')
                ->attr(HtmlAttribute::Multiple, true),
            $field->isUpload()            => new Element(HtmlTag::Input)
                ->attr(HtmlAttribute::Type, InputType::File)
                ->attr(HtmlAttribute::Name, $field),
            $field === ActionField::Text  => new Element(HtmlTag::Textarea)->attr(HtmlAttribute::Name, $field),
            $field === ActionField::Password => new Element(HtmlTag::Input)
                ->attr(HtmlAttribute::Type, InputType::Password)
                ->attr(HtmlAttribute::Name, $field)
                ->attr(HtmlAttribute::Autocomplete, Autocomplete::NewPassword),
            $field->isFlag()              => new Element(HtmlTag::Input)
                ->attr(HtmlAttribute::Type, InputType::Checkbox)
                ->attr(HtmlAttribute::Name, $field)
                ->attr(HtmlAttribute::Value, self::said(true)),
            default                       => new Element(HtmlTag::Input)
                ->attr(HtmlAttribute::Type, InputType::Text)
                ->attr(HtmlAttribute::Name, $field),
        };

        $labelled = new Element(HtmlTag::Label)
            ->containing($field->describe(), ' ', $control->attr(HtmlAttribute::Required, !$field->isOptional()));

        return new Element(HtmlTag::P)->containing($labelled);
    }
}
