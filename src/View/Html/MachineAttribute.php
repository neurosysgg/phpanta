<?php

declare(strict_types=1);

namespace Phpanta\View\Html;

/**
 * The MachineAttribute enum. What the `machine` service's elements read off the page.
 *
 * Mirrored by `assets/ts/model/MachineAttribute.ts`, so the name the server writes is the name the
 * client asks for.
 */
enum MachineAttribute: string implements AttributeName
{
    /**
     * Where `<machine-stats>` asks for its readings again: the address of the answer it is part of,
     * as data. An address, so it is checked as one — see {@link self::isUrl()}.
     */
    case Source = 'data-source';

    /** Which reading a meter or a value shows — a {@link \Phpanta\Model\Machine\MachineReading}. */
    case Reading = 'data-reading';

    /** The lower-cased name of a directory entry, set on its row — what `<machine-filter>` compares. */
    case Entry = 'data-entry';

    /**
     * @return string
     */
    public function attribute(): string
    {
        return $this->value;
    }

    /**
     * @return bool
     */
    public function isUrl(): bool
    {
        return $this === self::Source;
    }
}
