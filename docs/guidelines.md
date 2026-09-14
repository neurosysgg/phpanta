# Guidelines — five habits, and the test that watches them

The PHP side argues against five habits. A **bare array** announces nothing about what it holds,
which is what [`Collection`](collections.md) is for; a **bare string** is a name with no vocabulary,
which is what the eighty-odd enums are for; an **`array_*` call** is a member of that collection
written the long way; an **`@`** hides whatever it happens to be in front of; and a **bare SPL
exception** names the condition "something".

None of those arguments is worth anything if it only holds for as long as whoever writes the next
method remembers it. `GuidelineTest` is what holds it — the framework's over this tree, and a
vendoring site's, with the same rules, over its own; see
[testing.md](testing.md#the-rules-the-framework-holds-itself-to). How the rules arrived, and what they found on
their first run, is in [history/types.md](history/types.md).

---

## How it reads the code

The way PHP does: **reflection for what is declared, the tokenizer for what is written**. Nothing is
grepped for and nothing is listed by hand except the three excuse sets, which are pinned in both
directions the way `NoDiscardTest`'s set is.

## An excuse is an attribute with a sentence in it

`#[BareArray('why')]` on a method or property, `#[BareString('literal', 'why')]` on a class,
`#[BareCall('array_map', 'why')]` on a method. The reason is mandatory in the attribute's own
constructor as well as in the test, for the reason `CspHost` checks an origin at its constructor:
the test reports a fault against a list, the constructor reports it — as a `GuidelineException` —
against the line that is wrong. A bare `#[BareArray]` would say the array is deliberate, which the
reader already suspected; what is worth saying is **which door it is**.

**Two of the five have no excuse mechanism at all**, and that is a claim about those two rather than
a gap. Neither `@` nor a foreign exception has a case where the replacement is worse, so there is
nothing for an attribute to say — an escape hatch offered where none is needed is an escape hatch
somebody eventually takes.

## The array rule

**Every `array` in a declared type.** A variadic is not one and never will be:
`deny(PermissionsPolicyFeature ...$features)` is a check PHP makes for free, and a `Collection`
parameter there would replace it with one we make ourselves — the distinction
[collections.md](collections.md#where-a-collection-goes-and-where-it-does-not) draws between what a
class *takes* and what it *stores*.

50 `#[BareArray]` attributes carry an excuse in the framework's `src/` (counted on 2026-09-14), and
they come in four kinds:

| Kind | Examples |
|---|---|
| a **door** | `preg_match`'s `$matches` in `Route::matches()`, `file()`'s lines in `File::lines()`, `unpack`'s shape in `TarArchive`, `scandir()` in `Directory`, `toArray()` itself |
| a **variadic's argument**, spread into a call | `varyOn()`, `Route::createController()` |
| a **tuple** — two types in a fixed order, the one shape a homogeneous collection cannot hold | `AcceptedLanguages::entry()`, `AuthScheme::credentials()` |
| an **accumulator** — written to in a loop, where `with()` would copy | `AcceptedLanguages::$qualities`, `UpdateApplier::walk()` |

## The string rule

**It is not "no literals".** A tagline, a heading and an exception message are all text and none of
them is a name; demanding an argument for each would produce four hundred arguments and bury the
four that matter. It catches the two shapes where a literal genuinely is a vocabulary written out:

- **A word an enum in reach already spells.** Reach is "the file names the enum anywhere at all" —
  if it writes `HttpMethod`, it could have written `HttpMethod::Get` rather than `'GET'`.
- **A word written in two classes.** One occurrence is a value; the same one in another file is a
  fact with two spellings and nothing keeping them in step. Two *files* rather than two lines,
  deliberately — a literal repeated inside one class is on one screen and has `const` waiting for
  it, where every failure this codebase is careful about is two halves in two files, neither knowing
  about the other.

Enum declarations are exempt outright, since that is where a vocabulary is meant to live; so is
anything with no letter or digit in it, because `'/'`, `', '` and `"\n"` are structure and a name
for them would read worse than they do; and so are an attribute's own arguments, which are prose
about the code the way a docblock is.

13 literals carry a `#[BareString]` excuse in the framework's `src/` (counted on 2026-09-14), and
**every one of them is a coincidence rather than a shortcut**, which is the point of listing them:
each is a word that looks like a name and is not. Somebody else's vocabulary — `int` and `string`
are `get_debug_type()`'s spellings in a class-string's place, in `TypedItems`, `Diagnostics`,
`Route`, `Vocabulary`, `HealthSection`, `UpdateReport`, `Input`, `MultipartParameters`, `Session`
and `Migrations` — or
another grammar: `c` is an `fopen()`
mode in `FileLock`, and `#^https://…#i` is a regex in `Location`.

**That last one is a real duplication kept on purpose and is worth re-reading rather than
assuming.** A site that holds URLs of its own — a profile link in its data — may check them with the
same regex, in a class of its own. The two check two different kinds of address — a header the
framework emits, and data a site reads — and throw different exceptions for it, so sharing one
constant would mean a change made for a redirect silently changing what the site's data may hold.
That is the argument; it is written on `Location`, and it is the one excuse here that a later reader
might reasonably overturn.

## The call rule

**Table-driven, and the table is the rule.** `GuidelineTest` carries a map of the array functions a
collection has a member for — `array_map`→`map()`, `array_filter`→`where()`,
`array_values`→`toValues()`, `array_keys`→`toKeys()`, `array_find`→`first()`,
`array_unique`→`unique()` — and asks about those and nothing else. `array_slice`, `array_shift`,
`array_merge`, `array_any` and `array_key_last` are deliberately absent: no member answers them, and
demanding an excuse for a call with no replacement asks for an apology rather than an argument.
Writing a member is what adds a row, and adding a row makes every existing call to that function fail
until somebody looks at it.

`Collection`, `SearchableCollection` and `TypedItems` are exempt outright, the way an enum
declaration is exempt from the string rule: those three *are* the members, and the array functions
are what they are made of.

Three calls in the framework carry a `#[BareCall]` excuse, in two kinds:

- **A class constant** — `Element::verifyUrl()` maps `URL_SCHEMES`. A class constant *cannot* hold a
  `Collection`, since `new` is not a constant expression, so that one is permanent.
- **A door, or a variadic straight through one** — `File::lines()` is `file()`'s doorway;
  `Element::containing()` maps the variadic PHP already guards directly into `with()`, on the hottest
  path a page has.

## The `@` rule

**No excuse list, because `@` has no case left to make.** There is no `@` anywhere; every
suppression is `Diagnostics::muted(static fn(): … => …)`. Two things `@` cannot do are the whole
argument: it cannot say *which* diagnostics it meant — it silences every one raised anywhere in the
expression, at any severity, from any call nested inside it, so a `@file_get_contents()` written for a
missing file also swallows an `E_DEPRECATED` that arrives with a PHP upgrade — and it cannot answer
*for one call*, because `error_get_last()` is process-global and sticky. `Diagnostics` names the
severities it handles — warnings and notices, never a deprecation, which explains no return value —
and hands everything else back to PHP untouched, and `watched()` keeps the
messages, which is what `MarkupParser` uses to refuse a parse error. It costs **0.58 µs** a call,
measured; against the 3.4 µs a failing `file_get_contents()` takes to fail, it does not show up.

**This is the one rule that walks the tooling too** — the framework's `tools/lib/` and a site's own
— and for the reason the others do not: what is excluded there are the doors, and a suppression is
not a door — `PrivateKey` signs a call with the only private key a deployment's tooling touches.

## The exception rule

**Three questions, and `@throws` already answers the hardest.** Every `throw new` under either source
tree names a class in `Phpanta\Exception` or the site's own exception namespace; every method that
throws directly declares it; every `catch` names a concrete class rather than `Throwable` or
`Exception` — and one that binds a variable and then throws must hand that variable on. An SPL
exception becomes ours by *extending what it already was*, so every `instanceof`, `catch` and
`expectException` keeps matching; see [architecture.md](architecture.md#exceptions).

**Three smaller guidelines ride along**, all at zero and all there as regressions rather than as
work:

- `declare(strict_types=1)` on every file — without it a `string` parameter starts coercing an
  `int`, in one file, silently;
- a declared type on every parameter, return and property;
- a backing value on every enum — because the TypeScript mirrors compare *values*, so a pure case is
  a parity test with nothing to compare.

## What is deliberately not checked

**`mixed`**, which appears a dozen times in declared types under `src/`: as a collection's element
type — `with()`, `find()`, `first()`, `last()`, `guard()` — and as what `Diagnostics::muted()` hands
back from a closure whose result it cannot know, an `fopen()` handle or `false`. It is there because
PHP has no generics rather than because anybody chose it, and a fourth attribute for a set that
cannot change would be ceremony.

**The tooling (`tools/lib/`)**, except for the `@` rule: it is not deployed, it is outside the coverage source, and
the doors it is made of (`unpack`, `preg_match`, `file`) are most of what it does.
