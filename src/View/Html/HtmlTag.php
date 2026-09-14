<?php

declare(strict_types=1);

namespace Phpanta\View\Html;

/**
 * The HtmlTag enum. The standard HTML elements in use.
 *
 * Not every element that exists — only the ones actually used, the same way {@link HtmlAttribute}
 * and {@link \Phpanta\Http\Security\PermissionsPolicyFeature} list what is used rather than what is
 * possible. Adding markup that needs a new element means adding its case, which is the moment to ask
 * whether it should be a custom element of the site's own instead, in the site's own tag enum.
 *
 * "Used" means by either side. Some are only ever created by the client, but they are elements a
 * page carries all the same, and `assets/ts/model/HtmlTag.ts` mirrors this list so both halves
 * agree on every one.
 */
enum HtmlTag: string implements TagName
{
    case Html   = 'html';
    case Head   = 'head';
    case Meta   = 'meta';
    case Link   = 'link';
    case Title  = 'title';
    case Script = 'script';
    case Body   = 'body';

    case Header  = 'header';
    case Nav     = 'nav';
    case Main    = 'main';
    case Footer  = 'footer';
    case Section = 'section';

    case H1 = 'h1';
    case H2 = 'h2';
    case H3 = 'h3';

    /**
     * `h4`, `ul`, `li` and `em` are for hand-authored documents, and they arrived with
     * {@link MarkupParser}.
     *
     * They are the answer to the question above — whether a new element should be one of ours
     * instead — and for all four it is no: they carry no behaviour, need no registration, and the
     * browser has known them longer than we have. What is new is only that a *parsed* document may
     * name them, where before its markup went out as one unread string.
     */
    case H4 = 'h4';

    case P  = 'p';
    case Ul = 'ul';
    case Li = 'li';
    case Br = 'br';

    case A      = 'a';
    case Img    = 'img';
    case Button = 'button';
    case Span   = 'span';
    case Small  = 'small';
    case Strong = 'strong';
    case Em     = 'em';
    case Div    = 'div';

    /**
     * The one media element here, and one that stays native for a reason beyond convention.
     *
     * A player could be a custom element. This one is not, because the browser's own controls are
     * the whole feature: they seek, they work with a keyboard, they work with a screen reader, and
     * **they work with JavaScript off**, which for audio sent to one person to listen to is worth
     * more than any styling.
     */
    case Audio    = 'audio';

    /** Created client-side only: an embedded frame, and the textarea that decodes entities. */
    case Iframe   = 'iframe';
    /**
     * What a custom element draws on. Client-created only, the way {@link self::Textarea} is — a
     * view emits the custom element and the element makes this.
     */
    case Canvas   = 'canvas';

    case Textarea = 'textarea';

    case Table = 'table';
    case Tr    = 'tr';
    case Td    = 'td';

    /**
     * Never written by a view, and needed all the same: the parser implies one around a table's rows
     * whether the source wrote it or not, so a hand-authored `<table>` parses only if it has a case.
     */
    case Tbody = 'tbody';

    /**
     * What {@link \Phpanta\Form\Form} writes, and only it: a form carries the visitor's form token,
     * so one assembled anywhere else is a form that posts without it. {@link MarkupParser} refuses a
     * hand-authored one for that reason.
     */
    case Form = 'form';

    /** A form control: void, like `<img>` — its value is an attribute, never content. */
    case Input    = 'input';
    case Label    = 'label';
    case Select   = 'select';
    case Option   = 'option';
    case Fieldset = 'fieldset';
    case Legend   = 'legend';

    /**
     * @return string
     */
    public function tagName(): string
    {
        return $this->value;
    }

    /**
     * @return bool
     */
    public function isVoid(): bool
    {
        return match ($this) {
            self::Meta, self::Link, self::Img, self::Br, self::Input => true,
            default                                                  => false,
        };
    }

    /**
     * True if the element sits in a line of text, so whitespace between it and another such is a
     * space on the page — HTML's phrasing content, which {@link Element} keeps on one line beside
     * its kind.
     *
     * `<script>` is phrasing content in the specification and not here: it draws nothing, so the
     * whitespace beside it draws nothing either, and the document keeps it on a line of its own.
     *
     * @return bool
     */
    public function isPhrasing(): bool
    {
        return match ($this) {
            self::A, self::Img, self::Button, self::Span, self::Small, self::Strong, self::Em, self::Br,
            self::Audio, self::Iframe, self::Canvas, self::Textarea,
            self::Input, self::Label, self::Select => true,
            default                                => false,
        };
    }

    /**
     * True if what the element holds is itself a line of text, so whitespace just inside it is a
     * space on the page — which is why {@link Element} writes its children on its own line.
     *
     * Narrower than {@link self::isPhrasing()}: a `<select>` sits in a line, but its options are not
     * one, and a `<button>` lays out its content as a box of its own.
     *
     * @return bool
     */
    public function isInlineContainer(): bool
    {
        return match ($this) {
            self::A, self::Span, self::Small, self::Strong, self::Em, self::Label => true,
            default                                                               => false,
        };
    }

    /**
     * True if a browser reads the element's content as raw text — neither decoding a `&amp;` nor
     * seeing a tag — so the one escaping this tree has would change what it says.
     *
     * {@link Element} refuses such an element children, and gives its content by `src` instead.
     *
     * @return bool
     */
    public function isRawText(): bool
    {
        return $this === self::Script || $this === self::Iframe;
    }
}
