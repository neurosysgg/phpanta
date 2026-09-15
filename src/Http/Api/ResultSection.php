<?php

declare(strict_types=1);

namespace Phpanta\Http\Api;

use JsonSerializable;
use Phpanta\View\Html\Node;

/**
 * The ResultSection interface. One part of an admin answer, written three ways from one value.
 *
 * {@link \Phpanta\Model\Health\HealthSection} is the first and most of them — a caption and facts, or
 * a caption and lines. An answer that is more than a report — a directory, with a link for each entry
 * in it — is a section of its own that says the same three things: the text a terminal reads, the
 * part of a page a browser sees, and the data a script reads.
 *
 * **Its data keeps a section's shape.** What it serializes to carries a caption and either `facts` or
 * `lines`, as a {@link \Phpanta\Model\Health\HealthSection} does, so the signing commands can print
 * any section a server sends without knowing its kind; whatever else it says for a script sits beside
 * them under keys of its own.
 */
interface ResultSection extends JsonSerializable
{
    /**
     * The section as the text a terminal reads, with no trailing newline.
     *
     * @return string
     */
    public function render(): string;

    /**
     * The section as part of a page.
     *
     * @return Node
     */
    public function node(): Node;
}
