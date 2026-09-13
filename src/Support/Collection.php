<?php

declare(strict_types=1);

namespace Phpanta\Support;

use ArrayIterator;
use Countable;
use Generator;
use IteratorAggregate;
use NoDiscard;
use Phpanta\Exception\CollectionException;
use Throwable;
use Traversable;

/**
 * The Collection class. A type-safe generic collection, held as a list.
 *
 * Immutable: {@link self::with()} returns a new instance rather than mutating this one, which is
 * what makes a collection safe to hold inside a readonly value object. `readonly` protects the
 * reference, not what it points at, so a mutable collection would leave every value object that
 * holds one appendable from anywhere holding it. Same shape as
 * {@link \Phpanta\Http\Security\ContentSecurityPolicy::allow()}, and named for it: `with` reads
 * as a copy where `add` would read as a mutation, so a discarded return value looks wrong.
 *
 * The store, the pending pipeline, the declared type, the type check and every query method live in
 * {@link TypedItems}, shared with {@link SearchableCollection} — see that trait for why it is a
 * trait and not a base class, and for how a chain of transformations comes to run in one pass. What
 * is here is what makes this one a **list**: `with()` appends, {@link self::sequenced()} renumbers,
 * and iteration yields integer keys.
 *
 * @template T
 * @implements IteratorAggregate<int, T>
 */
class Collection implements Countable, IteratorAggregate
{
    /** @use TypedItems<T> */
    use TypedItems;

    /**
     * Returns a copy of this collection with one or more items appended.
     *
     * Appending is a **store** operation, so any pending transformations are run first and the copy
     * starts from their results — which is what keeps `->map(…)->with($x)` meaning what it reads as,
     * with $x on the end of the mapped items rather than waiting behind a transformation that was
     * never meant to touch it.
     *
     * @param T ...$items
     * @return static
     * @throws CollectionException if any item is not an instance of the declared type. The copy is
     *                     discarded with the exception, so a rejected batch cannot half-apply.
     * @throws Throwable whatever a pending step throws, since running them comes first.
     */
    #[NoDiscard('with() copies rather than appends, so a call whose result goes nowhere does nothing')]
    public function with(mixed ...$items): static
    {
        $copy        = clone $this;
        $copy->items = $this->toArray();
        $copy->steps = [];

        foreach ($items as $item) {
            $this->guard($item);
            $copy->items[] = $item;
        }
        return $copy;
    }

    /**
     * $stream, renumbered from zero.
     *
     * The reindex is the whole reason this is not shared with {@link SearchableCollection}: a filter
     * that dropped the second of three items would otherwise leave `1 => …` missing — an array PHP
     * will still iterate and a `list<T>` it is not. It runs after every step rather than once at the
     * end, so a `map()` following a `where()` is handed `0, 1, 2` exactly as it was when `where()`
     * rebuilt an array eagerly.
     *
     * @param Generator<array-key, T> $stream
     * @return Generator<int, T>
     */
    private function sequenced(Generator $stream): Generator
    {
        $index = 0;

        foreach ($stream as $item) {
            yield $index++ => $item;
        }
    }

    /**
     * An empty list holding $type.
     *
     * It must answer with **this** class and not the other kind. {@link TypedItems::map()} writes
     * `$copy->items` across instances, which PHP allows only between instances of the class the
     * private member was declared in — so a sibling here is not a type error but a fatal, and the
     * obvious way to make a map answer with a list is the one thing this cannot be used for.
     *
     * @param class-string|'string'|'int'|'float'|'bool' $type
     * @return self
     */
    private function ofType(string $type): self
    {
        return new self($type);
    }

    /**
     * With nothing pending this is the store itself, which `with()` only ever appends to and so is
     * always a list; with a pipeline pending it is what that pipeline produces, renumbered by
     * {@link self::sequenced()}. The trait's store is typed `array-key` because it is shared with
     * the map, not because this one can grow holes.
     *
     * @return ArrayIterator<int, T>|Generator<int, T>
     */
    public function getIterator(): Traversable
    {
        return $this->steps === [] ? new ArrayIterator($this->items) : $this->stream();
    }
}
