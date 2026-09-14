<?php

declare(strict_types=1);

namespace Phpanta\View;

use Phpanta\Http\CsrfField;
use Phpanta\Http\Parameter;
use Phpanta\Http\PasskeyFormField;
use Phpanta\Model\Passkey\CeremonyType;
use Phpanta\Model\Passkey\EntranceCeremony;
use Phpanta\Support\AdminPath;
use Phpanta\Text\Translatable;
use Phpanta\View\Html\ButtonType;
use Phpanta\View\Html\Element;
use Phpanta\View\Html\FormMethod;
use Phpanta\View\Html\HtmlAttribute;
use Phpanta\View\Html\HtmlTag;
use Phpanta\View\Html\InputType;
use Phpanta\View\Html\PasskeyAttribute;

/**
 * The AdminForm class. The forms the admin's pages post back: to the entrance, to unlock, register or
 * lock, and to an action, to write.
 *
 * Every one carries the session's form token, which the admin checks itself rather than through
 * {@link \Phpanta\Service\Layer\CsrfGuard} — that guard would stand in front of the signed commands
 * too, which carry no form. One a passkey must answer before it is sent says which ceremony and which
 * challenge, and the client module that runs the ceremony fills in what the authenticator answered.
 */
final class AdminForm
{
    /**
     * A form posting $ceremony to the entrance, answered by a passkey where $type says which ceremony.
     *
     * @param EntranceCeremony  $ceremony
     * @param CeremonyType|null $type      Null for a form no passkey answers — locking the admin.
     * @param string            $token     The session's form token.
     * @param string|null       $challenge The entrance's challenge, where a passkey answers.
     * @param Translatable      $label     What its button says.
     * @return Element
     */
    public static function entrance(
        EntranceCeremony $ceremony,
        ?CeremonyType $type,
        string $token,
        ?string $challenge,
        Translatable $label,
    ): Element {
        return self::posting(AdminPath::Index->to(), $token, $type, $challenge)->containing(
            self::hidden(PasskeyFormField::Ceremony, $ceremony->value),
            self::button($label),
        );
    }

    /**
     * A form posting to $action with the session's $token — answered by a passkey where $type says so.
     *
     * @param string            $action
     * @param string            $token
     * @param CeremonyType|null $type
     * @param string|null       $challenge
     * @return Element
     */
    public static function posting(string $action, string $token, ?CeremonyType $type, ?string $challenge): Element
    {
        return new Element(HtmlTag::Form)
            ->attr(HtmlAttribute::Method, FormMethod::Post)
            ->attr(HtmlAttribute::Action, $action)
            ->attr(PasskeyAttribute::Ceremony, $type)
            ->attr(PasskeyAttribute::Challenge, $challenge)
            ->containing(self::hidden(CsrfField::Token, $token));
    }

    /**
     * A field the form sends without showing it.
     *
     * @param Parameter $name
     * @param string    $value
     * @return Element
     */
    public static function hidden(Parameter $name, string $value): Element
    {
        return new Element(HtmlTag::Input)
            ->attr(HtmlAttribute::Type, InputType::Hidden)
            ->attr(HtmlAttribute::Name, $name)
            ->attr(HtmlAttribute::Value, $value);
    }

    /**
     * A button that sends the form — as $name set to $value, where it is one of several.
     *
     * @param Translatable   $label
     * @param Parameter|null $name
     * @param string|null    $value
     * @return Element
     */
    public static function button(Translatable $label, ?Parameter $name = null, ?string $value = null): Element
    {
        return new Element(HtmlTag::Button)
            ->attr(HtmlAttribute::Type, ButtonType::Submit)
            ->attr(HtmlAttribute::Name, $name)
            ->attr(HtmlAttribute::Value, $value)
            ->containing($label);
    }
}
