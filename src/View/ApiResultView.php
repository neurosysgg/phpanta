<?php

declare(strict_types=1);

namespace Phpanta\View;

use Phpanta\Http\Api\ApiResult;
use Phpanta\Http\RequestHeader;
use Phpanta\Support\BareArray;
use Phpanta\Text\Translatable;
use Phpanta\View\Html\Element;
use Phpanta\View\Html\HtmlTag;
use Phpanta\View\Html\Node;

/**
 * The ApiResultView class. An admin answer as a page, in whatever shell the app wraps its pages in.
 *
 * The heading is the address the answer is for, and the rest is the result's own sections — a table
 * for facts, a list for lines. Nothing here is a word the framework chose: every string on the page
 * is either the address or what a handler reported, so the page needs no catalog of its own.
 */
final class ApiResultView extends View
{
    /**
     * Constructs an instance of {@link self}.
     *
     * @param ApiResult $result
     * @param string $address What was asked for, as `service/version/action` — as it was sent, and
     *                        escaped like any other text when it is written.
     */
    public function __construct(
        private readonly ApiResult $result,
        private readonly string    $address,
    ) {}

    /**
     * @return Translatable
     */
    public function pageTitle(): Translatable
    {
        return self::title($this->address);
    }

    /**
     * @return Node
     */
    public function content(): Node
    {
        return new Element(HtmlTag::Section)->containing(
            new Element(HtmlTag::H1)->containing($this->address),
            $this->result->node(),
        );
    }

    /**
     * The page is one of two forms of the same answer, chosen by `Accept`.
     *
     * @return list<RequestHeader>
     */
    #[BareArray('overrides View::varyOn(), whose own attribute says why it is an array')]
    public function varyOn(): array
    {
        return [RequestHeader::Accept];
    }
}
