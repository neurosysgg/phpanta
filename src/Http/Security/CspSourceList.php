<?php

declare(strict_types=1);

namespace Phpanta\Http\Security;

use Phpanta\Support\Collection;

/**
 * The CspSourceList class. One directive's source list, as a collection that names its own element.
 *
 * **This exists because PHP has no nested generics, and it is the one place in the framework where that
 * costs something.** {@link ContentSecurityPolicy} holds source lists keyed by directive — a map of
 * lists — and a collection is defined by a `class-string`, so the outer
 * {@link \Phpanta\Support\SearchableCollection} has to be told what its *values* are. Told
 * `Collection::class` it would check only that each value is some collection, not that it is a
 * collection of {@link CspSource} — which is the entire question worth asking about a policy.
 *
 * Naming the inner list recovers that. `SearchableCollection(CspSourceList::class)` checks the
 * outer, `CspSourceList` checks the inner, and neither has to describe the other.
 *
 * **It is also what falls out of {@link \Phpanta\Support\TypedItems::SCALARS} refusing `array`.**
 * A collection of arrays would have let the map keep its `list<CspSource>` values untyped under a
 * collection's name, which is the escape hatch that constant exists to close. Refusing it forced
 * the honest structure, and the honest structure is this file.
 *
 * @extends Collection<CspSource>
 */
final class CspSourceList extends Collection
{
    /**
     * Constructs an empty source list.
     *
     * No `$type` parameter, unlike its parent: naming the element type is this class's whole
     * reason to exist, so leaving it to a caller would give it away again.
     */
    public function __construct()
    {
        parent::__construct(CspSource::class);
    }
}
