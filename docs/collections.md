# Collections

`Collection<T>` and `SearchableCollection<T>` are the only shapes a group of values takes on the
PHP side — a list and a map, both immutable, both lazy, holding objects or scalars. This document is
what they are, what they cost, and where they deliberately stop.

The guideline that keeps a bare `array` from coming back is in [guidelines.md](guidelines.md). How
they got this way — the escape hatch `all()` used to be, the chain that used to end at `map()`, the
304 an empty collection let through — is in [history/types.md](history/types.md).

---

## One guard, and what is left to check by hand

A bare `array` with a `foreach`-and-`instanceof` check in a constructor is the thing these replace.
That check is `TypedItems::guard()`'s, once, and it throws a `CollectionException` — which *is* a
`TypeError`, see [architecture.md](https://github.com/neurosysgg/neurosys-webspace/blob/master/docs/architecture.md#exceptions). What is left to check by hand is the
*element type*, the one thing a PHP generic cannot say: `is_a($this->fields->type,
TerminalField::class, true)`, in seven places.

**That check asks `is_a()` rather than `!==`, and the difference is covariance.** A collection of
any subclass of `Format` is a perfectly good `Collection<Format>` for every consumer of
`Release::$formats`, so refusing it would be invariance imposed on a structure whose immutability is
exactly what makes covariance sound. The usual reason a container must demand invariance is a write
path — hand out a `Collection<Format>` that is really a narrower list and somebody inserts a plain
`Format` into it — and there is none here: `with()` copies rather than appends, and every reader of
the seven guarded properties across `src/` is a query (`map()`, `first()`, `last()`, `isEmpty()`,
`count()`, `toValues()`, `type`) with no `with()` among them. It changes behaviour on exactly one of
the seven today, because `Format` is the only element type they name that is not `final` — the rest
are `final` classes or an enum, where the two spellings cannot differ. Which makes it a statement of
what the guard means rather than a fix: it says *at least* this type, which is what a read-only
collection can honestly promise. The argument lives on `Release::verify()` and the other six point
at it.

**What they share is a trait, `Support/TypedItems`, and not a base class** — the codebase's only
trait, and the reason is worth stating. The two are not substitutable and never should be: one is a
list and one is a map, their `with()` methods take different arguments, and nothing anywhere holds
"either kind of collection". `extends` would announce a common type that nothing wants; `use`
announces shared plumbing, which is all it is. Two mechanical consequences follow: `$items` stays
`private`, because PHP flattens a trait's members into the using class where a parent's private
member would have had to become `protected`; and `static::class` names the collection rather than
the trait, so the exception names the class a caller actually built. `SupportTest` asserts that
message, which is what would catch a later slip to `self::class`.

## The members

What stays in each class is what genuinely differs; everything else is the trait.

| Member | Where | Is |
|---|---|---|
| `with()` | each class | a copy with one more item — the list and the map take different arguments |
| `find()` | `SearchableCollection` | the item under a key |
| `getIterator()` | each class | a materialiser — see below |
| `ofType()`, `sequenced()` | each class, private | the trait's two abstract members |
| `where()`, `unique()`, `map()` | trait | the three lazy steps |
| `first()`, `last()`, `join()`, `count()`, `isEmpty()` | trait | materialisers that answer a question |
| `toArray()`, `toValues()`, `toKeys()` | trait | materialisers that leave the type — `toArray()` is the door |
| `settled()` | trait | a materialiser that stays inside the type |

`toArray()`, `toValues()` and `toKeys()` say in their names which of the two shapes you are getting,
and everything above them stays inside the type.

Eleven of the trait's members carry `#[\NoDiscard]` — every one of them above except `count()`,
which is `Countable`'s. `NoDiscardTest` pins them three times each, since PHP reports a trait's
members on both using classes *and* on the trait. Ten are pure, so a dropped result is never
anything but a bug; `settled()` is the eleventh and belongs to both halves — dropping it is the one
discard here that does work and then throws the work away, which is the mistake it exists to stop.

## A chain stays a collection, and it runs once

**Every transforming step answers with a collection and every one of them is lazy.** `where()`,
`unique()` and `map()` record a stream transformer and run *nothing*; the callbacks are first called
by a materialiser, which folds every pending step over **one pass** of the source:

```php
$this->directives
    ->map(static fn(CacheDirective $directive): string => $directive->value)
    ->join(', ');
```

The passes are **fused**, not staged: `->where(even)->map(rename)` over `1..6` calls its callbacks
in the order `w1 w2 m2 w3 w4 m4 w5 w6 m6`, so each element goes through the whole chain before the
next is touched and nothing in between is ever built. `SupportTest` asserts that order, because it
is the one property an eager implementation passes every other test without having. It also means
the short-circuiting materialisers genuinely short-circuit — `first()` on that chain stops after
`w1 w2 m2`, and `isEmpty()` after `w1`.

`stream()` builds a fresh generator each time it is asked, so a pipeline is re-iterable and a nested
`foreach` over one works; the classic once-only-`Generator` trap does not apply.

**`map()` reads its element type off the callback's own return declaration**, via
`ReflectionFunction`, which is why it takes no type argument. A `class-string` parameter beside a
callback that already declares `: string` is the same fact written twice, and stating it once puts
it where PHP itself enforces it — a callback returning the wrong thing is a `TypeError` at the
`return`, naming the function, before the collection sees the value. **So a mapped item is not
`guard()`ed**, and that is provable rather than lax. A callback with no declared return type, or a
union or nullable one, throws at the `map()` call; everything else a return type can say (`void`,
`array`, `object`, `static`) is refused by the constructor, which names it.

`map()` answers with the **base** shape rather than `static`: a mapped `CspSourceList` holds strings,
not `CspSource`s, so calling it a source list would be a lie. `where()`, `unique()` and `settled()`
keep the subclass, because none of them changes what is held.

**A map keeps its keys through a `map()`.** The consequence to know is that a mapped
`SearchableCollection` cannot be spread into a call, because string keys are named arguments, so
every spreading call site asks `toValues()` and says so.

**A key comes back as the string it went in as.** PHP stores a decimal-integer string key as an int
in every array, so a slug of `2024` was handed to a callback as the int `2024` — and a callback
declaring a string key threw on it. `SearchableCollection::with()` notes when a key has been turned
into an int, and only then do the iterator, the steps, `first()` and `toKeys()` put the string back;
a map of names pays nothing. `toArray()` cannot: an array is where the int came from.

**A step that does work runs where it is asked for.** Every callback here is pure except one —
`DemoStage::write()` filters on a predicate that *transcodes with ffmpeg* and reports whether that
worked. Left pending, `write()` would write nothing: its caller's `isEmpty()` would stop at the first
failure with every mix behind it unstaged, and the loop that reports the failures would encode them
all a second time. So `write()` ends in `settled()`, which runs every pending step once and answers
with a collection that holds the results. `Directory::files()` ends in one too, for the weaker
version of the same reason: `exists()` is a `stat()`, and a directory listing is a snapshot rather
than a live query. Since `settled()` answers with the collection itself when nothing is pending,
`assertSame($c, $c->settled())` is how a test asks "is there work pending here?" without reaching for
a private member.

Four decisions are worth knowing before adding a member:

- **The callback takes the value first and the key second.** That is the order PHP's own
  `array_find`, `array_any` and `array_all` use — `Element::renderChildren()` already calls one —
  and the order `ARRAY_FILTER_USE_BOTH` passes. It is also what keeps a one-argument callback a
  first-class callable, since PHP hands a userland callback extra arguments harmlessly:
  `$links->map(self::profileLink(...))` needs no closure around it. Key-first would break every such
  site.
- **`sequenced()` is abstract because a filter is the only thing that can put holes in a list.** A
  `Collection` renumbers after a `where()` or a `unique()` — so a `map()` following one sees
  `0, 1, 2` — and a `SearchableCollection` keeps its keys, its implementation being a
  `return $stream` rather than a `yield from` so the map pays nothing for a layer that hands back what
  it was given. Only those two steps push it: resequencing after every step would spend a generator
  layer per step renumbering keys that are already in order.
- **`ofType()` must answer with its own class**, not the sibling. `map()` writes `$copy->items`
  across instances, which PHP allows only between instances of the class that declared the private
  member — so the obvious way to make a map answer with a list is not a type error but a fatal.
- **`last()` takes no predicate**, unlike `first()`: nothing here searches backwards, PHP gives
  `array_find()` and no `array_find_last()`, and `where(…)->last()` already answers the day something
  wants one.

**`unique()` compares by strict identity, which `array_unique()` does not**, and that is the thing to
know before reaching for either. `array_unique()` compares its items as strings, so it calls `1` and
`1.0` one item; this calls them two, because `===` does. The one place both can be present is a
collection declared `float`, which accepts an `int` — the single widening the language itself makes.
An object is the same item only when it is the same object: a value object here declares no
equality, and inventing one inside a collection would be the container deciding what its elements
mean. Both callers map to a scalar first, which is what makes the question not arise. An object is
**held** while it is compared rather than numbered, because `spl_object_id()` hands a freed object's
id to the next one made — and a stream of fresh objects, each let go after its step, would drop a new
one as a repeat of one already gone. The two floats where `===` surprises are kept as it has them:
`-0.0` is `0.0`, and every NAN is its own.

## What a collection may hold

`guard()` asks `instanceof` for a class and `get_debug_type()` for a scalar, so `Collection('string')`,
`SearchableCollection('int')` and the rest are collections like any other. Three rules come with
that, and each is worth knowing before adding a fourth:

- **`null` and `array` are refused as declared types**, and `TypedItems::SCALARS` says so out loud.
  A collection of nulls holds no information; a collection of arrays is the shape every one of
  these was written to replace, so allowing it would let the escape hatch back in under the type's
  own name. That refusal is load-bearing rather than tidy — see `CspSourceList` below, which exists
  because of it.
- **`int` satisfies `float`, and nothing else widens.** That is the one coercion PHP itself makes
  under `declare(strict_types=1)`, so a collection that refused `array_fill(0, 512, 0)` would be
  stricter than the language it is written in. The value is kept as it arrived rather than cast,
  exactly as a parameter would.
- **The declared type is checked in the constructor**, the same move `HiDriveLink` makes on a share
  id and `CspHost` makes on an origin. `instanceof` answers `false` for a string naming no class
  rather than complaining about it, so an unchecked `new Collection('Reelase')` would not be an error
  but a collection that silently rejected everything ever offered to it — reporting the typo as a
  fault in the *item*. `class_exists()` and `interface_exists()` are both asked, because the first
  answers `false` for an interface and `Collection(Node::class)` is among the commonest shapes here;
  enums need no third question.

**`CspSourceList` is the one place PHP's lack of nested generics costs something.**
`ContentSecurityPolicy` holds source lists keyed by directive — a map of lists — and a collection is
defined by a `class-string`, so the outer `SearchableCollection` has to be told what its values are.
Told `Collection::class` it would check only that each value is *some* collection, not that it is a
collection of `CspSource`, which is the entire question worth asking about a policy. Naming the
inner list recovers both halves. It is also what refusing `'array'` above forces: a collection of
arrays would let the map keep untyped values under a collection's name.

## Where a collection goes, and where it does not

**`with()` copies; it does not append.** That is what makes a collection safe to hold inside a
`readonly` value object: `readonly` protects the reference, not what it points at, so a mutable
collection would leave every `Release`, `Terminal` and `SoundCloudEmbed` appendable by anyone
holding one. The name is deliberate too — a discarded `$c->add(…)` reads as correct, a discarded
`$c->with(…)` reads as wrong. Same shape as `ContentSecurityPolicy::allow()`.

**The compiler enforces that naming convention.** `with()`, `allow()`, `attr()` and `containing()`
all carry `#[\NoDiscard]` with a sentence saying why the dropped call did nothing, so a result that
goes nowhere is an `E_WARNING` — and `phpunit.xml.dist` sets `failOnWarning`, which makes it a
failing test rather than a line in a log. `Auth::accepts()` carries one too, and is the only member
that is not a builder: it is the gate's entire decision, and the two `require*` methods are only the
challenge wrapped around it. `NoDiscardTest` pins the set in both directions and asserts each
attribute carries a message, because the default warning has none. The deliberate discards are all
in the tests — proving a builder did not mutate what it was called on, or that a bad argument threw
— and each is spelled `(void)`, which says out loud what the test is there to demonstrate.

**The rule is about the parameter, and then about the store.** A collection replaces a hand-rolled
type check on data crossing a public boundary, and it is what a class holds afterwards; **it does not
replace a variadic.** `deny(PermissionsPolicyFeature ...$features)` keeps its variadic, because a
`Collection` parameter there would replace a check the language makes for free with one we make
ourselves. What a class *stores* once the variadic has guarded the boundary is a separate question,
and storing an array would mean rendering with `implode(', ', array_map(…))` — which is `join()`
spelled out. So `Allow`, `Vary`, `CacheControl`, `RobotsPolicy`, `PermissionsPolicy`, `Fragment` and
`TerminalCommand` all hold a `Collection` behind a variadic constructor, and their `render()` is one
`join()` each.

What is a collection, each because it crosses a public boundary with nothing else checking it:

- `Element`'s attributes — a `SearchableCollection<Attribute>` keyed by name, which is what keeps
  last-write-wins — and its **children**. `containing()` is a variadic and PHP guards it; the
  constructor is the other way in, and a string reaching a bare array there would not be a
  `TypeError` naming the element but a fatal in `renderChildren()` calling `render()` on a string.
- The headers of `ViewResponse`, `PlainTextResponse` and `FileResponse`, and of an outbound tooling
  `Request`, whose body fields are one too — an `array<string, string|FilePart>` is a docblock's
  promise rather than the language's.
- `SecurityHeaders::all()` and `WaveformBand::bands()`.
- `ReleaseFolder`'s audio files, keyed by `ReleaseFormat` value in the order the catalogue lists
  them; and in `tools/`, `Project::$markers`, `Call::$arguments` and `DemoStage::$sources`.

**Ask a collection whether it is empty with `isEmpty()`, never `!== []`.** `!== []` is true of
*every* `Collection`, so it reads as a guard and is not one. `ViewResponse::send()`'s 304 depends on
the right question, and a test is named for the hazard.

**What deliberately stays a plain array.** `Preflight`'s findings, `ReleaseFolder::missing()`'s
filter over `Fact::cases()`, `FlpFile::all()` — none crosses a public boundary, and a collection does
not replace a variadic. PHP's own `array_find`/`array_any`/`array_all` are the API there.
`CurlTransport::parts()` keeps its `foreach` for a different reason: it rekeys by field name, which
is not a `map`. `Dsp/`'s buffers and `DownloadStats`'s tally accumulator stay arrays for a third
reason again — both are written to in a loop, and `with()` copies; see
[below](#what-deliberately-did-not-go-in).

**And a great many things stay arrays because PHP hands them over that way.** `cases()` (19 sites),
`preg_match`'s `&$matches` (17), `unpack` (16), `file` (10), `parse_url` (7), `explode` (6),
`json_decode` (5), `glob`, `range` — about ninety points across `src/` and `tools/` where a builtin
answers with an array and no amount of typing on this side changes that. The target is therefore
**all-collection in the interior with an adapter at each door**, not zero arrays anywhere:
`Directory::files()` is the model, where `scandir()` is the door and the `Collection<File>` is what
crosses the boundary.

## What deliberately did not go in

**`tools/lib/Dsp/` keeps raw arrays, and it is one function rather than a directory's worth of
exception.** `Fft::transform()` is the codebase's only genuine in-place mutation: its butterfly
reads and writes four arbitrary indices of two arrays per iteration, and the bit-reversal above it
swaps two more. No immutable collection expresses that, and no per-element callback can see another
element. Measured at 512 floats a window and ~2000 windows a mix: `array_fill` 1 ms, one batched
`with()` per window 354 ms, incremental `with()` 1321 ms. Everything else in those three classes —
`hann()`, `magnitude()`, `Analyze::mono()`, `Spectrum::bars()` — builds a *fresh* array in a loop
and returns it, so it is already `map`-shaped and already immutable; it stays arrays because the
port's stated contract is that the caller owns the buffer, and because the hot path is the one place
the 1.5x iteration cost of a collection is worth counting.

**A member is written when it has a second caller.** A member added for one caller is a member
nobody else will find — `unique()` passed that test when `Demo::verify()` became its second caller,
and two others have not:

- **`do(fn(&$value, $key))`**, an in-place walk, is the obvious answer to the buffers above. It is
  mechanically fine — an arrow function does take a by-ref parameter — but it *is* `map()`, with the
  result landing in the old array instead of a new one, and it cannot reach the only code that
  wanted it. It also measured **5.2x slower** than the indexed loop it would replace (the closure
  call per element, not the reference: a raw by-ref `foreach` is *faster* than indexing), it would
  put a mutation on the class whose immutability makes it safe inside every `readonly` value object
  here, and it would be the first member meant to be discarded — inverting the `with()`/`add()`
  convention above. If in-place ever becomes necessary, the honest shape is a separate `Buffer`
  type, not a hole in this one.
- **`zip()`** would serve one call site in `Dsp/` and one in `hosts()` — both excluded above, both
  doors.

## What it costs

Measured when the chain went lazy (2026-09-08), with no Xdebug loaded:

| | |
|---|---|
| `toValues()` with no steps pending — reads the store, builds no generator | 0.45 µs |
| the same through a one-step pipeline | 3.35 µs |
| a `map()` before it maps anything — 0.65 µs of `ReflectionFunction`, 0.58 µs constructing the collection, the rest copying | 1.76 µs |
| `Element::renderChildren()`'s `map(…)->join('')` | 5.16 µs |
| one page's worth of `renderChildren()` — about 10% of the time spent rendering markup | 1.257 ms |
| `ContentSecurityPolicy::render()`, a `map()` inside another's callback, on every response | 40.32 µs |

The fast path in the first row is why the collections that never chain cost what an array would. The
1.76 µs is the price of the type coming from the callback rather than from an argument.
`renderChildren()` is the one hot call site that is lazy: if that ever stops being worth a chain that
stays inside the type, it is the one place to spend a `foreach` and the only one. The figures before
laziness are in [history/markup.md](history/markup.md).
