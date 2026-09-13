<?php

declare(strict_types=1);

namespace Phpanta\View;

use Phpanta\Http\Api\ApiListing;
use Phpanta\Http\Api\ListingEntry;
use Phpanta\Http\RequestHeader;
use Phpanta\Support\BareArray;
use Phpanta\Text\AdminText;
use Phpanta\Text\Translatable;
use Phpanta\View\Html\Element;
use Phpanta\View\Html\HtmlAttribute;
use Phpanta\View\Html\HtmlTag;
use Phpanta\View\Html\Node;

/**
 * The ApiListingView class. An admin listing as a page: the address, and a row for each thing under
 * it.
 *
 * A service or a version is a link to its own listing. An action that reads is a link to its
 * answer; one that writes is named, with what it takes, but not linked — following a link is a read,
 * and a write is made deliberately or not at all.
 */
final class ApiListingView extends View
{
    /**
     * Constructs an instance of {@link self}.
     *
     * @param ApiListing $listing
     */
    public function __construct(private readonly ApiListing $listing) {}

    /**
     * @return Translatable
     */
    public function pageTitle(): Translatable
    {
        return self::title($this->listing->address);
    }

    /**
     * @return Node
     */
    public function content(): Node
    {
        return new Element(HtmlTag::Section)->containing(
            new Element(HtmlTag::H1)->containing($this->listing->address),
            new Element(HtmlTag::Table)->containing(...$this->listing->entries()
                ->map(static fn(ListingEntry $entry): Node => self::row($entry))
                ->toValues()),
        );
    }

    /**
     * A listing is one of two forms of the same answer, chosen by `Accept`.
     *
     * @return list<RequestHeader>
     */
    #[BareArray('overrides View::varyOn(), whose own attribute says why it is an array')]
    public function varyOn(): array
    {
        return [RequestHeader::Accept];
    }

    /**
     * One entry's row: its name, what an action is, and what it is for.
     *
     * @param ListingEntry $entry
     * @return Element
     */
    private static function row(ListingEntry $entry): Element
    {
        $name = $entry->writes()
            ? new Element(HtmlTag::Strong)->containing($entry->name)
            : new Element(HtmlTag::A)->attr(HtmlAttribute::Href, $entry->href)->containing($entry->name);

        $cells = [new Element(HtmlTag::Td)->containing($name)];

        if ($entry->action !== null) {
            $cells[] = new Element(HtmlTag::Td)->containing($entry->action->method()->value);
            $cells[] = new Element(HtmlTag::Td)->containing($entry->writes() ? AdminText::Writes : AdminText::Reads);
            $cells[] = $entry->action->fromBrowser()
                ? new Element(HtmlTag::Td)
                : new Element(HtmlTag::Td)->containing(AdminText::CliOnly);
        }

        $cells[] = new Element(HtmlTag::Td)->containing($entry->description);

        return new Element(HtmlTag::Tr)->containing(...$cells);
    }
}
