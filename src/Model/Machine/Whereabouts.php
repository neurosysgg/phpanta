<?php

declare(strict_types=1);

namespace Phpanta\Model\Machine;

use Phpanta\Http\Api\MachineAction;
use Phpanta\Text\AdminText;
use Phpanta\View\Html\Element;
use Phpanta\View\Html\HtmlAttribute;
use Phpanta\View\Html\HtmlTag;

/**
 * The Whereabouts class. Where a place is, as a line of links: its root, each directory down to it,
 * and itself — the breadcrumbs over a directory or a file the `machine` service shows.
 */
final class Whereabouts
{
    /** What stands between two steps. */
    private const string BETWEEN = ' › ';

    /**
     * $place's trail: every step a link to its listing, the last one named but not linked.
     *
     * @param MachinePath $place
     * @return Element
     */
    public static function of(MachinePath $place): Element
    {
        $steps = [];

        foreach ($place->trail() as $step) {
            if ($steps !== []) {
                $steps[] = self::BETWEEN;
            }

            $steps[] = $step === $place || $step->path === $place->path
                ? new Element(HtmlTag::Strong)->containing($step->name())
                : new Element(HtmlTag::A)
                    ->attr(HtmlAttribute::Href, MachineAction::Files->href($step->subject()))
                    ->containing($step->name());
        }

        return new Element(HtmlTag::Nav)->attr(HtmlAttribute::AriaLabel, AdminText::Whereabouts)->containing(...$steps);
    }
}
