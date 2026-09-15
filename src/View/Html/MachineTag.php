<?php

declare(strict_types=1);

namespace Phpanta\View\Html;

/**
 * The MachineTag enum. The custom elements the admin's `machine` service writes.
 *
 * Each works without its script: the server writes the readings and the whole listing, and the
 * element only keeps them live, or filters what is already there. A site that shows the service
 * imports the modules that define them from its entry script — see
 * `assets/ts/elements/MachineStats.ts` and `MachineFilter.ts` — and `assets/ts/model/MachineTag.ts`
 * mirrors this list.
 */
enum MachineTag: string implements TagName
{
    /** The machine's readings, asked for again every few seconds while the page is shown. */
    case Stats = 'machine-stats';

    /** A box that hides the entries of a directory whose names do not hold what is typed into it. */
    case Filter = 'machine-filter';

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
        return false;
    }
}
