<?php

declare(strict_types=1);

namespace Phpanta\Model\Drop;

/**
 * The DropSummary class. One drop as the admin lists it: what can be said of it without its link.
 */
final readonly class DropSummary
{
    /**
     * Constructs an instance of {@link self}.
     *
     * @param string     $id     Its name in the store — a keyed hash of its token, which it is not.
     * @param DropHeader $header
     * @param int        $bytes  How much it takes on disk.
     */
    public function __construct(public string $id, public DropHeader $header, public int $bytes) {}
}
