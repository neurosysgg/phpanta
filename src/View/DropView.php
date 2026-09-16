<?php

declare(strict_types=1);

namespace Phpanta\View;

use Phpanta\Http\DropField;
use Phpanta\Model\Drop\DropToken;
use Phpanta\Support\DropPath;
use Phpanta\Text\DropText;
use Phpanta\Text\Translatable;
use Phpanta\View\Html\Autocomplete;
use Phpanta\View\Html\DropTag;
use Phpanta\View\Html\Element;
use Phpanta\View\Html\FormMethod;
use Phpanta\View\Html\HtmlAttribute;
use Phpanta\View\Html\HtmlTag;
use Phpanta\View\Html\InputType;
use Phpanta\View\Html\Node;

/**
 * The DropView class. The page at `/drop`: a form that posts a link's token — and a password, where
 * the drop has one — back to the same address, which answers with what the drop holds.
 *
 * **The token arrives after the link's `#`**, which a browser never sends, so the server cannot write
 * it into the page; `<drop-reveal>` fills it in from the address, hides its field, and takes it out of
 * the address again. Without the script the field is there to paste it into. Where a post was refused
 * for its password, the token it carried is written back, so only the password is asked again.
 *
 * **Revealing is a post, never a read.** A link a chat app unfurls, or a scanner follows, reveals
 * nothing and burns nothing: it gets this page.
 */
final class DropView extends View
{
    /**
     * Constructs an instance of {@link self}.
     *
     * @param Translatable|null $message Why the last post revealed nothing, where it did not.
     * @param DropToken|null    $token   The token a post refused for its password carried.
     */
    public function __construct(
        private readonly ?Translatable $message = null,
        private readonly ?DropToken    $token = null,
    ) {}

    /**
     * @return Translatable
     */
    public function pageTitle(): Translatable
    {
        return self::title(DropText::Heading);
    }

    /**
     * @return Node
     */
    public function content(): Node
    {
        $said  = $this->message === null ? [] : [new Element(HtmlTag::P)->containing($this->message)];
        $token = new Element(HtmlTag::Input)
            ->attr(HtmlAttribute::Type, InputType::Text)
            ->attr(HtmlAttribute::Name, DropField::Token)
            ->attr(HtmlAttribute::Value, $this->token?->text())
            ->attr(HtmlAttribute::Autocomplete, Autocomplete::Off)
            ->attr(HtmlAttribute::Required, true);
        $password = new Element(HtmlTag::Input)
            ->attr(HtmlAttribute::Type, InputType::Password)
            ->attr(HtmlAttribute::Name, DropField::Password)
            ->attr(HtmlAttribute::Autocomplete, Autocomplete::Off);

        return new Element(HtmlTag::Section)->containing(
            new Element(HtmlTag::H1)->containing(DropText::Heading),
            new Element(HtmlTag::P)->containing(DropText::Intro),
            ...$said,
            ...[
                new Element(HtmlTag::Form)
                    ->attr(HtmlAttribute::Method, FormMethod::Post)
                    ->attr(HtmlAttribute::Action, DropPath::Index->to())
                    ->containing(
                        new Element(DropTag::Reveal)->containing(self::field(DropText::Token, $token)),
                        self::field(DropText::Password, $password),
                        AdminForm::hidden(DropField::Page, (string) json_encode(true)),
                        new Element(HtmlTag::P)->containing(AdminForm::button(DropText::Reveal)),
                    ),
            ],
        );
    }

    /**
     * $control, labelled, in a paragraph of its own.
     *
     * @param Translatable $label
     * @param Element      $control
     * @return Element
     */
    private static function field(Translatable $label, Element $control): Element
    {
        return new Element(HtmlTag::P)->containing(new Element(HtmlTag::Label)->containing($label, ' ', $control));
    }
}
