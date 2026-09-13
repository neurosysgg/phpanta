<?php

declare(strict_types=1);

namespace Phpanta\Http\Api;

/**
 * The ResultKey enum. Every key an admin answer written as data carries — an {@link ApiResult} or an
 * {@link ApiListing}.
 *
 * One vocabulary for both sides of the wire: {@link ApiResult}, {@link ApiListing},
 * {@link ListingEntry}, {@link \Phpanta\Model\Health\HealthSection} and
 * {@link \Phpanta\Model\Health\HealthFact} write these keys, and the signing CLI's readers look them
 * up by the same cases — so a key renamed on one side is renamed on the other, rather than a field
 * that silently stops arriving.
 */
enum ResultKey: string
{
    /** The result's status code, as a number. */
    case Status = 'status';

    /** The result's sections, in order. */
    case Sections = 'sections';

    /** A section's caption, or null for a block with none. */
    case Caption = 'caption';

    /** A section's facts — present exactly when the section holds facts. */
    case Facts = 'facts';

    /** A section's lines — present exactly when the section holds lines. */
    case Lines = 'lines';

    /** A fact's name. */
    case Name = 'name';

    /** A fact's value, empty where there is none. */
    case Value = 'value';

    /** A fact's verdict — present only where a health check made one. */
    case Verdict = 'verdict';

    /** The address a listing lists. */
    case Address = 'address';

    /** A listing's entries, in order. */
    case Entries = 'entries';

    /** Where an entry is. */
    case Href = 'href';

    /** What an entry is for. */
    case Description = 'description';

    /** The one method an action answers on. */
    case Method = 'method';

    /** Whether an action writes. */
    case Writes = 'writes';

    /** Whether a browser may run an action. */
    case Browser = 'browser';

    /** The fields an action takes. */
    case Fields = 'fields';
}
