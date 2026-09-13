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
 * The SearchableCollection class. A type-safe, string-keyed collection.
 *
 * Complements {@link Collection} (which is integer-indexed) with key-based storage
 * and lookup. Use when items must be retrievable by a named key (e.g. a URL slug).
 *
 * Immutable for the same reason and in the same way — see {@link Collection}.
 *
 * The store, the pending pipeline, the declared type, the type check and every query method live in
 * {@link TypedItems}, shared with {@link Collection}. What is here is what makes this one a **map**:
 * `with()` takes a key, `find()` exists at all, {@link self::sequenced()} keeps what
 * {@link Collection}'s throws away, and iteration yields that key alongside the item — which is the
 * whole reason a view can name each item by its slug while listing it.
 *
 * **A key comes out as the string it went in as**, including one PHP stores as an integer — see
 * {@link TypedItems::$castsKeys}.
 *
 * @template T
 * @implements IteratorAggregate<string, T>
 */
class SearchableCollection implements Countable, IteratorAggregate
{
    /** @use TypedItems<T> */
    use TypedItems;

    /**
     * Returns a copy of this collection with $item stored under $key.
     *
     * Storing is a **store** operation, so any pending transformations are run first — see
     * {@link Collection::with()}, which says why.
     *
     * @param string $key  The key to store the item under.
     * @param T      $item The item to store.
     * @return static
     * @throws CollectionException if $item is not an instance of the declared type.
     * @throws Throwable whatever a pending step throws, since running them comes first.
     */
    #[NoDiscard('with() copies rather than stores, so a call whose result goes nowhere does nothing')]
    public function with(string $key, mixed $item): static
    {
        $this->guard($item);

        $copy              = clone $this;
        $copy->items       = $this->toArray();
        $copy->steps       = [];
        $copy->items[$key] = $item;

        // Asked of the store rather than of $key: whether a key becomes an integer is PHP's rule,
        // and the array it has just been put into is the one thing that applies it exactly. The
        // last key is this one unless $key was already held — and then this was settled when it was.
        $copy->castsKeys = $this->castsKeys || !is_string(array_key_last($copy->items));

        return $copy;
    }

    /**
     * Finds an item by its key, or returns null if not found.
     *
     * @param string $key
     * @return T|null
     */
    #[NoDiscard('find() answers with an item and changes nothing, so a call whose result goes nowhere does nothing')]
    public function find(string $key): mixed
    {
        return $this->toArray()[$key] ?? null;
    }

    /**
     * $stream, exactly as it arrived.
     *
     * The counterpart to {@link Collection::sequenced()}, and the opposite decision: a map that lost
     * its keys on the way through a `where()` or a `map()` would have stopped being one.
     *
     * **A `return` rather than a `yield from`**, which is the difference between an identity and a
     * generator that copies a stream element by element. Written the obvious way this is the one
     * member on the whole site whose entire job is to cost something.
     *
     * @param Generator<array-key, T> $stream
     * @return Generator<array-key, T>
     */
    private function sequenced(Generator $stream): Generator
    {
        return $stream;
    }

    /**
     * An empty map holding $type.
     *
     * @param class-string|'string'|'int'|'float'|'bool' $type
     * @return self
     */
    private function ofType(string $type): self
    {
        return new self($type);
    }

    /**
     * @return ArrayIterator<string, T>|Generator<string, T>
     */
    public function getIterator(): Traversable
    {
        return $this->steps === [] && !$this->castsKeys ? new ArrayIterator($this->items) : $this->stream();
    }
}
