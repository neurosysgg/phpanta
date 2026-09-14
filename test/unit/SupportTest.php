<?php

declare(strict_types=1);

namespace Phpanta\Test\Unit;

use ArrayObject;
use DateTime;
use DateTimeImmutable;
use Generator;
use Phpanta\Exception\CollectionException;
use Phpanta\Exception\FilesystemException;
use Phpanta\Exception\InvalidValueException;
use Phpanta\Http\Security\CspSource;
use Phpanta\Http\Security\CspSourceList;
use Phpanta\Support\Collection;
use Phpanta\Support\Diagnostics;
use Phpanta\Support\Directory;
use Phpanta\Support\ErrorLog;
use Phpanta\Support\File;
use Phpanta\Support\SearchableCollection;
use Phpanta\Support\TypedItems;
use Phpanta\Text\Language;
use Phpanta\View\Html\Node;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversTrait;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use stdClass;
use TypeError;

#[CoversClass(Collection::class)]
#[CoversClass(SearchableCollection::class)]
#[CoversTrait(TypedItems::class)]
#[CoversClass(File::class)]
#[CoversClass(Diagnostics::class)]
#[CoversClass(Directory::class)]
#[CoversClass(ErrorLog::class)]
final class SupportTest extends TestCase
{
    // ───────────────────────────── Collection ─────────────────────────────

    /**
     * @return void
     */
    public function testStartsEmpty(): void
    {
        $collection = new Collection(stdClass::class);

        self::assertCount(0, $collection);
        self::assertSame([], $collection->toArray());
    }

    /**
     * @return void
     */
    public function testAddsItemsAndPreservesOrder(): void
    {
        $a = new stdClass();
        $b = new stdClass();

        $collection = new Collection(stdClass::class)->with($a, $b);

        self::assertCount(2, $collection);
        self::assertSame([$a, $b], $collection->toArray());
    }

    /**
     * @return void
     */
    public function testWithReturnsACopyAndLeavesTheOriginalEmpty(): void
    {
        $collection = new Collection(stdClass::class);
        $extended   = $collection->with(new stdClass());

        self::assertNotSame($collection, $extended);
        self::assertCount(0, $collection);
        self::assertCount(1, $extended);
    }

    /**
     * The reason the collections are immutable: readonly protects the reference, not what it points
     * at. A mutable collection would make every readonly value object holding one appendable by
     * anyone who can reach it.
     *
     * @return void
     */
    public function testACollectionInsideAReadonlyObjectCannotBeAppendedTo(): void
    {
        $fixture = new ReadonlyFixture(new Collection('string')->with('first'));

        (void) $fixture->items->with('second');

        self::assertCount(1, $fixture->items);
    }

    /**
     * @return void
     */
    public function testIsIterable(): void
    {
        $items = [new stdClass(), new stdClass()];

        self::assertSame($items, iterator_to_array(new Collection(stdClass::class)->with(...$items)));
    }

    /**
     * @return void
     */
    public function testRejectsAnItemOfTheWrongType(): void
    {
        $this->expectException(TypeError::class);
        (void) new Collection(DateTime::class)->with(new stdClass());
    }

    /**
     * @return void
     */
    public function testRejectsAScalar(): void
    {
        $this->expectException(TypeError::class);
        (void) new Collection(stdClass::class)->with('not an object');
    }

    /**
     * @return void
     */
    public function testTheTypeErrorNamesBothTypes(): void
    {
        $this->expectException(TypeError::class);
        $this->expectExceptionMessageIsOrContains(DateTime::class);
        (void) new Collection(DateTime::class)->with(new stdClass());
    }

    /**
     * The copy is discarded with the exception, so the good items in a bad batch go with it.
     *
     * @return void
     */
    public function testARejectedBatchLeavesTheOriginalUntouched(): void
    {
        $collection = new Collection(stdClass::class)->with(new stdClass());

        try {
            (void) $collection->with(new stdClass(), 'not an object');
        } catch (TypeError) {
            // expected
        }

        self::assertCount(1, $collection);
    }

    /**
     * @return void
     */
    public function testAcceptsSubclassesOfTheDeclaredType(): void
    {
        $collection = new Collection(ArrayObject::class)->with(new class () extends ArrayObject {});

        self::assertCount(1, $collection);
    }

    /**
     * @return void
     */
    public function testExposesItsDeclaredType(): void
    {
        self::assertSame(stdClass::class, new Collection(stdClass::class)->type);
    }

    // ─────────────────────────── scalar collections ───────────────────────────

    /**
     * @return void
     */
    public function testHoldsScalarsOfTheDeclaredType(): void
    {
        self::assertSame(['a', 'b'], new Collection('string')->with('a', 'b')->toArray());
        self::assertSame([1, 2], new Collection('int')->with(1, 2)->toArray());
        self::assertSame([1.5], new Collection('float')->with(1.5)->toArray());
        self::assertSame([true, false], new Collection('bool')->with(true, false)->toArray());
    }

    /**
     * @return void
     */
    public function testRejectsAScalarOfTheWrongType(): void
    {
        $this->expectException(TypeError::class);
        $this->expectExceptionMessageIsOrContains('expects string, got int');
        (void) new Collection('string')->with(1);
    }

    /**
     * @return void
     */
    public function testRejectsAnObjectInAScalarCollection(): void
    {
        $this->expectException(TypeError::class);
        (void) new Collection('int')->with(new stdClass());
    }

    /**
     * `int` satisfies `float` because that is the one widening PHP itself performs under
     * `declare(strict_types=1)`; a collection stricter than the language would refuse
     * `array_fill(0, 512, 0)`.
     *
     * @return void
     */
    public function testAnIntSatisfiesAFloatCollection(): void
    {
        self::assertSame([0, 1.5], new Collection('float')->with(0, 1.5)->toArray());
    }

    /**
     * The widening is one-way, exactly as a parameter's is.
     *
     * @return void
     */
    public function testAFloatDoesNotSatisfyAnIntCollection(): void
    {
        $this->expectException(TypeError::class);
        (void) new Collection('int')->with(1.5);
    }

    /**
     * @return void
     */
    public function testScalarsWorkInASearchableCollectionToo(): void
    {
        self::assertSame('v', new SearchableCollection('string')->with('k', 'v')->find('k'));
    }

    // ────────────────────── the declared type is checked ──────────────────────

    /**
     * The fault this exists for: `instanceof` answers `false` for a string naming no class, so a
     * misspelled type would be a collection that silently rejects everything.
     *
     * @return void
     */
    public function testRefusesATypeThatNamesNothing(): void
    {
        $this->expectException(TypeError::class);
        $this->expectExceptionMessageIsOrContains('Reelase');
        (void) new Collection('Reelase');
    }

    /**
     * @return void
     */
    public function testRefusesNullAndArrayAsDeclaredTypes(): void
    {
        $refused = 0;

        foreach (['null', 'array', 'mixed', 'iterable', ''] as $type) {
            try {
                (void) new Collection($type);
            } catch (TypeError) {
                $refused++;
            }
        }

        self::assertSame(5, $refused);
    }

    /**
     * `class_exists()` answers false for an interface, so the constructor has to ask twice.
     *
     * @return void
     */
    public function testAcceptsAnInterfaceAsItsDeclaredType(): void
    {
        self::assertSame(Node::class, new Collection(Node::class)->type);
    }

    /**
     * Enums need no third question — `class_exists()` already answers true for them.
     *
     * @return void
     */
    public function testAcceptsAnEnumAsItsDeclaredType(): void
    {
        self::assertCount(1, new Collection(Language::class)->with(Language::English));
    }

    /**
     * @return void
     */
    public function testTheRefusalNamesTheCollectionAndTheScalarsItWouldAccept(): void
    {
        $this->expectException(TypeError::class);
        $this->expectExceptionMessageIsOrContains(SearchableCollection::class);
        (void) new SearchableCollection('Nope');
    }

    // ───────────────────────── SearchableCollection ─────────────────────────

    /**
     * @return void
     */
    public function testFindReturnsNullForAnUnknownKey(): void
    {
        self::assertNull(new SearchableCollection(stdClass::class)->find('nope'));
    }

    /**
     * @return void
     */
    public function testFindReturnsTheItemStoredUnderAKey(): void
    {
        $item = new stdClass();

        self::assertSame($item, new SearchableCollection(stdClass::class)->with('k', $item)->find('k'));
    }

    /**
     * @return void
     */
    public function testAddingTheSameKeyTwiceReplacesTheItem(): void
    {
        $second = new stdClass();

        $collection = new SearchableCollection(stdClass::class)
            ->with('k', new stdClass())
            ->with('k', $second);

        self::assertCount(1, $collection);
        self::assertSame($second, $collection->find('k'));
    }

    /**
     * @return void
     */
    public function testIteratesAsKeyValuePairs(): void
    {
        $a = new stdClass();
        $b = new stdClass();

        $collection = new SearchableCollection(stdClass::class)->with('a', $a)->with('b', $b);

        self::assertSame(['a' => $a, 'b' => $b], iterator_to_array($collection));
    }

    /**
     * @return void
     */
    public function testSearchableRejectsAnItemOfTheWrongType(): void
    {
        $this->expectException(TypeError::class);
        (void) new SearchableCollection(DateTime::class)->with('k', new stdClass());
    }

    /**
     * @return void
     */
    public function testKeysWithSlashesAndDotsAreJustKeys(): void
    {
        $item = new stdClass();

        $collection = new SearchableCollection(stdClass::class)->with('../../etc/passwd', $item);

        self::assertSame($item, $collection->find('../../etc/passwd'));
        self::assertNull($collection->find('etc/passwd'));
    }

    /**
     * all() hands back the keyed map rather than a list — the key is the item's name, and a caller
     * iterating the map reads each one's name from it.
     *
     * @return void
     */
    public function testASearchableCollectionHandsBackItsItemsKeyed(): void
    {
        $a = new stdClass();
        $b = new stdClass();

        $collection = new SearchableCollection(stdClass::class)->with('a', $a)->with('b', $b);

        self::assertSame(['a' => $a, 'b' => $b], $collection->toArray());
    }

    /**
     * @return void
     */
    public function testAnEmptySearchableCollectionHandsBackAnEmptyArray(): void
    {
        self::assertSame([], new SearchableCollection(stdClass::class)->toArray());
    }
    // ─────────────────────────── The query methods ───────────────────────────

    /**
     * The store both collections share, and so the one place the six query methods are written.
     *
     * They are exercised through both classes rather than through one, because the two differ in
     * exactly the way each class's own sequencing exists to handle: a list has to be reindexed
     * after a filter and a map has to keep its keys. Everything else here is shared by construction.
     *
     * @return void
     */
    public function testIsEmptyAnswersForBothShapes(): void
    {
        self::assertTrue(new Collection(stdClass::class)->isEmpty());
        self::assertTrue(new SearchableCollection(stdClass::class)->isEmpty());
        self::assertFalse(new Collection(stdClass::class)->with(new stdClass())->isEmpty());
        self::assertFalse(new SearchableCollection(stdClass::class)->with('k', new stdClass())->isEmpty());
    }

    /**
     * @return void
     */
    public function testWhereKeepsOnlyTheMatches(): void
    {
        [$a, $b, $c] = [self::numbered(1), self::numbered(2), self::numbered(3)];

        $kept = new Collection(stdClass::class)
            ->with($a, $b, $c)
            ->where(static fn(stdClass $item): bool => $item->n !== 2);

        self::assertSame([$a, $c], $kept->toArray());
    }

    /**
     * A `Collection` is a `list<T>`, and `array_filter` preserves keys — so dropping the middle item
     * of three would leave `[0 => …, 2 => …]` if nothing reindexed. This is the assertion that says
     * something does.
     *
     * @return void
     */
    public function testWhereReindexesAList(): void
    {
        $kept = new Collection(stdClass::class)
            ->with(self::numbered(1), self::numbered(2), self::numbered(3))
            ->where(static fn(stdClass $item): bool => $item->n !== 2);

        self::assertSame([0, 1], $kept->toKeys());
    }

    /**
     * The other half of the same decision: a map that lost its keys on the way through `where()`
     * would have stopped being one.
     *
     * @return void
     */
    public function testWhereKeepsTheKeysOfAMap(): void
    {
        $kept = new SearchableCollection(stdClass::class)
            ->with('a', self::numbered(1))
            ->with('b', self::numbered(2))
            ->where(static fn(stdClass $item): bool => $item->n === 2);

        self::assertSame(['b'], $kept->toKeys());
    }

    /**
     * @return void
     */
    public function testWhereReturnsACopyAndLeavesTheOriginalAlone(): void
    {
        $collection = new Collection(stdClass::class)->with(new stdClass(), new stdClass());

        (void) $collection->where(static fn(): bool => false);

        self::assertCount(2, $collection);
    }

    /**
     * A collection rather than a list, and one that holds what the callback said it returns.
     *
     * The type is read off the `: int` and nowhere else — passing it as an argument too would be
     * the same fact written twice, and the second copy is the one that goes stale.
     *
     * @return void
     */
    public function testMapAnswersWithACollectionOfTheCallbacksReturnType(): void
    {
        $mapped = new Collection(stdClass::class)
            ->with(self::numbered(1), self::numbered(2))
            ->map(static fn(stdClass $item): int => $item->n);

        self::assertInstanceOf(Collection::class, $mapped);
        self::assertSame('int', $mapped->type);
        self::assertSame([1, 2], $mapped->toValues());
    }

    /**
     * The value first and the key second — the order `array_find` and `ARRAY_FILTER_USE_BOTH` use,
     * and the order that lets a one-argument callback stay a first-class callable.
     *
     * @return void
     */
    public function testMapHandsOverTheValueThenTheKey(): void
    {
        $mapped = new SearchableCollection(stdClass::class)
            ->with('a', self::numbered(1))
            ->with('b', self::numbered(2))
            ->map(static fn(stdClass $item, string $key): string => $key . $item->n);

        self::assertSame(['a1', 'b2'], $mapped->toValues());
    }

    /**
     * The property the value-first order was chosen for: PHP hands a userland callback the extra
     * argument harmlessly, so a callback that only wants the item does not have to declare a key it
     * will not read. Nine call sites depend on this.
     *
     * @return void
     */
    public function testAOneArgumentCallbackNeedsNoClosureAroundIt(): void
    {
        $mapped = new SearchableCollection(stdClass::class)
            ->with('a', self::numbered(7))
            ->map(self::plainNumber(...));

        self::assertSame([7], $mapped->toValues());
    }

    /**
     * The behaviour with the widest blast radius.
     *
     * `map()` keeps keys rather than reindexing the way `array_map` given two arrays does — that
     * would be the implementation talking rather than the type. A `SearchableCollection` is a map,
     * and the whole reason a caller can still name each item by its key after a `map()` is that
     * it stays one. What follows is that the result cannot be spread into a call, since string
     * keys are named arguments, so a spreading call site asks {@link Collection::toValues()} and
     * says so.
     *
     * @return void
     */
    public function testMapKeepsTheKeysOfAMap(): void
    {
        $mapped = new SearchableCollection(stdClass::class)
            ->with('z', self::numbered(1))
            ->with('a', self::numbered(2))
            ->map(static fn(stdClass $item): int => $item->n);

        self::assertSame(['z' => 1, 'a' => 2], $mapped->toArray());
    }

    /**
     * A list maps to a list and a map maps to a map. Neither becomes the other.
     *
     * @return void
     */
    public function testMapAnswersWithTheSameShapeItWasCalledOn(): void
    {
        self::assertInstanceOf(
            SearchableCollection::class,
            new SearchableCollection(stdClass::class)->map(static fn(stdClass $i): int => $i->n),
        );
        self::assertInstanceOf(
            Collection::class,
            new Collection(stdClass::class)->map(static fn(stdClass $i): int => $i->n),
        );
    }

    /**
     * A subclass is a claim about the element type, so `where()` keeps it and `map()` cannot.
     *
     * {@link CspSourceList} is the whole reason the distinction is worth a test: it exists to say
     * its list holds {@link \Phpanta\Http\Security\CspSource}, which is exactly what stops
     * being true the moment a callback turns those into something else.
     *
     * @return void
     */
    public function testASubclassSurvivesWhereAndIsLeftBehindByMap(): void
    {
        $sources = new CspSourceList();

        self::assertInstanceOf(CspSourceList::class, $sources->where(static fn(): bool => true));

        $mapped = $sources->map(static fn(CspSource $source): string => $source->source());

        self::assertInstanceOf(Collection::class, $mapped);
        self::assertNotInstanceOf(CspSourceList::class, $mapped);
    }

    /**
     * @return void
     */
    public function testMapRefusesACallbackThatDeclaresNoReturnType(): void
    {
        $this->expectException(TypeError::class);
        $this->expectExceptionMessageMatches('/declares none/');

        (void) new Collection('int')->map(static fn(int $n) => $n);
    }

    /**
     * A callback declared `: static` maps to the class it was called on. PHP resolves `self` and
     * `parent` for the reflection, and leaves `static` as the word, which names no class.
     *
     * @return void
     */
    public function testMapReadsAStaticReturnOffTheClassItWasCalledOn(): void
    {
        $mapped = new Collection('int')->with(1, 2)->map(new CountFixture(10)->plus(...));

        self::assertSame(CountFixture::class, $mapped->type);
        self::assertSame(
            [11, 12],
            $mapped->map(static fn(CountFixture $count): int => $count->count)->toValues(),
        );
    }

    /**
     * A collection cannot hold null, so a callback that may answer with one is refused where it is
     * written rather than at whichever item first turns out to be null.
     *
     * @return void
     */
    public function testMapRefusesANullableReturnType(): void
    {
        $this->expectException(TypeError::class);
        $this->expectExceptionMessageMatches('/cannot hold null/');

        (void) new Collection('int')->map(static fn(int $n): ?string => null);
    }

    /**
     * `array` is refused as a declared type, so it is refused as a mapped-to one — the constructor
     * is the single place that decides, and its message names the type rather than the callback.
     *
     * @return void
     */
    public function testMapRefusesAReturnTypeNoCollectionCanHold(): void
    {
        $this->expectException(TypeError::class);
        $this->expectExceptionMessageMatches("/cannot hold 'array'/");

        (void) new Collection('int')->with(1)->map(static fn(int $n): array => [$n]);
    }

    /**
     * @return void
     */
    public function testJoinImplodesWhateverTheChainProduced(): void
    {
        $joined = new Collection(stdClass::class)
            ->with(self::numbered(1), self::numbered(2), self::numbered(3))
            ->map(static fn(stdClass $item): string => (string) $item->n)
            ->join(' · ');

        self::assertSame('1 · 2 · 3', $joined);
    }

    /**
     * @return void
     */
    public function testJoinAnswersEmptyForAnEmptyCollection(): void
    {
        self::assertSame('', new Collection('string')->join(', '));
    }

    /**
     * `join()` lost its callback when `map()` started answering with a collection, so what is left
     * is a collection of strings or a mistake. Saying which is cheaper than `implode()`'s own
     * answer, which for a collection of objects is a fatal about string conversion.
     *
     * @return void
     */
    public function testJoinRefusesACollectionThatDoesNotHoldStrings(): void
    {
        $this->expectException(TypeError::class);
        $this->expectExceptionMessageMatches('/map\(\) it to a string first/');

        (void) new Collection('int')->with(1, 2)->join(', ');
    }

    /**
     * @return void
     */
    public function testUniqueKeepsTheFirstOfEachAndDropsTheRest(): void
    {
        self::assertSame(
            ['a', 'b', 'c'],
            new Collection('string')->with('a', 'b', 'a', 'c', 'b', 'a')->unique()->toValues(),
        );
    }

    /**
     * A list is renumbered afterwards and a map keeps its keys, exactly as after a `where()` — this
     * is the second step that can put holes in a list, and the only other one.
     *
     * @return void
     */
    public function testUniqueRenumbersAListAndKeepsAMapsKeys(): void
    {
        self::assertSame(
            [0 => 'a', 1 => 'b'],
            new Collection('string')->with('a', 'a', 'b')->unique()->toArray(),
        );

        $map = new SearchableCollection('string')
            ->with('x', 'a')
            ->with('y', 'b')
            ->with('z', 'a');

        self::assertSame(['x' => 'a', 'y' => 'b'], $map->unique()->toArray());
    }

    /**
     * The difference from `array_unique()`, which is the thing to know before reaching for either.
     *
     * `array_unique()` compares its items as strings, so it calls `1` and `1.0` one item. This
     * compares them the way `===` does, so it calls them two — and a collection declared `float`
     * is the one place both can be present, since an `int` is the single widening the language
     * itself makes.
     *
     * @return void
     */
    public function testUniqueComparesByIdentityRatherThanAsStrings(): void
    {
        $numbers = new Collection('float')->with(1, 1.0, 1.5, 1.5)->unique()->toValues();

        self::assertSame([1, 1.0, 1.5], $numbers);
        self::assertCount(3, $numbers, 'array_unique() would have called 1 and 1.0 the same number');
    }

    /**
     * An object is the same item only when it is the same object.
     *
     * Two value objects with identical fields are two items, because a value object here declares
     * no equality and inventing one inside a collection would be this class deciding what its
     * elements mean. A caller wanting value equality maps to the value first, which is what both
     * callers under `src/` do.
     *
     * @return void
     */
    public function testUniqueComparesObjectsByIdentity(): void
    {
        $one   = self::numbered(1);
        $other = self::numbered(1);

        self::assertNotSame($one, $other, 'two distinct objects, or this asserts nothing');

        self::assertCount(2, new Collection(stdClass::class)->with($one, $other, $one)->unique()->toValues());
    }

    /**
     * @return void
     */
    public function testUniqueReturnsACopyAndKeepsASubclass(): void
    {
        $letters = new Collection('string')->with('a', 'a');

        (void) $letters->unique();

        self::assertCount(2, $letters->toValues(), 'unique() must not touch what it was called on');
        self::assertInstanceOf(CspSourceList::class, new CspSourceList()->unique());
    }

    /**
     * An object is held while it is compared, not numbered. spl_object_id() gives a freed object's
     * id to the next one made, so a stream of fresh objects, each let go after the step that used
     * it, could meet a new object under a seen id — and drop it as a repeat of one already gone.
     *
     * @return void
     */
    public function testUniqueNeverMistakesAFreshObjectForAFreedOne(): void
    {
        $numbers = new Collection('int')
            ->with(1, 2, 3, 4, 5, 6)
            ->map(static function (int $n): stdClass {
                $box    = new stdClass();
                $box->n = $n;

                return $box;
            })
            ->unique()
            ->map(static fn(stdClass $box): int => $box->n);

        self::assertSame([1, 2, 3, 4, 5, 6], $numbers->toValues());
    }

    /**
     * `-0.0 === 0.0`, so the two are one item; NAN is `===` to nothing, itself included, so each
     * NAN is its own. The strict identity unique() promises, kept at the two floats where marking
     * a value by its printed form broke it.
     *
     * @return void
     */
    public function testUniqueTreatsTheZeroesAsOneAndEveryNanAsItsOwn(): void
    {
        $floats = new Collection('float')->with(0.0, -0.0, NAN, NAN, 1.5)->unique()->toValues();

        self::assertCount(4, $floats);
        self::assertSame(0.0, $floats[0]);
        self::assertNan($floats[1]);
        self::assertNan($floats[2]);
        self::assertSame(1.5, $floats[3]);
    }

    // ─────────────────────── keys that read as integers ───────────────────────

    /**
     * PHP stores `'2024'` as the int 2024 in every array. A map hands it back as the string it was
     * given as — to its iterator, to a step, to first() and to toKeys() — so a callback declaring a
     * string key does not throw on a slug that happens to be a year.
     *
     * @return void
     */
    public function testAKeyThatReadsAsAnIntegerComesBackAsTheStringItWentInAs(): void
    {
        $map = new SearchableCollection('string')->with('2024', 'debut')->with('ill', 'single');

        foreach ($map as $key => $item) {
            self::assertIsString($key, "the key of $item");
        }

        self::assertSame(['2024', 'ill'], $map->toKeys());
        self::assertSame('debut', $map->find('2024'));
        self::assertSame(
            'debut',
            $map->first(static fn(string $item, string $key): bool => $key === '2024'),
        );
        self::assertSame(
            ['2024: debut', 'ill: single'],
            $map->map(static fn(string $item, string $key): string => "$key: $item")->toValues(),
        );
        self::assertSame(
            ['2024'],
            $map->map(static fn(string $item): string => $item)
                ->where(static fn(string $item, string $key): bool => $key !== 'ill')
                ->toKeys(),
        );
    }

    /**
     * `withEach()` stores a whole map in one copy, and a key PHP made an integer of on the way in —
     * whether the array handed over already holds it as one or a generator yields the string — comes
     * back out as the string, as it does from `with()`. A later key replaces an earlier one.
     *
     * @return void
     */
    public function testWithEachStoresAWholeMapAndKeepsItsKeysStrings(): void
    {
        $map = new SearchableCollection('string')
            ->with('ill', 'single')
            ->withEach(['2024' => 'debut', 'ill' => 'again'])
            ->withEach((static function (): Generator {
                yield '7' => 'seven';
            })());

        self::assertSame(['ill', '2024', '7'], $map->toKeys());
        self::assertSame(['again', 'debut', 'seven'], $map->toValues());
        self::assertSame('debut', $map->find('2024'));
    }

    /**
     * A batch with one item of the wrong type stores none of it.
     *
     * @return void
     */
    public function testWithEachRefusesTheWholeBatchForOneWrongItem(): void
    {
        $map = new SearchableCollection('string')->with('kept', 'yes');

        try {
            (void) $map->withEach(['a' => 'fine', 'b' => 2]);
            self::fail('an int in a map of strings was stored');
        } catch (CollectionException) {
            self::assertSame(['kept'], $map->toKeys());
        }
    }

    // ─────────────────────── writing, listing, removing ───────────────────────

    /**
     * A rewrite with no mode keeps the mode of the file it replaces, so a key an earlier write
     * narrowed to 0600 is not widened to the umask's by the next — and leaves no temporary behind.
     *
     * @return void
     */
    public function testARewriteKeepsTheModeOfTheFileItReplaces(): void
    {
        $directory = Directory::temporary('phpanta-write-');
        $file      = $directory->file('key');

        try {
            self::assertTrue($file->write('first', 0o600));
            self::assertTrue($file->write('second'));
            clearstatcache();

            self::assertSame(0o600, fileperms($file->path) & 0o777);
            self::assertSame('second', $file->read());
            self::assertSame(
                ['key'],
                $directory->files()->map(static fn(File $each): string => $each->name())->toValues(),
            );
        } finally {
            $directory->remove();
        }
    }

    /**
     * A listing matches names and never the directory's own path — `glob()` read a `[` or a `*` in
     * the directory's name as a pattern, and listed nothing. A leading dot is matched only by a
     * pattern that writes one, as `glob()` had it; removing takes the dotfiles too.
     *
     * @return void
     */
    public function testAListingMatchesNamesAndRemovingTakesEveryFile(): void
    {
        $directory = Directory::temporary('phpanta-[draft]*-');
        $names     = static fn(Collection $files): string => $files
            ->map(static fn(File $file): string => $file->name())
            ->join(' ');

        try {
            foreach (['a.flac', 'b.txt', '.hidden.flac'] as $name) {
                $directory->file($name)->write('x');
            }

            $directory->directory('sub.flac')->create();

            self::assertSame('a.flac', $names($directory->files('*.flac')));
            self::assertSame('.hidden.flac', $names($directory->files('.*')));
            self::assertFalse($directory->remove(), 'a subdirectory is not descended into');

            $directory->directory('sub.flac')->remove();

            self::assertTrue($directory->remove());
            self::assertFalse($directory->exists());
        } finally {
            $directory->directory('sub.flac')->remove();
            $directory->remove();
        }
    }

    /**
     * A link is refused rather than followed, so what it points to is left as it was.
     *
     * @return void
     */
    public function testRemovingALinkRefusesAndLeavesItsTargetAlone(): void
    {
        $target = Directory::temporary('phpanta-target-');
        $link   = new Directory($target->path . '-link');

        $target->file('keep')->write('x');
        symlink($target->path, $link->path);

        try {
            self::assertFalse($link->remove());
            self::assertTrue($target->file('keep')->exists());
        } finally {
            unlink($link->path);
            $target->remove();
        }
    }

    /**
     * A deprecation is handed on rather than muted: it says a call will stop working, not that this
     * one did not, and it is what `@` swallows by accident on a PHP upgrade. A warning — the failure a
     * muted call exists to answer — is still muted.
     *
     * Handed on to the handler installed before, not past it to PHP: answering `false` would skip
     * every handler above this one — a test runner's among them, which is how a suite failing on
     * deprecations would never see one raised inside a muted closure.
     *
     * @return void
     */
    public function testADeprecationIsHandedOnToThePreviousHandlerAndAWarningIsNot(): void
    {
        $seen = [];

        set_error_handler(static function (int $severity, string $message) use (&$seen): bool {
            $seen[] = $message;

            return true;
        });

        try {
            Diagnostics::muted(static fn(): bool => trigger_error('muted', E_USER_WARNING));
            self::assertSame([], $seen);

            Diagnostics::muted(static fn(): bool => trigger_error('handed on', E_USER_DEPRECATED));
            self::assertSame(['handed on'], $seen);

            $watched = Diagnostics::watched(
                static fn(): bool => trigger_error('handed on, watched', E_USER_DEPRECATED),
            );
            self::assertTrue($watched->reported->isEmpty());
            self::assertSame(['handed on', 'handed on, watched'], $seen);
        } finally {
            restore_error_handler();
        }
    }

    /**
     * A previous handler that declines what it is handed leaves it to PHP, as it would have without
     * this class in front of it — seen through error_get_last(), which records what reaches PHP and
     * nothing a handler keeps. The severity is out of error_reporting for the test, so it prints nowhere.
     *
     * @return void
     */
    public function testWhatThePreviousHandlerDeclinesReachesPhp(): void
    {
        set_error_handler(static fn(): bool => false);
        $reporting = error_reporting(E_ALL & ~E_USER_DEPRECATED);

        try {
            error_clear_last();
            Diagnostics::muted(static fn(): bool => trigger_error('declined', E_USER_DEPRECATED));
            self::assertSame(E_USER_DEPRECATED, error_get_last()['type'] ?? null);
        } finally {
            error_reporting($reporting);
            error_clear_last();
            restore_error_handler();
        }
    }
    // ─────────────────────────── One pass, fused ───────────────────────────

    /**
     * `unique()` is lazy and fuses with the steps around it, like every other transforming step.
     *
     * The trace is what says so: a staged implementation would run every `w` before the first `u`,
     * and an eager `unique()` would compare all six before `map()` saw one. Note `u2` and `u4`
     * appearing without an `m` behind them — those are the repeats, dropped mid-pass.
     *
     * @return void
     */
    public function testUniqueRunsFusedWithTheStepsAroundIt(): void
    {
        $trace = [];

        $chain = new Collection('int')
            ->with(1, 2, 2, 3, 2, 3)
            ->where(static function (int $n) use (&$trace): bool {
                $trace[] = "w$n";

                return true;
            })
            ->unique()
            ->map(static function (int $n) use (&$trace): string {
                $trace[] = "m$n";

                return "n$n";
            });

        self::assertSame([], $trace, 'unique() must compare nothing until something asks');
        self::assertSame(['n1', 'n2', 'n3'], $chain->toValues());
        self::assertSame(['w1', 'm1', 'w2', 'm2', 'w2', 'w3', 'm3', 'w2', 'w3'], $trace);
    }

    /**
     * The property the whole pipeline exists for, and the one an eager implementation passes every
     * other test without having.
     *
     * A staged `where()`-then-`map()` would trace `w1 w2 w3 w4 w5 w6` and only then `m2 m4 m6`,
     * building an intermediate list in between. Fused, each element goes through the whole chain
     * before the next is touched — which is what the interleaving below says and what nothing else
     * here can distinguish.
     *
     * @return void
     */
    public function testTransformingStepsRunFusedRatherThanOneAfterTheOther(): void
    {
        $trace = [];

        $chain = new Collection('int')
            ->with(1, 2, 3, 4, 5, 6)
            ->where(static function (int $n) use (&$trace): bool {
                $trace[] = "w$n";

                return $n % 2 === 0;
            })
            ->map(static function (int $n) use (&$trace): string {
                $trace[] = "m$n";

                return "n$n";
            });

        self::assertSame([], $trace, 'a transforming step must run nothing until something asks');

        self::assertSame(['n2', 'n4', 'n6'], $chain->toValues());
        self::assertSame(
            ['w1', 'w2', 'm2', 'w3', 'w4', 'm4', 'w5', 'w6', 'm6'],
            $trace,
        );
    }

    /**
     * Both short-circuiting materialisers, asserted by what the chain was *not* asked to do.
     *
     * @return void
     */
    public function testFirstAndIsEmptyStopAtTheFirstAnswer(): void
    {
        $seen  = 0;
        $chain = new Collection('int')
            ->with(1, 2, 3, 4, 5, 6)
            ->map(static function (int $n) use (&$seen): int {
                $seen++;

                return $n * 10;
            });

        self::assertSame(10, $chain->first());
        self::assertSame(1, $seen, 'first() ran the chain for one element');

        $seen = 0;
        self::assertFalse($chain->isEmpty());
        self::assertSame(1, $seen, 'isEmpty() ran the chain for one element');

        $seen = 0;
        self::assertSame(40, $chain->first(static fn(int $n): bool => $n > 30));
        self::assertSame(4, $seen, 'a predicate stops at the match rather than at the end');
    }

    /**
     * A `Generator` is exhausted once; a method that builds one is not. That distinction is what
     * lets a pending pipeline be held, iterated, counted and iterated again — including nested
     * inside its own `foreach`, which is what `Element::renderChildren()` does one collection down.
     *
     * @return void
     */
    public function testAPipelineCanBeMaterialisedMoreThanOnce(): void
    {
        $chain = new Collection('int')
            ->with(1, 2, 3)
            ->map(static fn(int $n): int => $n * 2);

        self::assertSame([2, 4, 6], $chain->toValues());
        self::assertSame([2, 4, 6], $chain->toValues());
        self::assertCount(3, $chain);
        self::assertSame([2, 4, 6], iterator_to_array($chain, false));

        $pairs = [];

        foreach ($chain as $outer) {
            foreach ($chain as $inner) {
                $pairs[] = $outer + $inner;
            }
        }

        self::assertSame([4, 6, 8, 6, 8, 10, 8, 10, 12], $pairs);
    }

    /**
     * Appending is a store operation, so it runs what is pending first — which is what keeps
     * `->map(…)->with($x)` meaning what it reads as, with $x on the end of the mapped items rather
     * than waiting behind a transformation that was never meant to touch it.
     *
     * @return void
     */
    public function testWithRunsAPendingPipelineBeforeAppending(): void
    {
        $chain = new Collection('int')->with(1, 2)->map(static fn(int $n): string => "n$n");

        self::assertSame(['n1', 'n2', 'z'], $chain->with('z')->toValues());
        self::assertSame(['n1', 'n2'], $chain->toValues(), 'with() copies rather than appends');
    }

    /**
     * A list renumbers between steps, so a `map()` after a `where()` is handed `0, 1, 2` — exactly
     * what it was handed when `where()` rebuilt an array eagerly. A map keeps its keys throughout.
     *
     * @return void
     */
    public function testAListIsRenumberedBetweenStepsAndAMapIsNot(): void
    {
        self::assertSame(
            ['0:20', '1:40'],
            new Collection('int')
                ->with(10, 20, 30, 40)
                ->where(static fn(int $n): bool => $n % 20 === 0)
                ->map(static fn(int $n, int $key): string => "$key:$n")
                ->toValues(),
        );

        self::assertSame(
            ['b:20'],
            new SearchableCollection('int')
                ->with('a', 10)
                ->with('b', 20)
                ->where(static fn(int $n): bool => $n === 20)
                ->map(static fn(int $n, string $key): string => "$key:$n")
                ->toValues(),
        );
    }

    /**
     * The one materialiser that cannot short-circuit — the far end of a stream is only knowable by
     * reaching it — and the one that had no test at all until the pipeline gave it a second way to
     * be wrong.
     *
     * @return void
     */
    public function testLastAnswersTheFarEndOfWhateverTheChainProduced(): void
    {
        self::assertNull(new Collection('int')->last());
        self::assertSame(3, new Collection('int')->with(1, 2, 3)->last());
        self::assertSame(
            'n4',
            new Collection('int')
                ->with(1, 2, 3, 4, 5)
                ->where(static fn(int $n): bool => $n % 2 === 0)
                ->map(static fn(int $n): string => "n$n")
                ->last(),
        );
        self::assertNull(
            new Collection('int')->with(1)->where(static fn(): bool => false)->last(),
        );
    }

    // ─────────────────────────── settled() ───────────────────────────

    /**
     * The member laziness made necessary, and the hazard it exists for.
     *
     * A filter whose predicate does work — writes a file, runs a process — makes *when* that
     * predicate runs the whole behaviour of the chain. Left pending it runs at whatever first asks a
     * question — and `isEmpty()`, which is what a caller asks, stops at the first match with every
     * item behind it never visited.
     *
     * @return void
     */
    public function testSettledRunsThePendingStepsWhereItIsAsked(): void
    {
        $staged = [];

        $failed = new Collection('string')
            ->with('a', 'b', 'c')
            ->where(static function (string $mix) use (&$staged): bool {
                $staged[] = $mix;

                return $mix !== 'b';
            })
            ->settled();

        self::assertSame(['a', 'b', 'c'], $staged, 'every item went through before settled() returned');
        self::assertSame(['a', 'c'], $failed->toValues());

        (void) $failed->isEmpty();
        (void) $failed->toValues();

        self::assertSame(['a', 'b', 'c'], $staged, 'and the answer can be asked twice for free');
    }

    /**
     * The behaviour that makes `settled()` worth having: without it the predicate runs once for the
     * first item and again for all of them, so a side-effecting one both skips work and repeats it.
     *
     * @return void
     */
    public function testALazyFilterShortCircuitsAndThenRunsAgain(): void
    {
        $seen    = [];
        $pending = new Collection('string')
            ->with('a', 'b', 'c')
            ->where(static function (string $mix) use (&$seen): bool {
                $seen[] = $mix;

                return true;
            });

        self::assertFalse($pending->isEmpty());
        self::assertSame(['a'], $seen, 'isEmpty() stopped at the first item');

        (void) $pending->toValues();

        self::assertSame(['a', 'a', 'b', 'c'], $seen, "and 'a' was put through a second time");
    }

    /**
     * Nothing pending is nothing to run, so this is the collection itself — which is also what lets
     * a caller assert `assertSame($c, $c->settled())` to say a collection carries no pending work,
     * without reaching for its private members.
     *
     * @return void
     */
    public function testSettledIsTheSameCollectionWhenNothingIsPending(): void
    {
        $collection = new Collection('int')->with(1, 2);

        self::assertSame($collection, $collection->settled());
        self::assertNotSame($collection, $collection->where(static fn(): bool => true)->settled());
    }

    /**
     * Settling changes when the work happens, not what the collection holds — so a subclass
     * survives it, exactly as it survives {@link Collection::where()}.
     *
     * @return void
     */
    public function testSettledKeepsASubclass(): void
    {
        self::assertInstanceOf(
            CspSourceList::class,
            new CspSourceList()->where(static fn(): bool => true)->settled(),
        );
    }

    /**
     * @return void
     */
    public function testFirstAnswersTheFirstMatch(): void
    {
        [$a, $b] = [self::numbered(2), self::numbered(2)];

        $collection = new Collection(stdClass::class)->with(self::numbered(1), $a, $b);

        self::assertSame($a, $collection->first(static fn(stdClass $item): bool => $item->n === 2));
    }

    /**
     * @return void
     */
    public function testFirstWithNoPredicateAnswersTheFirstItem(): void
    {
        $a = new stdClass();

        self::assertSame($a, new Collection(stdClass::class)->with($a, new stdClass())->first());
    }

    /**
     * Null for both ways of not finding anything, which is what every call site collapses them to.
     *
     * @return void
     */
    public function testFirstAnswersNullWhenThereIsNothingToAnswerWith(): void
    {
        self::assertNull(new Collection(stdClass::class)->first());
        self::assertNull(new Collection(stdClass::class)->with(new stdClass())->first(static fn(): bool => false));

        // And through a pending pipeline, which is a different loop: the fast path above asks
        // array_find(), a chain has to be run out before it can say there was nothing.
        self::assertNull(
            new Collection('int')->with(1, 2)->map(static fn(int $n): string => "n$n")->first(
                static fn(string $name): bool => $name === 'n3',
            ),
        );
    }

    /**
     * @return void
     */
    public function testKeysAnswersIndicesForAListAndNamesForAMap(): void
    {
        self::assertSame(
            [0, 1],
            new Collection(stdClass::class)->with(new stdClass(), new stdClass())->toKeys(),
        );
        self::assertSame(
            ['a', 'b'],
            new SearchableCollection(stdClass::class)
                ->with('a', new stdClass())
                ->with('b', new stdClass())
                ->toKeys(),
        );
    }

    /**
     * An object carrying one number, so a test can say which item it got back.
     *
     * @param int $n
     * @return stdClass
     */
    private static function numbered(int $n): stdClass
    {
        $item    = new stdClass();
        $item->n = $n;

        return $item;
    }

    /**
     * Declares one parameter on purpose — see
     * {@link self::testAOneArgumentCallbackNeedsNoClosureAroundIt()}.
     *
     * @param stdClass $item
     * @return int
     */
    private static function plainNumber(stdClass $item): int
    {
        return $item->n;
    }

    // ───────────────────────────── File and Directory ─────────────────────────────

    /**
     * Absent and unreadable are different causes and the same answer.
     *
     * This is the case the class was written for: `is_file()` guards the first and does nothing
     * about the second, so `file_get_contents()` warned — and on a host that prints warnings, the
     * warning lands in the page ahead of the doctype, after the headers have already gone out.
     *
     * @return void
     */
    public function testAFileThatCannotBeReadAnswersNullRatherThanWarning(): void
    {
        $directory = Directory::temporary('phpanta-support-');

        try {
            self::assertNull($directory->file('never-written.txt')->read());
            self::assertFalse($directory->file('never-written.txt')->exists());
            self::assertSame([], $directory->file('never-written.txt')->lines());
            self::assertSame(0, $directory->file('never-written.txt')->size());
        } finally {
            $directory->remove();
        }
    }

    /**
     * @return void
     */
    public function testAFileIsWrittenReadBackAndRemoved(): void
    {
        $directory = Directory::temporary('phpanta-support-');
        $file      = $directory->file('note.txt');

        try {
            self::assertTrue($file->write("one\ntwo\n"));
            self::assertSame("one\ntwo\n", $file->read());
            self::assertSame(['one', 'two'], $file->lines());
            self::assertSame(8, $file->size());
            self::assertSame('note.txt', $file->name());
            self::assertSame('txt', $file->extension());
            self::assertTrue($file->delete());
            self::assertFalse($file->exists());
        } finally {
            $directory->remove();
        }
    }

    /**
     * A read stops at the byte limit it is given.
     *
     * This is what lets a caller reading an untrusted stream — the one being
     * {@link \Phpanta\Http\Request::body()} over `php://input` — bound how much it pulls into memory
     * rather than inheriting `post_max_size`. Null, the default every other caller uses, reads the
     * file whole; a limit past the end is the same, since there is no more to read.
     *
     * @return void
     */
    public function testAReadStopsAtItsLimit(): void
    {
        $directory = Directory::temporary('phpanta-support-');
        $file      = $directory->file('body.bin');

        try {
            self::assertTrue($file->write('0123456789'));
            self::assertSame('0123', $file->read(4), 'the read ran past its limit');
            self::assertSame('0123456789', $file->read(100), 'a limit past the end is the whole file');
            self::assertSame('0123456789', $file->read(), 'null reads the file whole');
        } finally {
            $directory->remove();
        }
    }

    /**
     * Deleting a file that was never there is a success: the postcondition is what is asked for.
     *
     * @return void
     */
    public function testDeletingSomethingThatIsNotThereSucceeds(): void
    {
        self::assertTrue(new File('/x/never-existed.txt')->delete());
    }

    /**
     * A directory is something there that deleting a file does not remove, so it answers false
     * rather than a success over a directory still standing.
     *
     * @return void
     */
    public function testDeletingADirectoryAsAFileAnswersFalseAndLeavesIt(): void
    {
        $directory = Directory::temporary('phpanta-support-');

        try {
            self::assertFalse(new File($directory->path)->delete());
            self::assertTrue($directory->exists());
        } finally {
            $directory->remove();
        }
    }

    /**
     * A link whose target is gone is there all the same, and deleting it removes the link: it is no
     * file, and it was not "never there".
     *
     * @return void
     */
    public function testDeletingALinkWhoseTargetIsGoneRemovesTheLink(): void
    {
        $directory = Directory::temporary('phpanta-support-');
        $link      = $directory->file('dangling');

        try {
            self::assertTrue(symlink($directory->path . '/nowhere', $link->path));
            self::assertTrue($link->delete());
            self::assertFalse(is_link($link->path));
        } finally {
            $directory->remove();
        }
    }

    /**
     * A directory has no contents to read. PHP opens one and reads `''`; this answers null, as for
     * anything else with nothing to tell.
     *
     * @return void
     */
    public function testADirectoryReadsAsNothing(): void
    {
        $directory = Directory::temporary('phpanta-support-');

        try {
            self::assertNull(new File($directory->path)->read());
        } finally {
            $directory->remove();
        }
    }

    /**
     * A length below nothing is a mistake in the code that asked, refused as one rather than left to
     * PHP's own `ValueError`.
     *
     * @return void
     */
    public function testAReadOfLessThanNothingIsRefused(): void
    {
        $this->expectException(InvalidValueException::class);

        (void) new File('/x/never-existed.txt')->read(-1);
    }

    /**
     * @return void
     */
    public function testATailOfLessThanNothingIsRefused(): void
    {
        $this->expectException(InvalidValueException::class);

        (void) new File('/x/never-existed.txt')->tail(-1);
    }

    /**
     * @return void
     */
    public function testAnExtensionIsLowerCasedAndAFileWithoutOneHasNone(): void
    {
        self::assertSame('flac', new File('/x/ILL..FLAC')->extension());
        self::assertSame('', new File('/x/README')->extension());
    }

    /**
     * The one thing this class refuses to do, and the history that decided it: a logger that
     * created its own directory was deployed once, had to be reverted, and the directory it had
     * already made on the server had to be deleted by hand. Writing into a directory that is not
     * there fails, and the caller asks {@link Directory::create()} when it means to.
     *
     * @return void
     */
    public function testWritingIntoADirectoryThatIsNotThereFailsRatherThanCreatingIt(): void
    {
        $directory = Directory::temporary('phpanta-support-');
        $missing   = $directory->directory('nope');

        try {
            self::assertFalse($missing->file('x.txt')->write('anything'));
            self::assertFalse($missing->file('x.txt')->append('anything'));
            self::assertFalse($missing->exists());
        } finally {
            $directory->remove();
        }
    }

    /**
     * @return void
     */
    public function testAppendingAddsWholeLinesAndCreatesTheFile(): void
    {
        $directory = Directory::temporary('phpanta-support-');
        $log       = $directory->file('downloads.log');

        try {
            self::assertTrue($log->append('{"slug":"ill"}'));
            self::assertTrue($log->append('{"slug":"hello-world"}'));
            self::assertSame(['{"slug":"ill"}', '{"slug":"hello-world"}'], $log->lines());
        } finally {
            $directory->remove();
        }
    }

    /**
     * That the mode lands, which is the half this can see.
     *
     * **The other half is the order, and no runtime assertion can reach it.** Applying the mode
     * before the contents and applying it after both end with the same file at the same mode; what
     * differs is only whether the contents sat there world-readable in between, which is a window
     * this process cannot sample from inside itself. The order is asserted against the source
     * instead, by a check that reads the file rather than runs it — the kind that fails when the
     * two statements are swapped back.
     *
     * @return void
     */
    public function testAModeIsAppliedBeforeTheContentsAreReachable(): void
    {
        $directory = Directory::temporary('phpanta-support-');
        $file      = $directory->file('token.json');

        try {
            self::assertTrue($file->write('{}', 0o600));
            self::assertSame('0600', substr(sprintf('%o', fileperms($file->path)), -4));

            // A mode-less write is left to the umask rather than narrowed to something of this
            // class's choosing — appending a log asks for no mode, so the caller that asks for one
            // is the one holding a credential.
            $plain = $directory->file('plain.txt');

            self::assertTrue($plain->write('hello'));
            self::assertSame('hello', $plain->read());
        } finally {
            $directory->remove();
        }
    }

    /**
     * @return void
     */
    public function testADirectoryListsItsOwnFilesAndNotItsSubdirectories(): void
    {
        $directory = Directory::temporary('phpanta-support-');

        try {
            $directory->file('a.flac')->write('');
            $directory->file('b.wav')->write('');
            $directory->directory('web')->create();

            self::assertSame(
                ['a.flac', 'b.wav'],
                $directory->files()->map(static fn(File $f): string => $f->name())->toValues(),
            );
            self::assertSame(
                ['a.flac'],
                $directory->files('*.flac')->map(static fn(File $f): string => $f->name())->toValues(),
            );
        } finally {
            $directory->directory('web')->remove();
            $directory->remove();
        }
    }

    /**
     * It removes what it holds and itself, and refuses to descend — a recursive delete is not a
     * thing this repository needs, and not a thing to have lying around.
     *
     * @return void
     */
    public function testRemovingADirectoryWithASubdirectoryInItRefusesRatherThanRecursing(): void
    {
        $directory = Directory::temporary('phpanta-support-');
        $nested    = $directory->directory('web');

        $nested->create();
        $nested->file('cover.jpg')->write('');

        self::assertFalse($directory->remove());
        self::assertTrue($directory->exists());
        self::assertTrue($nested->file('cover.jpg')->exists(), 'nothing inside it was touched');

        $nested->remove();
        $directory->remove();
    }

    /**
     * @return void
     */
    public function testATemporaryDirectoryIsCreatedForItsOwnerAndIsUniquePerCall(): void
    {
        $one = Directory::temporary('phpanta-support-');
        $two = Directory::temporary('phpanta-support-');

        try {
            self::assertTrue($one->exists());
            self::assertNotSame($one->path, $two->path);
            self::assertSame('0700', substr(sprintf('%o', fileperms($one->path)), -4));
        } finally {
            $one->remove();
            $two->remove();
        }
    }

    /**
     * One that cannot be created is refused, rather than handed back to fail at the first write into
     * it — reached by asking for one inside a file.
     *
     * @return void
     */
    public function testATemporaryDirectoryThatCannotBeCreatedIsRefused(): void
    {
        $blocker = new File(sys_get_temp_dir() . '/phpanta-support-blocker-' . bin2hex(random_bytes(6)));

        self::assertTrue($blocker->write('x'));

        try {
            $this->expectException(FilesystemException::class);

            (void) Directory::temporary(basename($blocker->path) . '/inside-');
        } finally {
            $blocker->delete();
        }
    }

    /**
     * A write that cannot be put into place leaves nothing behind — not the temporary file either.
     *
     * Reached by aiming at a name a directory already has: `rename()` will not replace a directory
     * with a file. It is the same failure as a full disk or a read-only mount, which is what this
     * branch is really for.
     *
     * @return void
     */
    public function testAWriteThatCannotBeRenamedIntoPlaceLeavesNothingBehind(): void
    {
        $directory = Directory::temporary('phpanta-support-');
        $occupied  = $directory->directory('taken');

        $occupied->create();
        $occupied->file('inside.txt')->write('');

        try {
            self::assertFalse($directory->file('taken')->write('anything'));
            self::assertSame([], $directory->files('taken.*')->toArray(), 'the temporary file was cleaned up');
        } finally {
            $occupied->remove();
            $directory->remove();
        }
    }

    /**
     * A file moved onto another replaces it whole and keeps its own mode; one that cannot be moved
     * — onto a directory — says so and leaves both where they were.
     *
     * @return void
     */
    public function testAMovedFileReplacesItsTargetOrSaysItCouldNot(): void
    {
        $directory = Directory::temporary('phpanta-support-');
        $staged    = $directory->file('staged.txt');
        $live      = $directory->file('live.txt');
        $occupied  = $directory->directory('taken');

        try {
            self::assertTrue($staged->write('new', 0o600));
            self::assertTrue($live->write('old', 0o644));

            self::assertTrue($staged->moveOnto($live));
            self::assertSame('new', $live->read());
            self::assertFalse($staged->exists());
            clearstatcache();
            self::assertSame(0o600, $live->permissions());

            self::assertTrue($occupied->create());
            self::assertTrue($occupied->file('inside.txt')->write(''));
            self::assertFalse($live->moveOnto(new File($occupied->path)));
            self::assertSame('new', $live->read(), 'a failed move lost the file');
        } finally {
            $occupied->remove();
            $directory->remove();
        }
    }

    /**
     * Removing what is not there is a success: the postcondition is what is being asked for.
     *
     * @return void
     */
    public function testRemovingADirectoryThatIsNotThereSucceeds(): void
    {
        self::assertTrue(new Directory('/x/never-existed')->remove());
    }

    /**
     * A file it cannot remove stops it, rather than leaving it to fail at the `rmdir()`.
     *
     * @return void
     */
    public function testADirectoryThatCannotBeEmptiedAnswersFalse(): void
    {
        $directory = Directory::temporary('phpanta-support-');

        $directory->file('locked.txt')->write('');
        chmod($directory->path, 0o500);

        try {
            if (is_writable($directory->path)) {
                self::markTestSkipped('this process can write to a read-only directory');
            }

            self::assertFalse($directory->remove());
            self::assertTrue($directory->exists());
        } finally {
            chmod($directory->path, 0o700);
            $directory->remove();
        }
    }

    /**
     * @return void
     */
    public function testAFileKnowsWhichDirectoryItIsIn(): void
    {
        self::assertSame('/x/web', new File('/x/web/cover.jpg')->directory()->path);
    }

    /**
     * A tail is the last bytes, the whole file where it is shorter, and null where there is none.
     *
     * @return void
     */
    public function testATailIsTheFilesLastBytes(): void
    {
        $directory = Directory::temporary('phpanta-support-');
        $file      = $directory->file('php.log');

        try {
            self::assertTrue($file->write('0123456789'));
            self::assertSame('6789', $file->tail(4));
            self::assertSame('0123456789', $file->tail(100), 'a tail past the start is the whole file');
            self::assertNull($directory->file('never-written.log')->tail(4));
        } finally {
            $directory->remove();
        }
    }

    /**
     * Writable is asked of a directory that is there; one that is not is not writable either.
     *
     * @return void
     */
    public function testADirectoryIsWritableOnlyWhereItIsThereAndPermitsIt(): void
    {
        $directory = Directory::temporary('phpanta-support-');

        try {
            self::assertTrue($directory->isWritable());
            self::assertFalse($directory->directory('nope')->isWritable());

            chmod($directory->path, 0o500);

            if (is_writable($directory->path)) {
                self::markTestSkipped('this process can write to a read-only directory');
            }

            self::assertFalse($directory->isWritable());
        } finally {
            chmod($directory->path, 0o700);
            $directory->remove();
        }
    }

    // ───────────────────────────── ErrorLog ─────────────────────────────

    /**
     * One file a month, named for it — the last second of a month still lands in that month.
     *
     * @return void
     */
    public function testTheErrorLogIsNamedForItsMonth(): void
    {
        $logs = new Directory('/x/data/logs');
        $in   = static fn(string $when): string => ErrorLog::file($logs, new DateTimeImmutable($when))->path;

        self::assertSame('/x/data/logs/php-2026-09.log', $in('2026-09-11 00:26'));
        self::assertSame('/x/data/logs/php-2026-12.log', $in('2026-12-31 23:59:59'));
    }

    /**
     * Installed, it takes every severity and sends what PHP logs to the file named.
     *
     * The line is written through `error_log()`, the call the last-resort handler in
     * `public/index.php` makes. A raised notice would reach PHPUnit's own handler instead, which is
     * the point of `failOnNotice` rather than a gap here.
     *
     * @return void
     */
    public function testAnInstalledErrorLogTakesEverySeverityIntoItsFile(): void
    {
        $directory = Directory::temporary('phpanta-errorlog-');
        $log       = $directory->file('php.log');
        $path      = (string) ini_get('error_log');
        $mask      = error_reporting();

        try {
            ErrorLog::install($log);

            self::assertSame($log->path, ini_get('error_log'));
            self::assertSame(E_ALL, error_reporting());

            error_log('phpanta: a line for the test');

            self::assertStringContainsString('phpanta: a line for the test', (string) $log->read());
        } finally {
            ini_set('error_log', $path);
            error_reporting($mask);
            $directory->remove();
        }
    }

    // ───────────────────────────── Diagnostics ─────────────────────────────

    /**
     * What `@` did, said out loud: the operation's answer, and no output.
     *
     * `beStrictAboutOutputDuringTests` is what makes the second half an assertion rather than a
     * hope — a warning that reached the page would reach this test's output buffer too.
     *
     * @return void
     */
    public function testMutedAnswersWhatTheOperationAnsweredAndPrintsNothing(): void
    {
        $directory = Directory::temporary('phpanta-diagnostics-');
        $missing    = $directory->file('there-is-no-such-file')->path;

        try {
            self::expectOutputString('');
            self::assertFalse(Diagnostics::muted(static fn(): string|false => file_get_contents($missing)));
        } finally {
            $directory->remove();
        }
    }

    /**
     * The half `@` has no version of: which diagnostic, for this call.
     *
     * @return void
     */
    public function testWatchedKeepsWhatTheOperationComplainedAbout(): void
    {
        $directory = Directory::temporary('phpanta-diagnostics-');
        $missing    = $directory->file('there-is-no-such-file')->path;

        try {
            $watched = Diagnostics::watched(static fn(): string|false => file_get_contents($missing));

            self::assertFalse($watched->result);
            self::assertCount(1, $watched->reported->toValues());
            self::assertStringContainsString('there-is-no-such-file', $watched->reported->join(' / '));
        } finally {
            $directory->remove();
        }
    }

    /**
     * Nothing reported is an empty collection, not a null and not an absent one.
     *
     * @return void
     */
    public function testWatchedReportsNothingWhenNothingWentWrong(): void
    {
        $watched = Diagnostics::watched(static fn(): int => 41 + 1);

        self::assertSame(42, $watched->result);
        self::assertTrue($watched->reported->isEmpty());
    }

    /**
     * The handler is restored on the way out however the operation ends.
     *
     * The `finally` is the whole reason both members are shaped the way they are: a handler left
     * installed would go on swallowing warnings for the rest of the request, which is `@`'s failure
     * mode made permanent rather than fixed.
     *
     * @return void
     */
    public function testTheHandlerIsRestoredEvenWhenTheOperationThrows(): void
    {
        $installed = static function (): ?callable {
            $current = set_error_handler(static fn(): bool => true);
            restore_error_handler();

            return $current;
        };

        $before = $installed();

        try {
            Diagnostics::muted(static fn(): never => throw new RuntimeException('while muted'));
            self::fail('the operation was supposed to throw');
        } catch (RuntimeException $thrown) {
            self::assertSame('while muted', $thrown->getMessage(), 'the throw must pass straight through');
        }

        self::assertSame($before, $installed(), 'muted() left its handler installed');

        try {
            Diagnostics::watched(static fn(): never => throw new RuntimeException('while watched'));
            self::fail('the operation was supposed to throw');
        } catch (RuntimeException) {
            // The point is the assertion below, not this one.
        }

        self::assertSame($before, $installed(), 'watched() left its handler installed');
    }
}
