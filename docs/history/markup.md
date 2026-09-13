# History — the markup tree

How the tree [architecture.md](https://github.com/neurosysgg/neurosys-webspace/blob/master/docs/architecture.md#the-markup-tree) describes got its shape. Format:
see [README.md](https://github.com/neurosysgg/neurosys-webspace/blob/master/docs/history/README.md).

## Attributes

### 2026-09-05 — the prefix list that missed a spelling (`4fb5d1a`)

The scheme check in `Element` used to decide "is this somewhere on this site" by pattern. As
CLAUDE.md told it:

> This used to be a two-entry list of the prefixes an authority can open with — `//host` and
> `/\host`, the same URL spelled the way that does not look like it — and a list of the spellings
> that occurred to us is the shape of mistake this class exists to avoid. It had missed one: the
> WHATWG parser strips tab, CR and LF from a URL *before* parsing it, so `/\r\n/host` is `//host` is
> `https://host`, and every "starts with a slash" test in the world calls it a path of ours. PHP 8.5
> ships that parser, so `Element::staysOnThisOrigin()` resolves the value the way a browser would and
> asks whether it landed where it started. `Navigation.ts` has done it this way round on the client
> all along — for want of a URL parser it was the stronger half, and now both halves are the same
> check.

architecture.md put the same thing in one line: "Pattern matching missed a case".

### 2026-09-06 — the tuple becomes `Attribute` (`7f52764`)

> **An attribute is an `Attribute`**, held in a `SearchableCollection` keyed by its name. It used to
> be an `array{AttributeName, string|null}` in a map keyed by the same string — a two-slot tuple
> destructured in the one place that read it, where `[$attribute, $value] = $pair` only reads
> correctly if you already know the answer.

### 2026-09-08 — attribute values get a grammar, and the allowlist gets a vocabulary (`2405b8f`)

`AttributeValue` arrived after the enum-valued attributes, "the same shape `HeaderValue` has on the
HTTP side, arrived at the same way and second for the same reason": `width=device-width,
initial-scale=1.0` "was being assembled inside the `->attr(…)` call, which is the one place a grammar
cannot be checked."

The same commit gave the scheme allowlist its enum:

> The allowlist itself is `UrlScheme` cases now rather than two strings — a scheme is a fact about a
> URL and not about markup, so it sits in `Support/` beside `Charset` for the same two-reader reason,
> and the footer and the imprint build their `mailto:` through it instead of concatenating a prefix
> each.

## Rendering

### 2026-09-08 — what laziness cost the two loops that map (`fbb04d3`)

When `map()` started answering with a lazy collection, the two call sites that call it in a loop
paid for it:

> The one hot call site that *is* lazy is `Element::renderChildren()`, which went from
> `implode('', array_map(…))` at **2.29 µs** to `map(…)->join('')` at **5.16 µs**; over a whole page
> that is **1.141 ms → 1.257 ms**, about **10%** of the time spent rendering markup and a fraction of
> a percent of a request.

> The other place `map()` is called in a loop is `ContentSecurityPolicy::render()`, where the inner
> one runs inside the outer one's callback: **16.45 µs → 40.32 µs** per render, so **+24 µs on every
> response**.

The current figures are in [collections.md](../collections.md#what-it-costs).

## Hand-authored markup

### 2026-09-09 — the audited hole, read back in (`17cca79`)

Until this commit the two halves of the privacy policy went out verbatim. As CLAUDE.md told it:

> **There used to be a single hole here, and closing it is the most recent thing that happened to
> this tree.** `RawHtml` emitted the two halves of `data/privacy.*.html` verbatim — a hand-authored
> document rather than markup a view assembles — checked by nothing but a docblock saying never to
> construct one from anything a request can influence, and a test pinning its call files. A
> convention with a test behind it is not a guarantee.

and architecture.md, under the heading *There is no hole, and there used to be one*:

> `data/privacy.de.html` and `data/privacy.en.html` are a hand-authored document rather than markup
> a view assembles, and they used to go out through `RawHtml` — a node that emitted its string
> verbatim, checked by nothing but a docblock and a test pinning its call sites.

Refusing any HTML5 parse error was "the check `RawHtml` could never make". The standing instruction
— never parse anything a request can influence — "survived the change and is the reason the
call-site pin did too". `h4`, `ul`, `li` and `em` joined `HtmlTag` for the policy, which uses all
four.

The cost, against what it replaced:

> Per half, with no Xdebug loaded: 0.071 ms to parse, 0.242 ms to walk into the tree, 0.262 ms to
> render it back out, against 0.004 ms for the old verbatim `str_replace`.

And the one measured result that went the opposite way from the obvious one:

> Decoding character references made the document *smaller on disk and larger on the wire*:
> `/privacy` went from 44,404 raw bytes to 42,528, and its gzipped body went **up**, 12,399 → 13,378.
> `&auml;` is six highly repetitive bytes that gzip almost to nothing; the `ä` that replaces it is two
> bytes that do not. The compression ratio fell from 72.1% to 68.5% — still the right trade at 29 KB
> saved, but not the direction anybody would have guessed.

The German half lost about 2.4 KB of `&auml;` in the process.

## From the code comments

*Moved out of comments under `src/`, `test/` and `tools/` when those were brought to the present
tense. Quoted as they stood; an ellipsis marks where a sentence ran on into the rule it supported,
which stays in the code.*

### 2026-09-04 — escaping at every call site (`ee0a7dd`)

From `HtmlTest`:

> Escaping used to be a htmlspecialchars() call per attribute at every call site

### 2026-09-04 — tag names as string literals (`9fd5f88`)

From the verify script:

> the tag names stopped being string literals when Tag arrived

### 2026-09-05 — the constructor was a way around escaping (`a2e8502`)

From `HtmlTest`:

> It did not before: attr() escaped on the way *in* and render() emitted whatever it found, so the
> constructor was a way around escaping entirely — a public one, documented as taking values that
> were already escaped and trusted to have been.

### 2026-09-05 — the terminal command was two concatenations (`8e931d3`)

From `ViewTest`:

> the two concatenations it replaced could not

### 2026-09-05 — `Charset`'s two spellings (`dceda61`)

> Both forms, pinned to the literals the three readers carried before this enum existed

### 2026-09-07 — `Element`'s children were a bare array (`c078707`)

From `HtmlTest`:

> the constructor took a plain `array` whose `list<Node>` lived in a docblock, which is the
> arrangement the attributes were moved out of one parameter earlier

### 2026-09-04 — the views were heredocs (`ee0a7dd`)

From `Element`: "heredocs the views used to be".

### 2026-09-08 — the viewport was a string, argued for (`2405b8f`)

From `ViewportContent` and `MetaName`:

> {@link MetaName::Viewport}'s docblock used to argue this value "stays a string: it is a descriptor
> list with its own grammar, not a case".

> That used to be the string `width=device-width, initial-scale=1.0`, on the reasoning that it is a
> descriptor list … right about the grammar and backwards about what follows from it.
