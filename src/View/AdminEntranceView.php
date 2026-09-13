<?php

declare(strict_types=1);

namespace Phpanta\View;

use Phpanta\Http\RequestHeader;
use Phpanta\Support\BareArray;
use Phpanta\Text\AdminText;
use Phpanta\Text\Translatable;
use Phpanta\View\Html\Element;
use Phpanta\View\Html\HtmlTag;
use Phpanta\View\Html\Node;

/**
 * The AdminEntranceView class. `/admin`, for a visitor it cannot yet let in.
 *
 * The one admin page anybody may see, and it says only that there is an admin and that it needs a
 * credential — which the site may say anyway, with a link to it. Every deeper address sends such a
 * visitor here, whether it exists or not.
 */
final class AdminEntranceView extends View
{
    /**
     * @return Translatable
     */
    public function pageTitle(): Translatable
    {
        return self::title(AdminText::Admin);
    }

    /**
     * @return Node
     */
    public function content(): Node
    {
        return new Element(HtmlTag::Section)->containing(
            new Element(HtmlTag::H1)->containing(AdminText::Admin),
            new Element(HtmlTag::P)->containing(AdminText::Entrance),
        );
    }

    /**
     * The entrance is what a request for a page gets; one for data gets a `401` instead.
     *
     * @return list<RequestHeader>
     */
    #[BareArray('overrides View::varyOn(), whose own attribute says why it is an array')]
    public function varyOn(): array
    {
        return [RequestHeader::Accept];
    }
}
