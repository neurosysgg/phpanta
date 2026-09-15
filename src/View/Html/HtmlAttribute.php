<?php

declare(strict_types=1);

namespace Phpanta\View\Html;

/**
 * The HtmlAttribute enum. The standard HTML attributes in use.
 *
 * `class` is {@link self::ClassName} rather than `Class`, because `HtmlAttribute::Class` parses as
 * the `::class` magic constant and would quietly evaluate to the enum's own name — a case where the
 * wrong thing compiles, which is exactly what this file exists to prevent.
 */
enum HtmlAttribute: string implements AttributeName
{
    case ClassName = 'class';
    case Id        = 'id';
    case Lang      = 'lang';
    case Title     = 'title';

    case Href   = 'href';
    case HrefLang = 'hreflang';
    case Src    = 'src';
    case Rel    = 'rel';
    case Target = 'target';
    case Type   = 'type';

    /**
     * That a link saves what it points at rather than showing it. `Navigation` leaves such a link
     * to the browser, since a response it saves is not a page it could swap in.
     */
    case Download = 'download';

    case Alt     = 'alt';
    case Height  = 'height';
    case Width   = 'width';
    case Charset = 'charset';
    case Name    = 'name';
    case Content = 'content';

    /**
     * Show the browser's own play/seek controls. A bare boolean attribute — `attr(…, true)`.
     *
     * On `<audio>` its absence is not a styling choice, it is a player with no way to start it.
     */
    case Controls = 'controls';

    /**
     * How much of a media file to fetch before it is played. See {@link MediaPreload}.
     */
    case Preload = 'preload';

    case AriaLabel = 'aria-label';

    /**
     * How a region's changes are read out. Written by `Navigation` alone, on the region it announces
     * a new page's title through — a swap is not a page load, and nothing else would tell a screen
     * reader the page changed.
     */
    case AriaLive = 'aria-live';

    /**
     * The id of the element that describes this one — a form control pointing at its error, so a
     * screen reader reads the error out with the control rather than leaving it to be found.
     */
    case AriaDescribedBy = 'aria-describedby';

    /**
     * Where a `<form>` sends what it holds: an address, and checked as one — see {@link self::isUrl()}.
     */
    case Action = 'action';

    /** How a `<form>` sends it. See {@link FormMethod}. */
    case Method = 'method';

    /** How a `<form>` packs what it sends. See {@link \Phpanta\Http\FormEncoding}. */
    case Enctype = 'enctype';

    case Value = 'value';

    /**
     * The id of the control a `<label>` names. `For` rather than anything longer: a case may be
     * called what PHP otherwise reserves, `class` alone excepted — see {@link self::ClassName}.
     */
    case For = 'for';

    case Required     = 'required';
    case MaxLength    = 'maxlength';
    case Autocomplete = 'autocomplete';
    case Checked      = 'checked';
    case Selected     = 'selected';
    case Readonly     = 'readonly';

    /** A control that takes no input — a passkey form's buttons, while its authenticator is asked. */
    case Disabled = 'disabled';

    /** Not shown until something shows it — what a passkey form says when nobody answered. */
    case Hidden = 'hidden';

    /** A file control that takes several files at once — the admin's upload. */
    case Multiple = 'multiple';

    /** Where a `<meter>`'s range starts. */
    case Min = 'min';

    /** Where a `<meter>`'s range ends. */
    case Max = 'max';

    /** What an empty text control is for, said inside it — the filter over a directory. */
    case Placeholder = 'placeholder';

    /**
     * @return string
     */
    public function attribute(): string
    {
        return $this->value;
    }

    /**
     * `href`, `src` and `action` are the three the browser dereferences; the rest are values it reads.
     *
     * `action` belongs with the other two for the reason `href` does: submitting a form navigates to
     * it, and `javascript:` there runs when the form is sent, exactly as it would from a link.
     *
     * @return bool
     */
    public function isUrl(): bool
    {
        return match ($this) {
            self::Href, self::Src, self::Action => true,
            default                             => false,
        };
    }
}
