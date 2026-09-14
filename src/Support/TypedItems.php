<?php

declare(strict_types=1);

namespace Phpanta\Support;

use Generator;
use NoDiscard;
use Phpanta\Exception\CollectionException;
use ReflectionFunction;
use ReflectionNamedType;
use SplObjectStorage;

/**
 * The TypedItems trait. The store and the pipeline {@link Collection} and
 * {@link SearchableCollection} share.
 *
 * **A trait rather than a base class, and that is still the whole design decision here.** The two
 * collections are not substitutable and never should be: one is a list and one is a map, their
 * `with()` methods take different arguments, and no call site holds "either kind of
 * collection". A shared parent would announce a common type that nothing wants and nothing checks;
 * a trait announces shared plumbing, which is all this is. It is the first trait in the codebase
 * for that reason — the honest reading of `extends` would have been a claim, and the honest reading
 * of `use` is a copy.
 *
 * Two mechanical consequences follow from that choice and are worth knowing before touching it:
 *
 * - **`$items` and `$steps` stay `private`.** PHP flattens a trait's members into the using class,
 *   so each collection's `with()` can still write `$copy->items` — which a `private` member of a
 *   *parent* would have forbidden, forcing it to `protected` and opening the store to anything that
 *   ever inherited.
 * - **`static::class` still names the collection**, not this trait, so {@link self::guard()}'s
 *   message reads `Phpanta\Support\Collection expects …` exactly as it did when the `sprintf` sat
 *   in both files. `SupportTest` asserts that message, which is what would catch a later slip to
 *   `self::class`.
 *
 * ## Transforming is lazy; materialising is what runs
 *
 * {@link self::where()} and {@link self::map()} run **nothing**. Each appends a stream transformer
 * to a copy's {@link self::$steps} and returns; the callbacks are first called by a materialiser —
 * {@link self::toArray()}, {@link self::toValues()}, {@link self::toKeys()}, {@link self::first()},
 * {@link self::last()}, {@link self::join()}, {@link self::settled()}, `count()`,
 * {@link self::isEmpty()}, `getIterator()` — which folds every step over one pass of the source.
 *
 * **A callback that does work must therefore say where it runs**, which is what
 * {@link self::settled()} is for and the one rule this change added to the codebase. Everything
 * else here is pure, so when it runs cannot be observed.
 *
 * **One pass, fused.** `->where(even)->map(rename)` over `1..6` calls its callbacks in the order
 * `w1 w2 m2 w3 w4 m4 w5 w6 m6`: each element goes through the whole chain before the next is
 * touched, rather than the filter running to completion and handing an intermediate array to the
 * map. Nothing between the source and the answer is ever built. It also means the short-circuiting
 * materialisers genuinely short-circuit: `first()` on that chain stops after `w1 w2 m2`.
 *
 * **{@link self::stream()} builds a fresh generator every time it is asked**, which is what keeps a
 * pipeline re-iterable — the once-only trap of holding a `Generator` in a property does not apply,
 * and a nested `foreach` over the same collection works.
 *
 * **The fast path is not decoration.** With no steps pending, every materialiser reads `$items`
 * directly and builds no generator at all. Measured on the two-item collection
 * {@link \Phpanta\View\Html\Element} holds: `toValues()` with nothing pending is **0.45 µs**,
 * and materialising a one-step pipeline is **3.35 µs**. That gap is why the check is worth writing
 * — `Element::render()` touches two collections per element and a page is hundreds of elements,
 * every one of them eager. Laziness costs only where laziness is used.
 *
 * **What laziness does cost, where it is used, is written down rather than waved at.** The one hot
 * call site is `Element::renderChildren()`, which went from
 * `implode('', array_map(…))` at **2.29 µs** to `map(…)->join('')` at **5.16 µs** — 1.80 µs to
 * record the step and 3.35 µs to run it. Over a whole page that is **1.141 ms to 1.257 ms**, about
 * **10%** of the time spent rendering markup, and a fraction of a percent of a request. It buys a
 * chain that stays inside the type from end to end; if it ever stops being worth that,
 * `renderChildren()` is the one place to spend a `foreach` and the only one.
 *
 * **Nothing is memoised.** A pipeline materialised twice runs its callbacks twice. Every callback
 * here is pure and every source is a `readonly` value object, so both runs answer the same; a cache
 * would be the one mutable thing inside the class whose immutability is the reason it is safe to
 * hold inside every `readonly` value object in an app.
 *
 * ## The query methods
 *
 * They are the framework's default way of handling a group of things. They were written because `all()`
 * had become the escape hatch out of the type: sixteen call sites reached for it or hand-rolled a
 * `foreach`, and nine of those unwrapped the collection for no other purpose than to hand the array
 * to `array_map`. A collection that has to be unwrapped before it can be asked anything is a
 * collection that only types its construction.
 *
 * **The callback takes the value first and the key second.** That is the order PHP's own
 * `array_find`, `array_any` and `array_all` use — `Element::renderChildren()` already calls one —
 * and the order `ARRAY_FILTER_USE_BOTH` passes. It also costs nothing at the call sites that do not
 * want the key: PHP hands a userland callback extra arguments harmlessly, so
 * `$items->map(self::card(...))` stays a first-class callable rather than growing a closure
 * around it. Key-first would have broken every one of those.
 *
 * **What a collection may hold is anything with a type, not only an object.** The bound was
 * `T of object` for as long as {@link self::guard()} was a bare `instanceof`, which is a check only
 * an object can pass — so `list<string>`, `list<float>` and every `array_fill()` buffer stayed
 * outside the type for a reason that was about the check rather than about them. It is
 * `get_debug_type()` beside the `instanceof` now.
 *
 * **What deliberately does not follow it in is a buffer the caller owns.** Code whose contract is
 * genuine in-place mutation — an FFT, whose butterfly reads and writes four arbitrary indices of
 * two arrays per iteration — does what no immutable collection expresses and no per-element
 * callback can see. Measured at 512 floats a window, rebuilding a collection per window costs
 * 354 ms against `array_fill`'s 1 ms. Such code keeps arrays under a `#[BareArray]` that says so,
 * and that is a named exception rather than a hole in the type.
 *
 * @template T
 */
#[BareString('int', 'a scalar type name, spelled the way get_debug_type() spells it. A collection is declared by '
    . 'a class-string and PHP has no case for int; the vocabulary is the engine\'s rather than '
    . 'ours, and TypedItems::SCALARS is where it is written down.')]
#[BareString('string', 'a scalar type name; see the one on int above')]
trait TypedItems
{
    /**
     * The source. Whatever {@link self::$steps} have yet to be applied, this is what they run over.
     *
     * @var array<array-key, T>
     */
    #[BareArray(
        'the store the collection is. Holding a collection here is the definition eating itself, '
        . 'and it is the one array under src/ whose type argument is checked at every write '
        . 'instead of being declared.',
    )]
    private array $items = [];

    /**
     * The transformations {@link self::where()} and {@link self::map()} have recorded and nothing
     * has yet run, in the order they were asked for.
     *
     * Each is a stream transformer rather than a per-element callback, which is what lets one step
     * drop an element and another replace it without either knowing the difference — and what makes
     * the fold in {@link self::stream()} three lines instead of a dispatch on a step's kind.
     *
     * @var list<callable(iterable<array-key, mixed>): Generator<array-key, mixed>>
     */
    #[BareArray(
        'a list of closures, and a collection is declared by a class-string: callable is not one. '
        . 'It is also read on the hot path — the fast path is a === [] against it — where a '
        . 'collection would cost a construction per with().',
    )]
    private array $steps = [];

    /**
     * Whether a key this map holds reads as an integer, and so is stored as one.
     *
     * PHP turns a decimal-integer string key into an int in every array, and a map may not: a slug
     * of `2024` would come back out as the int `2024`, and a callback declaring a string key would
     * throw on it. Set by {@link SearchableCollection::with()}, the one place a key goes in, so the
     * reads — {@link self::source()}, {@link self::first()}, {@link self::toKeys()} and the map's
     * iterator — put the string back only where there is one to put back, and a map of names pays
     * nothing. {@link self::toArray()} cannot: an array is where the int came from.
     */
    private bool $castsKeys = false;

    /**
     * The scalar types a collection may be declared to hold, spelled as
     * {@link https://www.php.net/get_debug_type get_debug_type()} reports them.
     *
     * **`null` and `array` are deliberately absent.** A collection of nulls holds no information,
     * and a collection of arrays is the shape every one of these was written to replace — allowing
     * it would let the escape hatch back in under the type's own name. That refusal is load-bearing
     * rather than tidy: it is what forced {@link \Phpanta\Http\Security\CspSourceList} to exist,
     * and it is why a callback that wants to hand back a pair maps to a small value object — a
     * `JsonSerializable`, say — rather than to an `array`.
     */
    private const array SCALARS = ['string', 'int', 'float', 'bool'];

    /**
     * Constructs an instance of {@link self}.
     *
     * **The declared type is checked here, and that is the same move a value object makes when it
     * checks its own shape at its constructor.** {@link self::guard()} asks `instanceof`, which answers `false` for a
     * string naming no class rather than complaining about it — so without this check,
     * `new Collection('Reelase')` would not be an error but a collection that silently rejects
     * everything ever offered to it, reporting the typo as a `CollectionException` about the *item*. Naming
     * the fault where it is written is worth one `class_exists()`.
     *
     * `interface_exists()` is asked separately because `class_exists()` answers `false` for an
     * interface — `Collection(Node::class)` is one of the most common shapes here. Enums need no
     * third question: `class_exists()` already answers `true` for them.
     *
     * It is also the check {@link self::map()} leans on. A callback declaring `: void`, `: never`,
     * `: array`, `: object` or `: static` produces a type name that answers none of these three
     * questions, and the message below names it — so the whole vocabulary of return types a mapped
     * collection cannot be built from is refused in one place rather than listed in two.
     *
     * @param class-string<T>|'string'|'int'|'float'|'bool' $type What this collection holds: a
     *                        fully-qualified class, interface or enum name, or one of
     *                        {@link self::SCALARS}.
     * @throws CollectionException if $type names neither.
     */
    public function __construct(public readonly string $type)
    {
        if (
            !in_array($type, self::SCALARS, true)
            && !class_exists($type)
            && !interface_exists($type)
        ) {
            throw new CollectionException(sprintf(
                "%s cannot hold '%s': it names no class, interface or enum, and is not one of %s.",
                static::class,
                $type,
                implode(', ', self::SCALARS),
            ));
        }
    }

    /**
     * @return int
     */
    public function count(): int
    {
        return $this->steps === [] ? count($this->items) : iterator_count($this->stream());
    }

    /**
     * Whether this collection holds nothing.
     *
     * Here because the three call sites that asked were each spelling it differently — `count() > 0`,
     * `count() === 0`, `all() === []` — and the last of those had to unwrap the collection to ask a
     * question about the collection. It is also the one that caught a live bug rather than a latent
     * one: {@link \Phpanta\Http\ViewResponse::answer()} guarded its 304 with `$cache !== []`, which
     * is true of *every* collection object, so a gated page answered 304 to a guessed validator.
     *
     * Stops at the first element a pending pipeline produces rather than running it out, which is
     * the whole point of asking this instead of `count() === 0`.
     *
     * @return bool
     */
    #[NoDiscard('isEmpty() answers a question and changes nothing, so a call whose result goes nowhere does nothing')]
    public function isEmpty(): bool
    {
        return $this->steps === [] ? $this->items === [] : !$this->stream()->valid();
    }

    /**
     * A copy holding only the items $predicate accepts.
     *
     * Records the filter and returns; $predicate is not called until something materialises. Copies
     * rather than filters in place for the reason {@link Collection::with()} gives: a collection
     * held inside a `readonly` value object is only as immutable as its own methods make it.
     *
     * Answers `static` rather than `self`, so a subclass survives it —
     * {@link \Phpanta\Http\Security\CspSourceList} filtered by directive is still a source list.
     * {@link self::map()} is the opposite case and says why.
     *
     * @param callable(T, array-key): bool $predicate Takes the item, then its key.
     * @return static
     */
    #[NoDiscard('where() returns a copy holding the matches; the collection it was called on is unchanged')]
    public function where(callable $predicate): static
    {
        $copy        = clone $this;
        $copy->steps = [
            ...$this->steps,
            static function (iterable $stream) use ($predicate): Generator {
                foreach ($stream as $key => $item) {
                    if ($predicate($item, $key)) {
                        yield $key => $item;
                    }
                }
            },
            // The only step that can put holes in a list, so the only one a list has to be
            // renumbered after. A map() preserves its keys, so resequencing one would be a
            // generator layer per step doing nothing — see self::stream().
            $this->sequenced(...),
        ];

        return $copy;
    }

    /**
     * A copy holding the first of each item and none of its repeats.
     *
     * Records the step and returns; nothing is compared until something materialises. Answers
     * `static` for {@link self::where()}'s reason — dropping repeats does not change what is held,
     * so a subclass survives it — and ends in {@link self::sequenced()} for {@link self::where()}'s
     * *other* reason: this is the second step that can put holes in a list.
     *
     * **It compares by strict identity, which is not what `array_unique()` does**, and the
     * difference is worth knowing before reaching for it. `array_unique()` compares the items as
     * *strings* by default, so it calls `1` and `'1'` and `1.0` the same item; this calls them
     * three items, because `===` does. The one place that shows is a collection declared `float`,
     * which accepts an `int` — the one widening the language itself makes — where `1` and `1.0` are
     * both kept. Matching `array_unique()` instead would mean inventing an equality this codebase
     * does not otherwise have.
     *
     * **An object is compared by identity and not by value**, for the same reason and a stronger
     * one: a value object here declares no equality, so the only honest question about two of them
     * is whether they are the same object. A caller wanting value equality maps to the value first,
     * which is what {@link \Phpanta\Http\Security\ContentSecurityPolicy::hosts()} does: it reduces
     * its sources to their origins, and asks this afterwards.
     *
     * @return static
     */
    #[NoDiscard('unique() returns a copy with the repeats dropped; the one it was called on keeps them')]
    public function unique(): static
    {
        $copy        = clone $this;
        $copy->steps = [
            ...$this->steps,
            static function (iterable $stream): Generator {
                $objects = new SplObjectStorage();
                $marks   = [];

                foreach ($stream as $key => $item) {
                    if (is_object($item)) {
                        // Held rather than numbered. spl_object_id() hands a freed object's id to the
                        // next object made, and a map() step makes a fresh object per element and
                        // lets the last one go — so a new object could arrive under a seen id and be
                        // dropped as a repeat of one that no longer exists.
                        if (isset($objects[$item])) {
                            continue;
                        }

                        $objects[$item] = true;
                    } elseif (!is_float($item) || !is_nan($item)) {
                        // The type is part of the mark, so `1` and `'1'` do not collide the way they
                        // would as bare array keys — and a float cannot be an array key at all without
                        // being truncated first, which would call 1.5 and 1.9 the same number. `-0.0`
                        // is `=== 0.0`, so it is marked as 0.0; NAN is `===` to nothing, itself
                        // included, so it is never a repeat and never marked.
                        $mark = get_debug_type($item) . "\0" . var_export($item === 0.0 ? 0.0 : $item, true);

                        if (isset($marks[$mark])) {
                            continue;
                        }

                        $marks[$mark] = true;
                    }

                    yield $key => $item;
                }
            },
            $this->sequenced(...),
        ];

        return $copy;
    }
    /**
     * A collection of every item put through $callback.
     *
     * Records the transformation and returns; $callback is not called until something materialises.
     *
     * **The element type is read off $callback's own return declaration**, which is why this takes
     * no type argument. A `class-string` parameter beside a callback that already declares
     * `: string` would be the same fact written twice, and the second copy is the one that goes
     * stale. Stating it once also puts it where
     * PHP itself enforces it, which is the stronger of the two checks: a callback that returns the
     * wrong thing is a `TypeError` at the `return`, naming the function, before this class sees the
     * value at all.
     *
     * **So a mapped item is not {@link self::guard()}ed, and that is provable rather than lax.**
     * The declared type *is* the callback's return declaration, so guarding would re-ask, once per
     * element, a question the language has already answered.
     *
     * A callback with no declared return type, or a union, intersection or nullable one, throws
     * here — at the `map()` call, where the callback is written, rather than at some item further
     * down. Everything else a return type can say (`void`, `never`, `array`, `object`, `static`)
     * is refused by the constructor, which names it.
     *
     * **Answers the base shape rather than `static`**, unlike {@link self::where()}: a mapped
     * {@link \Phpanta\Http\Security\CspSourceList} holds strings rather than `CspSource`s, so it is
     * a `Collection` and calling it a source list would be a lie. A list maps to a list and a map
     * maps to a map — {@link self::ofType()} is what knows which.
     *
     * **A map keeps its keys.** Reindexing would be the implementation talking — `array_map` given
     * two arrays returns one — and a `SearchableCollection` is a map: the whole reason a view can
     * name each item by its slug while mapping it is that it stays one. See docs/history/types.md.
     * The consequence to know is that its result cannot be spread into a call — string keys
     * become named arguments — so a call site that spreads asks {@link self::toValues()} for a list
     * and says so.
     *
     * @template TOut
     * @param callable(T, array-key): TOut $callback Takes the item, then its key. Must declare a
     *                        plain return type; that type is what the new collection holds.
     * @return self<TOut>
     * @throws CollectionException if $callback declares no return type, or one this cannot name.
     */
    #[NoDiscard('map() answers with a new collection and changes nothing, so a dropped result does nothing')]
    public function map(callable $callback): self
    {
        $copy        = $this->ofType(self::mappedType($callback));
        $copy->items     = $this->items;
        $copy->castsKeys = $this->castsKeys;
        $copy->steps     = [
            ...$this->steps,
            static function (iterable $stream) use ($callback): Generator {
                foreach ($stream as $key => $item) {
                    yield $key => $callback($item, $key);
                }
            },
        ];

        return $copy;
    }

    /**
     * Every item, joined by $glue.
     *
     * Takes no callback. It had one until {@link self::map()} started answering with a collection,
     * and the pair was written inside-out — the separator first, applying last, with the collection
     * being joined furthest from the word saying what happens to it. `->map(…)->join(', ')` reads in
     * the order it runs.
     *
     * No default glue. `implode()` has one — the empty string — and it is the answer to a question
     * nobody asks on purpose.
     *
     * @param string $glue
     * @return string
     * @throws CollectionException if this collection does not hold strings.
     */
    #[NoDiscard('join() answers with a string and changes nothing, so a call whose result goes nowhere does nothing')]
    public function join(string $glue): string
    {
        if ($this->type !== 'string') {
            throw new CollectionException(sprintf(
                "%s holds %s, which cannot be joined: map() it to a string first.",
                static::class,
                $this->type,
            ));
        }

        return implode($glue, $this->toValues());
    }

    /**
     * The first item $predicate accepts, or the first item at all, or null for neither.
     *
     * Replaces the `foreach`-and-return a search over a collection is otherwise
     * written as. Null rather than an exception for the same reason `find()` answers null: not
     * finding something is a normal answer to a search.
     *
     * Stops at the match. On a pending pipeline that means the steps run for the elements up to it
     * and for no others.
     *
     * @param (callable(T, array-key): bool)|null $predicate
     * @return T|null
     */
    #[NoDiscard('first() answers with an item and changes nothing, so a call whose result goes nowhere does nothing')]
    public function first(?callable $predicate = null): mixed
    {
        if ($this->steps === [] && !$this->castsKeys) {
            return array_find($this->items, $predicate ?? static fn(): bool => true);
        }

        foreach ($this->stream() as $key => $item) {
            if ($predicate === null || $predicate($item, $key)) {
                return $item;
            }
        }

        return null;
    }

    /**
     * The last item, or null for an empty collection.
     *
     * Here because a caller wanting the last item would otherwise unwrap the collection to get at
     * an array — not to do anything with the array, but because `end()` is the only way to ask an
     * array this question.
     *
     * `array_key_last()` rather than `end()`: `end()` moves the array's internal pointer, which is
     * a write to the store from a method that promises to be a read.
     *
     * **No predicate, unlike {@link self::first()}**, and the asymmetry is deliberate rather than
     * an omission. `first()` has one because finding a match is a search from the front;
     * nothing here searches backwards. PHP gives `array_find()` and no `array_find_last()`,
     * so the predicate form would be a hand-rolled reverse loop written for nobody — and the day
     * something wants one, `where(…)->last()` already answers it.
     *
     * The one materialiser that cannot short-circuit: the far end of a stream is only knowable by
     * reaching it.
     *
     * @return T|null
     */
    #[NoDiscard('last() answers with an item and changes nothing, so a call whose result goes nowhere does nothing')]
    public function last(): mixed
    {
        $items = $this->toArray();

        return $items === [] ? null : $items[array_key_last($items)];
    }

    /**
     * A copy with every pending step already run.
     *
     * **The one member laziness made necessary, and it exists for callbacks that do work.** Most
     * callbacks are pure, so when they run cannot be observed; the exception is a predicate that
     * acts — a `where()` inside a method called `write()` that converts each file it is given and
     * reports whether that worked. Left pending, that filter is a
     * method called `write()` which writes nothing: the first thing to ask a question runs it, an
     * {@link self::isEmpty()} stops at the first failure with every source behind it unstaged, and
     * a `foreach` over the answer encodes them all a second time.
     *
     * So a predicate or callback with a side effect is `settled()` where it is written, and the
     * rule that goes with it is short: **a step that does work runs where it is asked for.** This
     * is what says so out loud, which `with(...$collection->toValues())` — the same thing spelled
     * by hand — does not, and which also re-states the element type the collection already knows.
     *
     * Keeps `static`, so a subclass survives it: settling changes when the work happens and not
     * what the collection holds.
     *
     * @return static
     */
    #[NoDiscard('settled() answers with a copy; the collection it was called on still has its steps pending')]
    public function settled(): static
    {
        if ($this->steps === []) {
            return $this;
        }

        $copy        = clone $this;
        $copy->items = $this->toArray();
        $copy->steps = [];

        return $copy;
    }

    /**
     * Everything this collection holds, keyed as it keys things.
     *
     * Integer positions for a {@link Collection}, which is what makes it the same answer as
     * {@link self::toValues()}; names for a {@link SearchableCollection}, which is what it is for.
     * Replaces `all()`, which said nothing about which of the two you were getting.
     *
     * This is the door. Everything above it stays inside the type; an `array` crossing this line is
     * the adapter {@link \Phpanta\Support\Directory::files()} has always been the model for.
     *
     * @return array<array-key, T>
     */
    #[NoDiscard('toArray() answers with a new array; dropping it ran the whole pending chain for nothing')]
    #[BareArray('the door itself, named for what it hands over')]
    public function toArray(): array
    {
        return $this->steps === [] ? $this->items : iterator_to_array($this->stream());
    }

    /**
     * Everything this collection holds, as a list.
     *
     * The same as {@link self::toArray()} for a list and the key-dropping half of it for a map. It
     * is what a call site spreading into `containing(...)` asks for, and asking is the point: a
     * string-keyed array spread into a call is a set of named arguments, not a list, so the shape
     * that cannot be spread has to be turned into one out loud.
     *
     * @return list<T>
     */
    #[NoDiscard('toValues() answers with a new list; dropping it ran the whole pending chain for nothing')]
    #[BareArray('the door itself, named for what it hands over')]
    public function toValues(): array
    {
        return $this->steps === []
            ? array_values($this->items)
            : iterator_to_array($this->stream(), false);
    }

    /**
     * The keys, in insertion order.
     *
     * Integers for a {@link Collection} and strings for a {@link SearchableCollection}, which is why
     * this is one of the few members here whose usefulness is entirely on one side: only the map has
     * keys worth reading.
     *
     * @return list<array-key>
     */
    #[NoDiscard('toKeys() answers with a new list; dropping it ran the whole pending chain for nothing')]
    #[BareArray('the door itself, named for what it hands over')]
    public function toKeys(): array
    {
        if (!$this->castsKeys) {
            return array_keys($this->toArray());
        }

        // Read off the stream, which hands each key back as the string it went in as; the store's
        // own keys are the ints PHP made of them.
        $keys = [];

        foreach ($this->stream() as $key => $item) {
            $keys[] = $key;
        }

        return $keys;
    }

    /**
     * One pass over the source with every pending step folded over it.
     *
     * Built fresh on each call rather than held in a property, which is what makes a pipeline
     * re-iterable: a `Generator` is exhausted once, a method that returns one is not.
     *
     * **The fold starts at the array, not at a generator over it.** Every step takes an `iterable`
     * and `foreach` reads an array as happily as a stream, so a one-step chain — which is most of
     * them, and is what `Element::renderChildren()` runs — allocates one generator rather than two.
     * The wrapper it replaces bought nothing but symmetry.
     *
     * A plain fold, and deliberately not one that resequences between steps. {@link self::where()}
     * pushes {@link self::sequenced()} behind its own filter, because a filter is the only thing
     * that can put holes in a list; a `map()` keeps the keys it was given. Resequencing after every
     * step instead — which is how this was first written — spent a whole generator layer per step
     * to renumber keys that were already in order.
     *
     * @return Generator<array-key, T>
     */
    private function stream(): Generator
    {
        $stream = $this->source();

        foreach ($this->steps as $step) {
            $stream = $step($stream);
        }

        return $stream;
    }

    /**
     * The store as a pass reads it: the array itself, or — for a map holding a key PHP made an
     * integer — the same items with each key put back into a string. See {@link self::$castsKeys}.
     *
     * @return iterable<array-key, T>
     */
    private function source(): iterable
    {
        return $this->castsKeys ? self::stringKeyed($this->items) : $this->items;
    }

    /**
     * $items, with every key a string.
     *
     * @param array<array-key, T> $items
     * @return Generator<string, T>
     */
    #[BareArray('the store itself, handed over by source() to be re-keyed on the way out')]
    private static function stringKeyed(array $items): Generator
    {
        foreach ($items as $key => $item) {
            yield (string) $key => $item;
        }
    }

    /**
     * $stream, keyed the way this kind of collection keys things.
     *
     * Abstract because it is the one thing {@link self::where()} cannot decide for both: a
     * `Collection` is a `list<T>` and a
     * filter leaves the holes a list may not have, so it renumbers; a `SearchableCollection` keeps
     * its keys, which is what it is for — and its implementation is therefore not a generator at
     * all but a `return $stream`, which is what keeps a map's `where()` from paying for a layer
     * that would hand back exactly what it was given.
     *
     * Private rather than protected, for the reason `$items` is: PHP flattens a trait's members into
     * the using class, so {@link self::where()} can reach it without any of this becoming a surface
     * something outside could implement differently.
     *
     * @param Generator<array-key, T> $stream Always {@link self::where()}'s own filter step, which
     *                        is what lets this take the narrow type and lets the map be an identity.
     * @return Generator<array-key, T>
     */
    abstract private function sequenced(Generator $stream): Generator;

    /**
     * An empty collection of this same kind holding $type.
     *
     * The other half of what {@link self::map()} cannot decide for both — a list maps to a list and
     * a map maps to a map — and the reason `map()` cannot simply `clone`: `$type` is `readonly`, so
     * a collection holding something else has to be constructed rather than copied.
     *
     * `self` rather than `static`, so a subclass is left behind: `new static($type)` would have to
     * guess a subclass's constructor signature, and
     * {@link \Phpanta\Http\Security\CspSourceList} takes no argument at all precisely because
     * naming its element type is its whole reason to exist.
     *
     * @param class-string|'string'|'int'|'float'|'bool' $type
     * @return self
     */
    abstract private function ofType(string $type): self;

    /**
     * The element type a collection of $callback's results holds.
     *
     * `$callback(...)` normalises anything callable to a `Closure` first, which is what makes this
     * work for a plain arrow function, a first-class callable of a **private** method
     * (`$this->row(...)`), a static one (`self::card(...)`) and an internal function alike. A
     * `: static` return is read off the class the callable was called on.
     *
     * A union or intersection type is not a `ReflectionNamedType`; a nullable one is, and so is
     * `mixed`, both of which report `allowsNull()`. All three are refused rather than guessed at:
     * a collection cannot hold null, and picking one arm of a union would be this class inventing a
     * fact rather than reading one.
     *
     * @param callable $callback
     * @return string
     * @throws CollectionException if $callback declares no usable return type.
     */
    private static function mappedType(callable $callback): string
    {
        $function = new ReflectionFunction($callback(...));
        $declared = $function->getReturnType();

        if (!$declared instanceof ReflectionNamedType) {
            throw new CollectionException(sprintf(
                "map()'s callback must declare a plain return type: %s.",
                $declared === null ? 'this one declares none' : "'{$declared}' is a union or intersection",
            ));
        }

        if ($declared->allowsNull()) {
            throw new CollectionException(sprintf(
                "map()'s callback must declare a return type that is not nullable; '%s' is. A "
                . 'collection cannot hold null.',
                $declared,
            ));
        }

        // PHP resolves `self` and `parent` for the reflection; `static` it leaves as the word, and
        // what the word means is the class the callable was called on.
        return $declared->getName() === 'static'
            ? $function->getClosureCalledClass()->getName()
            : $declared->getName();
    }

    /**
     * Throws unless $item is one of the things this collection was declared to hold.
     *
     * The one check a PHP generic cannot make. `@template T` is a docblock, erased at runtime, so
     * `$this->type` is the only thing that actually knows — which is also why it is a public
     * readonly property rather than an implementation detail: a value object handed a collection
     * reads it to check the *element* type of what it was given.
     *
     * Called by `with()` and by nothing else. {@link self::map()} says why the items it produces
     * need no guard.
     *
     * **`instanceof` is asked first and answers `false` rather than throwing** when `$this->type`
     * names a scalar — that is PHP's own behaviour for a non-class string, and it is what lets the
     * two questions sit in one expression. The scalar half compares `get_debug_type()`, which is
     * also what the error message below reports, so a rejection names the two types in the same
     * vocabulary the constructor accepted.
     *
     * **`int` satisfies `float`, and nothing else widens.** That is the one coercion PHP itself
     * makes under `declare(strict_types=1)` — a `float` parameter takes an `int` — so a collection
     * that refused `array_fill(0, 512, 0)` would be stricter than the language it is written in.
     * The value is kept as it arrived rather than cast, exactly as a parameter would.
     *
     * @param T $item
     * @return void
     * @throws CollectionException if it is not.
     */
    private function guard(mixed $item): void
    {
        if ($item instanceof $this->type) {
            return;
        }

        $actual = get_debug_type($item);

        if ($actual === $this->type || ($this->type === 'float' && $actual === 'int')) {
            return;
        }

        throw new CollectionException(sprintf(
            '%s expects %s, got %s',
            static::class,
            $this->type,
            $actual,
        ));
    }
}
