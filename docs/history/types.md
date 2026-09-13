# History — types

How collections, exceptions, `Config`, `SitePath`, the data-file vocabulary and the guidelines got the
shape [collections.md](../collections.md), [guidelines.md](../guidelines.md) and
[architecture.md](https://github.com/neurosysgg/neurosys-webspace/blob/master/docs/architecture.md) describe. Format: see [README.md](https://github.com/neurosysgg/neurosys-webspace/blob/master/docs/history/README.md).

## Collections

### 2026-09-04 — three hand-rolled loops, one guard (`d9e65ca`)

> A bare `array` with a `foreach`-and-`instanceof` check in a constructor is the thing they replace —
> that loop existed three times, in `Release`, `Terminal` and `SoundCloudEmbed`, and it is now
> `TypedItems::guard()`'s single `TypeError`.

### 2026-09-05 — one trait instead of two copies (`8e931d3`)

`TypedItems` took the store and the type check out of both classes; `static::class` kept the
`TypeError` reading "exactly as it did when the `sprintf` sat in both files".

### 2026-09-06 — the query methods, and `all()` as the escape hatch (`c8bd783`)

architecture.md as it stood before the chain went lazy:

> Both collections share `TypedItems`, which holds the store, the type check, and the six query
> methods that are **the site's default way of handling a group of things**: `where()`, `map()`,
> `join()`, `first()`, `keys()`, `isEmpty()`. They exist because `all()` had become the escape hatch
> out of the type — sixteen call sites reached for it or hand-rolled a `foreach`, nine of them only to
> hand the array to `array_map`. […] `map()` answers with a `list` rather than a collection, because
> a collection is defined by a `class-string` and most call sites map to a `string` or an `array`;
> `where()` returns `static` and chains, `map()` ends the chain.

The same round moved three more groups in:

> **What moved into them since**, each for the same reason — a shape crossing a public boundary with
> nothing checking it: `Element`'s attributes (a `SearchableCollection<Attribute>`, keyed by name,
> which is what keeps last-write-wins), `ReleaseFolder`'s audio files (keyed by `ReleaseFormat` value,
> in the order the catalogue lists them), and an outbound `Request`'s headers and body fields. None of
> those had a hand-rolled check to replace, which is the weaker half of the rule: they had *no* check,
> and an `array<string, string|FilePart>` is a docblock's promise rather than the language's.

### 2026-09-07 — five more, and the 304 an empty collection let through (`c078707`)

> **And since that, five more, found by asking which `array_*` calls the query methods should have
> been doing.** `Element`'s **children** are the one worth reading twice: they are the sibling
> parameter of the attributes on the same public constructor, so the argument that moved one had
> already been made about the other and stopped one parameter short. […] In `tools/`:
> `Project::$markers`, whose `markersOf()` was `where()` spelled `array_values(array_filter(…))`;
> `Call::$arguments`, reached by three public factories; and `DemoStage::$sources`, whose `write()`
> was another longhand `where()`.
>
> One of those turned up a live bug rather than a latent one. `ViewResponse::send()` guarded the 304
> with `$cache !== []`, which is true of *every* `Collection` — so a gated page started answering 304
> to a guessed validator the moment `cacheHeaders()` returned one. `isEmpty()` is what it should have
> been asking all along, and the test named for that hazard caught it in the same run.

### 2026-09-07 — scalars, and the type that rejected everything in silence (`e5fb1fe`)

> **`T of object` was a limit of the check rather than a decision about the data.** `guard()` was a
> bare `instanceof`, which is a test only an object can pass — so `list<string>`, `list<float>` and
> every counted tally stayed outside the type for a reason that was never argued, only inherited.

The declared type started being checked in the constructor in the same commit; before it,
`new Collection('Reelase')` "was not an error but a collection that silently rejected everything ever
offered to it". Refusing `'array'` as a declared type is what forced `CspSourceList` into existence.

The same commit settled the store-versus-parameter question, which CLAUDE.md had argued the other
way round:

> **The rule is about the parameter, not the store, and that distinction took a while to draw.** This
> file used to say `PermissionsPolicy::$denied` and `ContentSecurityPolicy::$directives` wanted no
> collection because they are built only through a variadic that PHP already enforces. The first half
> of that is still exactly right […] The second half was a non-sequitur: what a class *stores* after
> the variadic has guarded the boundary is a separate question, and storing an array meant every one
> of these classes rendered itself with `implode(', ', array_map(…))` — which is `join()` spelled out.

architecture.md kept the old argument until the split into `docs/collections.md`:

> **When *not* to reach for a collection:** `PermissionsPolicy::$denied` and
> `ContentSecurityPolicy::$directives` are private, never escape, and are only ever built through a
> variadic — which PHP already enforces.

### 2026-09-08 — the chain that left the type at its first step (`fbb04d3`)

`map()` started answering with a collection, every step went lazy, and `all()` and `keys()` were
removed in favour of `toArray()` / `toValues()` / `toKeys()` — "`all()` said nothing about which of
the two shapes you were getting". Three things were written down about the way there:

- `map()` "used to reindex — the implementation talking, since `array_map` given two arrays returns
  one."
- `settled()` "is the member this change made necessary" — `DemoStage::write()` had started writing
  nothing, because its ffmpeg predicate was left pending.
- Resequencing after every step "is how this was first written", and it "spent a generator layer per
  step renumbering keys that were already in order"; `where()` alone pushes `sequenced()` now.

`ReleasesView::card()` had its own parameters swapped to match the value-first callback order
"rather than become the exception". The cost deltas are in [markup.md](markup.md#rendering).

A third query method that had been designed and not written, `mapTo(class-string, fn)`, is "what
`map()` became when it started answering with a collection, and it needed no `class-string` argument
in the end because the callback's return declaration already carries one."

### 2026-09-08 — `is_a()` rather than `!==` (`abde161`)

The element-type guard in the seven `verify()` methods compared with `!==` until this commit, which
refused a collection of any subclass of `Format` for `Release::$formats`.

### 2026-09-09 — `unique()`, the member that got its second caller (`bd07a6a`)

> **`unique()` was the third of these and is now written, which is worth recording rather than
> quietly editing away.** The objection was sound and was answered rather than overruled: it had one
> caller, and a member added for one caller is a member nobody else will find. What changed is that
> `Demo::verify()` became the second — it was spelling `count(array_unique($labels)) !==
> count($labels)` over a `toValues()` — so the same argument now says write it.

Adding `array_unique`→`unique()` to `GuidelineTest`'s call table is "how `unique()` arrived".

## Exceptions

### 2026-09-09 — the SPL exceptions become ours (`bd07a6a`)

> `CollectionException` and `GuidelineException` were a bare `TypeError` and a bare
> `InvalidArgumentException`; making them ours by *extending what they already were* means every
> `instanceof`, every `catch` and every one of the suite's existing `expectException` calls still
> matches.

The exception rule's first run: "All four were at zero when the rule was written except the first,
which was eight — five `TypeError`s in `TypedItems` and three `InvalidArgumentException`s in the
attributes." `MarkupException` became abstract over `ElementException`, `ParserException` and
`TerminalException` in the same commit, "which is why the split cost no test a change";
`SiteException` and the last-resort handler in `public/index.php` arrived with it. Before the handler,
an uncaught throwable was a PHP fatal whose presentation was left to the host's `display_errors`.

### 2026-09-09 — `ApiException` above `UpdateException` (`5baabd4`)

> `UpdateException` was a bare `RuntimeException` before there was an API around it, and
> `ApiException` is one too, so nothing a caller could do changed — only that the throw now says
> which layer raised it.

## `Config`

### 2026-09-04 — the facts that were stated twice (`33abdff`)

> A constant earns a place only by being **identity**, **environment**, or **already stated twice**.
> That third one is what made it worth writing:
>
> - `https://my.hidrive.com` was in `HiDriveLink` *and* in the CSP. Change one and covers keep
>   loading right up until the policy blocks them.
> - `https://w.soundcloud.com` was in the CSP and again in `SoundCloudPlayer.ts`, in another
>   language. Drift there means the player is blocked by our own policy with nothing in the page to
>   explain it.
> - `neuro.SYS` was in eleven places; the `data/` directory was derived seven times, one of them by a
>   different idiom (`__DIR__ . '/../../../data/'` rather than `dirname(__DIR__, 3)`). That is where
>   the credentials live.

## Addresses

### 2026-09-08 — the link that renders perfectly and 404s (`2405b8f`)

> The router used to declare `/releases/{slug}` while nine views concatenated `'/releases/' . $slug`,
> in different files, with nothing between them — the largest untyped vocabulary left here and the
> one that fails most quietly: a view naming a path the router does not have renders a link that
> looks perfectly fine and answers with the site's own 404.

## Files and `$_SERVER`

### Date not recorded — the `@mkdir` that was reverted

> The downloads log's directory is excluded from `deploy.sh`; an `@mkdir` was once added to "fix"
> that, had to be reverted, and the directory it had already made on the live server had to be
> deleted by hand.

### 2026-09-06 — a path stops being a string (`7f52764`)

`File` replaced five classes each asking `is_file()` in their own words — "a collapse that also
removed a real fault, since `is_file()` says nothing about a file that is present and unreadable and
the resulting warning printed into the page ahead of the doctype." `PrivacyController::policy()`
still records its old form, `is_file($f) ? file_get_contents($f) ?: '' : ''`, in its docblock.

### 2026-09-07 — the 401 that reads as a wrong password, and the catalogue that empties in silence (`903e324`)

`ServerVariable` and `DataFile` arrived together. Before `DataFile`, the vocabulary "already existed
before the enum did — as a hand-maintained data provider in `ConfigTest` that listed four of the
seven and could not notice the rest." (There are nine cases now; `UpdateKey` came with the push
endpoint.)

### 2026-09-08 — one privacy file becomes two (`2405b8f`)

> `privacy.de.html` and `privacy.en.html` were one `privacy.html` holding a German document and an
> English one end to end — which is how two e-recht24 exports get concatenated by hand, and it was
> fine for as long as the order was fixed.

Because `deploy.sh` has no `--delete` on `data/`, "the old `privacy.html` is still on the mount and
has to be removed by hand". Whether it has been is not recorded here.

### 2026-09-09 — `@` becomes `Diagnostics` (`bd07a6a`)

> It was twenty-one sites, nineteen under `src/` and two under `tools/lib/`, and every one is now
> `Diagnostics::muted(static fn(): … => …)`.

`MarkupParser` had hand-rolled an error handler to collect parse errors; `Diagnostics::watched()`
replaced it.

## The guidelines

### 2026-09-08 — the first run: arrays and strings (`15a10f1`)

> Every one of those arguments was made one class at a time and none of them had anything watching
> it — so the only thing between this codebase and a slow drift back to arrays-and-strings was
> whoever wrote the next method.

The string rule's first clause "found the one live drift: `Request::fromGlobals()` defaulted to a
`'GET'` the enum had spelled all along." The two attributes then existing "were caught by their own
rule sharing half a sentence" before an attribute's own arguments were exempted.

> **What the test changed on its first run**, besides the `'GET'`: `SecurityHeaders::all()` returns a
> `Collection<Header>` rather than a list, which is what the three `Response` classes already took
> and is the right way round for the five headers that cover the 401 as well as the 200; and
> `WaveformBand::bands()` does the same, having been the one group under `src/` with no door and no
> variadic behind it. Both then wanted `#[\NoDiscard]`, so `NoDiscardTest` grew an entry — which is
> two of these tests agreeing rather than either being wrong.

At the time CLAUDE.md counted thirty-five array declarations and seventeen literals carrying an
excuse; at `946c4fd` there are 31 `#[BareArray]` and 20 `#[BareString]` attributes.

### 2026-09-09 — the three habits nothing was watching: `array_*`, `@`, SPL exceptions (`bd07a6a`)

The call rule, the `@` rule and the exception rule arrived together, with `BareCall` as the third
excuse attribute. See the entries under [Exceptions](#exceptions) and
[Files and `$_SERVER`](#files-and-_server) for what each one changed.

## From the code comments

*Moved out of comments under `src/`, `test/` and `tools/` when those were brought to the present
tense. Quoted as they stood; an ellipsis marks where a sentence ran on into the rule it supported,
which stays in the code.*

### 2026-09-05 — `Profile` was an array shape (`156de9d`)

> It replaced an `['platform' => …, 'url' => …]` array shape

### 2026-09-05 — the title suffix was written six times (`156de9d`)

From `PageTest` and `ViewTest`:

> Six views used to write out `' — neuro.SYS'` between them

### 2026-09-05 — before `#[\NoDiscard]`, a naming convention (`4fb5d1a`)

From `NoDiscardTest`:

> That was the whole enforcement mechanism, and it was a naming convention doing a compiler's job.

### 2026-09-07 — `ConfigTest`'s four data files (`903e324`)

> The provider used to be four names written out here, which is the arrangement DataFile was
> extracted from: it listed the four the repository carries, said nothing about the three it does
> not, and had no way to notice a fifth arriving.

### 2026-09-04 — values that used to be shapes or pastes (`33abdff`, `d9e65ca`, `7495312`, `00eee34`)

One-line framings, each cut from the docblock of the class that replaced the thing it names:

- `Wordmark`: "Both used to spell it out as three pieces"
- `Profile`: the "shape … `ProfileRepository` used to hand back"
- `HiDriveLink` / `SoundCloudEmbed`: "the full share URLs / raw HTML that used to be pasted"
- `PlainTextResponse` (2026-06-17): "the JetBrains attribute that used to sit here as well is gone"

### 2026-09-05 — `DownloadLogEntry` read corrupt input as data (`a2e8502`)

> Two things it used to get wrong: - Decoding was `assoc: true`, which renders `{}` and `[]` as the
> same empty array — so a log line of `[1,2,3]` passed the `is_array()` guard and hydrated into an
> entry of four empty strings, counted in the total and filed under `/`. Corrupt input read as real
> data. … - Nothing checked the *values*. … a single malformed line took the entire stats page down
> with a 500 rather than being skipped

### 2026-09-06 — `DownloadStats` was a tuple and a fourth argument (`7f52764`, `e5fb1fe`)

> That distinction used to be a fourth constructor argument on the view.

> Replaces an `array{int, array<string, int>, array<string, int>}`

> The `(string)` cast is the one `StatsView` used to make with
> `array_map(strval(...), array_keys($rows))`.

`StatsView` said of it: "which is what this replaced".

### 2026-09-08 — `SectionPosition` was an array shape (`fbb04d3`)

> the `array{section: Section, offset: float}` it used to be … It was also the last thing under
> `src/` that … SCALARS could not let a collection hold

`TypedItems` said the same from its side: "the two callbacks on this site that used to map to an
`array`", and "the same decision `rebuilt()` used to make one layer out".
