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

    /** Created client-side only: an embedded frame, and the textarea that decodes entities. */
    /**
     * The one media element here, and one that stays native for a reason beyond convention.
     *
     * A player could be a custom element. This one is not, because the browser's own controls are
     * the whole feature: they seek, they work with a keyboard, they work with a screen reader, and
     * **they work with JavaScript off**, which for audio sent to one person to listen to is worth
     * more than any styling.
     */
    case Audio    = 'audio';

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
            self::Meta, self::Link, self::Img, self::Br => true,
            default                                     => false,
        };
    }
}
