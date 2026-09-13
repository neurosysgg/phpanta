# Architecture — the framework

How a request travels through Phpanta, the layers it passes, and the disciplines every layer keeps.
A site's own layers — its controllers, models and views — are described in its own documents;
neuro.SYS's are in [its architecture](https://github.com/neurosysgg/neurosys-webspace/blob/master/docs/architecture.md).

## The app

A site is a subclass of `Phpanta\App` — neuro.SYS's is `Site` — and there is exactly one per
process, booted by the site's autoloader and read back with `App::current()`. It is how a site's facts
reach the framework: the site gate, the API's serial, the health report and the CSP all sit at the
bottom of call chains that never had a reason to carry those facts, and asking the booted app is what
lets them stay that way.

Three rules keep the static honest:

- **Constructing one does nothing** — no `DOCUMENT_ROOT`, no file, no request. Booting is therefore
  free, which is what lets the autoloader do it for every entry point at once.
- **Booting is idempotent and exclusive.** The same class twice is the same instance; a second class
  is refused, because two apps in one process would each be reading the other's data.
- **Nothing caches what an app answers.** A test that needs a different answer passes a different
  object to the class that asks — `UpdateApplier` takes its `Deployment` — rather than swapping the
  booted app out from under everything else.

What a site owes, and what it may add:

| | Method | Answers |
|---|---|---|
| owes | `name()` | the Basic Auth realm, and the title a page is named under |
| | `above()` | the deployment directory — the one holding `autoload.php` |
| | `routes()` | the site's routes; `routeTable()` appends the framework's API route after them |
| | `notFound()` | the page for an address the site does not have, to a method that reads |
| | `languages()` | the languages it is written in, its default first |
| | `shell()` | the document every page is rendered inside |
| | `vocabulary()` | every tag and attribute hand-authored markup may be parsed into |
| | `buildId()` | which build is deployed, as `update v1 version` reports it |
| | `ownDataFiles()` | the site's own files under `data/`, beside the framework's credentials |
| may add | `contentHosts()` | third-party origins per CSP directive; none by default |
| | `ownRequirements()` | what it needs of its host beyond the framework's floor; none by default |

**What the framework derives from those is final** — `data()`, `webroot()`, `updateSerial()`,
`dataFile()`, `dataFiles()`, `logs()`, `errorLog()`, `requirements()`, `routeTable()` and `run()` —
because each derivation was measured into its shape, and a site getting one of them slightly
different is how a mirror deletes the wrong tree. `webroot()` is the one path that is not derived:
the directory is `public/` in a repository and whatever the host calls it on the server, so it is
asked of `DOCUMENT_ROOT` — for its basename only, and refused rather than guessed when that is blank,
relative, outside the deployment or absent.

`run()` is the request, in the order it has always had: the error log, the security headers, the
request, the site gate, the route. The site's `public/index.php` installs a last-resort exception
handler first — one that depends on nothing, because it has to work when nothing else did — and then
calls it.

## The request, traced

Follow one request all the way through. Everything below happens for `GET /releases/ill`.

### ⓪ The last-resort handler

**It is first because it is the one that has to work when nothing else did.** Without it an uncaught
throwable is a PHP fatal, which on a host whose `display_errors` we do not own is either a blank page
with a 200 already on the wire or a stack trace naming absolute paths — a choice left to a php.ini
rather than made here. It logs the fault, sends a 500 if `headers_sent()` says there is still a
response to shape, and writes a body of exactly `500`. It depends on nothing: no `Response`, no
`MimeType`, no view, because reaching for the markup tree would be reaching for the most likely
thing to have just broken, and a throw inside an exception handler is a fatal with the original
swallowed. The one type it does name is `SiteException`, to say in the log whether the fault came
from this repository — and that is free, see [Exceptions](#exceptions).

### ① Security headers, before anything can fail

[`SecurityHeaders::send()`](../src/Http/SecurityHeaders.php) runs *before* the request is
even parsed. That ordering is the whole design: the 401 that `Auth` exits with, the 405 the router
refuses a POST with, and the 303 a download redirects with all get the full header set, because none
of them can run before this line.

It also *removes* one header — `X-Powered-By`, which PHP appends with its exact patch version before
any of our code runs. See [security.md](security.md) for the policies themselves.

### ② `$_SERVER` becomes a `Request`

[`Request::fromGlobals()`](../src/Http/Request.php) is the only place a request is built
from the superglobals. What comes out is `readonly` and typed, and three of its decisions are
deliberate:

| Member | Decision |
|---|---|
| `method()` | `HttpMethod::tryFrom()` — **nullable**. An unrecognised verb is `null`, and null is not read-only. Never guessed as GET. |
| `path()` | Parsed with `Uri\Rfc3986\Uri::parse()`, which returns `null` on failure — so `??` is a real guard, where `parse_url()`'s `false` would not be. A target that will not parse comes back as **its own path**, everything up to the first `?` or `#`. That still matches a placeholder route, because `{param}` compiles to `([^/]+)`; see [security.md](security.md). |
| `authUser()` / `authPassword()` | Read from `PHP_AUTH_*`, falling back to decoding `Authorization` — Strato does not always hand PHP the former. See [deployment.md](https://github.com/neurosysgg/neurosys-webspace/blob/master/docs/deployment.md). |

The path is **raw, not decoded**: a route matches the target as it was sent.

**The `$_SERVER` keys a request is built from are `ServerVariable` cases**, because every reader of
that superglobal ends in a default — `?? 'GET'`, `?? '/'`, `?? ''` — which is exactly what makes a
misspelled key indistinguishable from a request that did not carry the value. `PHP_AUTH_USER` is the
one that matters: a typo there leaves the user `''`, which no stored credential equals, so both
gates refuse everything with a 401 that reads as a wrong password.

Not every key belongs on it, and the rule is whose name it is. A request header arrives under
`HTTP_` plus the name upper-cased with dashes as underscores — PHP's transform, so
`Request::header()` applies it to a `RequestHeader` case rather than anybody retyping the result. A
case earns a place on `ServerVariable` when that derivation cannot reach the name
(`REDIRECT_HTTP_AUTHORIZATION` is Apache's invention, not HTTP's) or when the reader has no
`Request` to ask — which is `HTTP_REFERER`, deliberately: `DownloadLogger` must read it *behind* the
`DOWNLOAD_LOGGING` guard, and an argument would be evaluated in front of it. Note the spelling. The
header lost an `r` in 1996 and the property it fills, `$referrer`, did not.

### ③ The pre-launch gate

[`Auth::requireSiteAuth()`](../src/Service/Auth.php) checks for `data/site_auth.php`. If
the file is absent it returns immediately — *that absence is how the gate is switched off*, and the
file is gitignored precisely so the repo copy cannot switch it on. It is also why a misspelled
`DataFile` case there would not fail but stand the gate down; see [Data files](https://github.com/neurosysgg/neurosys-webspace/blob/master/docs/architecture.md#data-files).

### ④ Routing

[`Router::dispatch()`](../src/Router.php) does two things, in order:

1. **The match.** Each [`Route`](../src/Support/Route.php) is a
   [`Path`](../src/Support/Path.php) case, a factory closure and a
   [`MethodPolicy`](../src/Support/MethodPolicy.php). `{param}` compiles to `([^/]+)`, and
   captures are passed positionally to the factory.
2. **The method gate**, asked of the matched route rather than globally. Nine routes are
   `ReadOnly` and answer anything but `GET`/`HEAD` with a 405 whose `Allow` comes from
   `Allow::readOnly()` — derived by filtering the cases, so the header cannot advertise something
   the gate does not do. `/api` is `Delegated`: the router forms no opinion and its controller
   answers every method itself, because any opinion the router formed would tell an unsigned caller
   the address is real. Two policies rather than a set of methods per route, because a route naming
   its own set would make its 405 name `POST`.

An unmatched path falls through to
[`UnroutedController`](../src/Controller/UnroutedController.php), which gives the 404 for a
read verb and the 405 for a write one — and is the same object `ApiController` delegates to, so
that no address under `/api` and an address that does not exist can answer differently.

The route table is the app's — [`App::routeTable()`](../src/App.php) — one entry per `Path` case,
in match order.

**Every address the site has is a `SitePath` case, and that is one vocabulary rather than two.** A
view naming a path the router does not have would render a link that looks perfectly fine and answers
with the site's own 404, so views never concatenate a path: the case's *value* is the pattern,
placeholders and all, so `Route::matches()` matches with it and `SitePath::to(...)` fills it in; the
placeholder syntax is one constant both read. `to()` refuses the wrong number of values, which is the
check a concatenation cannot make — `'/releases/' . $slug . '/'` is a perfectly good string and a URL
that matches nothing. Two details worth knowing: each value is `rawurlencode`d, a no-op for every
slug, format and label in `data/` today; and the callback that fills them **must** be a `function`
with `use (&$values)`, because `fn()` captures by value and every placeholder would take the first
value — `/releases/ill/ill`, well formed, matching a route, and the wrong page. `RoutingTest` pins
that one by name.

**The tests keep writing paths out in full, deliberately.** A test asking
`SitePath::Release->to('ill')` would pass with the enum wrong. Same reason the verify script curls
real URLs.

### ⑤ The controller, the view, the response

A controller fetches its own data. Nothing is injected for it, and there is no shared context object
— a release page's controller, say, constructs a
`ReleaseRepository`, asks it for a slug, and returns either a `ViewResponse` wrapping `ReleaseView`
or one wrapping `NotFoundView` with a 404.

The optional `?ReleaseRepository $releases = null` constructor parameter on the release and download
controllers is a **test seam and nothing else** — it is how the "format staged, no link yet" branch
gets exercised without a real release.

Then [`ViewResponse::send()`](../src/Http/ViewResponse.php) makes the one branch that
matters:

- **full page** → `Layout::wrap($view)` → a `Document`
- **AJAX fragment** → a `Fragment` of `<title>…</title>` plus the view's content

Both send `Content-Type: text/html; charset=utf-8` explicitly. The fragment is why: a page carries
`<meta charset>`, a fragment carries nothing, so the header is all the browser has.

Download routes (`/releases/{slug}/{format}`) call `DownloadLogger` and answer with a 303 to the
HiDrive direct-download link. **The demo routes are the one place a file passes through PHP**:
`/demos/{slug}` and `/demos/{slug}/{label}` sit behind that demo's own password, and the audio lives
under `data/` where the web server cannot reach it, so the password covers the bytes rather than only
the page. `FileResponse` answers byte ranges because an `<audio>` element seeks by asking for one.
See [demos.md](https://github.com/neurosysgg/neurosys-webspace/blob/master/docs/demos.md).

---

## `Http/` — the wire

Everything about a request or a response is a typed value here, not a string.

| Type | Is |
|---|---|
| `Request` | readonly, built once from `$_SERVER` |
| `Response` | an interface with one method: `send(Request): void` |
| `ViewResponse` | renders a `View`; the only response that returns rather than exiting |
| `RedirectResponse` | `Location` + status, then `exit` |
| `PlainTextResponse` | body + status + extra headers, then `exit` |
| `FileResponse` | a file under `data/`, whole or as a byte range — the demo audio |
| `Header` | a `HeaderName` and a `HeaderValue`; formats `Name: value` in one place |
| `MimeType` | a `TopLevelType`, a validated subtype, and a `Charset` |
| `HttpStatusCode` | every status code, backed by its number |
| `HttpMethod` | the eight methods, and which of them only read |

Most responses `exit` and one does not. That is not an inconsistency: a redirect and a plain-text
refusal are terminal, and a `ViewResponse` returns so that `echo` is the last thing that happens.
The consequence is that **PHPUnit cannot observe the exiting ones**, which is why the verify script
exists — see [testing.md](https://github.com/neurosysgg/neurosys-webspace/blob/master/docs/testing.md).

**Header names live in two enums on purpose.** `SecurityHeader` is exhaustive and tested as such —
`SecurityHeaders::headers()` sends exactly its cases. `ResponseHeader` is everything else. Folding
them together would make the exhaustiveness assertion meaningless. `RequestHeader` is the inbound
direction; all three implement `HeaderName`, so `Header` formats any of them.

**Header *values* are typed too, because a header value has a grammar** — a quoted `ETag`, a
comma-separated `Allow`, `Basic realm="…"`, `max-age=…; includeSubDomains`, `no-store, private` —
and a `new Header(…)` call site is the one place a grammar cannot be checked. `HeaderValue` is one
method, `render()`, and its implementations are the objects that know each header's grammar:
`ContentSecurityPolicy`, `PermissionsPolicy`, `StrictTransportSecurity`, `ReferrerPolicy`,
`ContentTypeOptions` and `MimeType`, plus `CacheControl`, `ETag`, `Vary`, `Allow`, `BasicChallenge`
and `Location`. `Header` accepts nothing else, so a value cannot be assembled as a string at the call
site. `Location` accepts only an absolute `https://` URL, the same shape `Profile` demands.
`SecurityPolicyTest` pins the set in both directions — every implementer must be rendered by its
table, and the table must name every implementer.

**`Content-Type` is a `MimeType`**, and not a string with `; charset=utf-8` stapled onto it. A class
rather than an enum for the reason `StrictTransportSecurity` is one: the value carries a parameter,
and a case cannot hold one. The two the site sends are `MimeType::html()` and `MimeType::plainText()`,
so no call site types a subtype, and a malformed one throws where it is written the way `CspHost`'s
origin does. The charset is the half that earns the class — `nosniff` stops a browser guessing the
type, and nothing stops it guessing the encoding.

## `Support/` — the shapes

`Collection<T>` and `SearchableCollection<T>` with the `TypedItems` trait they share — see
[collections.md](collections.md). `File`, `Directory` and `Diagnostics`, below. `Route`, `SitePath`,
`MethodPolicy` and `RouteInitialization`. `Charset` and `UrlScheme`. `PasswordHash` and `PublicKey`;
`TarArchive` and its entries. `BareArray`, `BareString` and `BareCall`, the three attributes that
excuse an exception to a guideline — see [guidelines.md](guidelines.md).

**The encoding is one fact.** `Charset` sits in `Support/` because both the header and the markup
tree read it and `View/` has no other reason to know anything about HTTP. It carries two forms —
`utf-8` for the header parameter, `canonical()` for the document head and for the site's one escaping
call — because those two readers write it differently, and one enum keeps both spellings in step.

## The type discipline

Almost everything here is one of four shapes. Recognising which one you need is most of the work of
adding something.

### 1. An enum, when the vocabulary is closed

`Genre`, `MusicalKey`, `ReleaseFormat`, `HttpStatusCode`, `CspDirective`, `Tag`, `SitePath`,
`DataFile`, every attribute name. A typo becomes a parse error instead of a value the browser
silently drops.

Enums here list **what is used, not what exists** — `HtmlTag` has the elements the site emits and no
others. The exceptions are the ones where the registry really is closed (`TopLevelType`,
`HttpStatusCode`) or where exhaustiveness is the point (`SecurityHeader`).

Single-case enums are not a mistake: `Charset`, `RequestedWith` and `Doctype` exist to make a value
a *type*, not to offer a choice. Every enum is backed — see [guidelines.md](guidelines.md).

### 2. A value object with a `verify()`, when the value arrives as free text

`HiDriveLink` (a share id), `Profile` (a URL), `CspHost` (a bare origin), `MimeType` (a subtype),
`Release` (a positive bpm). Each validates in its constructor and throws.

The point is **where** it throws. These all fire while `data/` is being loaded, naming the offending
value, rather than surfacing later as a broken link nobody clicks.

`Profile::url` is checked here *and* again by `Element` at render time. That is not redundancy: the
renderer is the backstop and reports the fault on whatever page draws the footer; the constructor
reports it where the mistake actually is.

### 3. An interface, when the axis is "which provider"

`FileLink`, `Embed`, `Response`, `Controller`, `Node`, `TagName`, `AttributeName`, `HeaderName`,
`HeaderValue`, `AttributeValue`, `CspSource`. Each has one or two methods and exists so a call site
can hold the abstraction without knowing the implementation.

### 4. An immutable collection, when a group crosses a public boundary

`Collection::with()` **copies**. That is what makes a collection safe inside a `readonly` value
object — `readonly` protects the reference, not what it points at. The name is chosen so a dropped
result reads as wrong, and PHP 8.5 enforces it: the builders and the query methods carry
`#[\NoDiscard]` and `phpunit.xml.dist` sets `failOnWarning`, so a discarded result is a failing test.
A collection replaces a hand-rolled type check on data crossing a public boundary; it does not
replace a variadic. The whole of it is in [collections.md](collections.md).

---

## Exceptions

Every condition this site can be in has a name, and all of them live in `Exception`.
Thirteen classes — one of them abstract — and one interface:

| Class | Is | Raised by |
|---|---|---|
| `SiteException` | the marker, an interface | — |
| `ApiException` | a signed request that cannot be read or trusted | `ApiCredential`, `ApiEnvelope` |
| ` └ UpdateException` | a payload that cannot be read or applied | 5 classes |
| `MarkupException` | abstract; the three below | — |
| ` ├ ElementException` | an element asked to be what no element can be | `Element` |
| ` ├ ParserException` | markup outside this site's own vocabulary | `MarkupParser` |
| ` └ TerminalException` | rows that cannot reach the element that draws them | `Terminal` |
| `CollectionException` | a collection asked to hold or produce the wrong type | `TypedItems` |
| `GuidelineException` | an excuse for a guideline with a hole in it | the three attributes |
| `MimeTypeException` | a media type that is not one | `MimeType` |
| `ReleaseVerificationException` | a `data/` value object built from data it cannot accept | 15 classes |
| `RequirementException` | a requirement declared with something it cannot check | 5 classes |
| `RouteException` | a `SitePath` given the wrong number of values | `SitePath` |
| `SecurityPolicyException` | a policy value that is not valid on the wire | 10 classes |

**`SiteException` is an interface because the inheritance chain is already spent.** Nine classes
declare it and the four under `MarkupException` and `ApiException` inherit it; of the nine, six are
a `LogicException`, one a `RuntimeException`, one a `TypeError` and one an
`InvalidArgumentException` — each saying something true — so the question *did this come from us*
has nowhere else to live. It matters more than it looks: `CollectionException extends TypeError`
extends **`Error`**, a sibling of `Exception` rather than a subclass, so `catch (Exception)` — the
widest net anybody reaches for by habit — misses one of the twelve concrete classes, silently, in the
class most likely to be thrown by a mistake made five minutes ago. Only `Throwable` catches all
twelve, and `Throwable` also catches everything PHP raises. This interface is the difference, and the
handler in `public/index.php` is what it is for.

**An exception becomes ours by extending the SPL class it already was, not by replacing it.**
`CollectionException extends TypeError`, `GuidelineException extends InvalidArgumentException`,
`ApiException extends RuntimeException`: every `instanceof`, every `catch` and every
`expectException` that matched the SPL class still matches, and the only thing ours adds is that the
throw says which layer raised it. Throwing an SPL class is how you avoid making a promise; extending
one is how you keep it.

**`MarkupException` is abstract**, because nothing throws it. It is what its three subclasses have in
common, and saying so in the language is what stops a fourth kind arriving as a bare
`MarkupException` — which would read as "one of those three" and be none of them. A `catch` or an
`@throws` naming it means any of the three.

**`ApiException` is the same arrangement and is deliberately *not* abstract**, which is the
difference worth reading. Both exist so one `catch` at a boundary covers a family without listing
it — `ApiGate` names `ApiException` and gets `UpdateException` with it — but this one is thrown:
`ApiCredential` and `ApiEnvelope` raise it about a request that is nobody's service in particular.

**Two throws that look misplaced are argued rather than moved.** `Terminal` throws
`ReleaseVerificationException` for its element-type guard — but that guard is the seventh of seven
identical `is_a($this->x->type, …)` checks, the other six of which are in `Model/`, and splitting one
off would put a single question in two classes. `PasswordHash` throws it for a digest that is not
bcrypt, which is a `data/` value object failing at load — exactly what the class is documented for.
In both cases the class *name* is the only thing that reads oddly, and a name is a cheaper thing to
live with than a check in two places.

The rule that keeps every `throw` naming one of these is in
[guidelines.md](guidelines.md#the-exception-rule).

---

## The markup tree

**Nothing builds HTML from a string.** A view returns a `Node`, a page is a tree of them, and the only
code on the site that writes a `<` is `Element` and `Doctype`. The verify script fails the build if a
heredoc or a `'<tag'` literal appears anywhere else under `src/`.

| Node | Is |
|---|---|
| `Element` | a `TagName`, a keyed collection of `Attribute`s, child nodes |
| `Text` | a run of text, escaped on the way out |
| `Fragment` | several nodes with no element around them |
| `Document` | a `Doctype` and the `<html>` under it |
| `MarkupParser` | the reader — hand-authored markup, back into the four above |

Four mistakes cannot happen, three of which would otherwise be silent: a misspelled tag renders as an
inert inline box, a misspelled attribute is a null the client reads as nothing, an unescaped value is
an injection, and a mismatched closing tag is a document the browser reinterprets. The last one a
tree removes outright — there is no closing tag to get wrong, because there is no text form to write.

### Building one

```php
new Element(HtmlTag::A)
    ->attr(HtmlAttribute::ClassName, CssClass::BtnPrimary)
    ->attr(HtmlAttribute::Href, '/releases')
    ->containing('releases →');
```

`attr()` is the whole attribute API, and what you pass decides what renders:

| Value | Renders |
|---|---|
| `'visual'`, `5` | `player-style="visual"`, `height="5"` |
| any backed enum | its value — `class="hero"` |
| an `AttributeValue` | its `render()` — `content="width=device-width, initial-scale=1.0"` |
| `''` | `options=""` — a real empty value |
| `true` | `narrow` — a bare boolean attribute |
| `false`, `null` | nothing at all |

`''` and `null` are deliberately different. A public SoundCloud track has no secret token, and
`secret-token=""` is not the same thing to the client as no attribute.

**An attribute is an `Attribute`**, held in a `SearchableCollection` keyed by its name. The name is
kept beside the value even though the map is keyed by it, because `render()` has to ask the name
whether it is a URL and a key is a string; the key is what keeps last-write-wins and declaration
order.

`containing()` takes nodes; a bare string becomes escaped `Text`. That is the safe reading of the
ambiguous case — markup passed as a string shows up as visible `&lt;b&gt;`, which is wrong on the
page but *visibly* wrong.

### Typed attribute values

**The attribute's *value* is typed too, wherever it is a fixed vocabulary rather than data.**
`attr()` accepts any `BackedEnum` and unwraps it, so `rel`, `target` and `type` are `LinkRel`,
`LinkTarget` and `ScriptType` cases rather than strings — the same move `RequestedWith` and
`ContentTypeOptions` make beside the headers they fill. It earns its place on the same grounds the
names did: misspell `modulepreload` and every preload hint stops preloading in silence, misspell
`noopener` and a security boundary on every outbound link is quietly not there, and drop `module`
from the script tag and `import` becomes a syntax error. `rel` is a token list, so `LinkRel::tokens(…)`
builds it variadically the way `Allow::readOnly()` builds the `Allow` header. `preload` is
`MediaPreload` and `<meta name>` is `MetaName` on the same grounds — the second is the whole
vocabulary of an attribute used nowhere else on the site, and it fails the way the rest of this list
does, which is not at all: a `<meta>` whose name nothing recognises is laid out as nothing and moved
past, so a misspelled `viewport` renders every phone at 980px with the media queries answering for a
screen nobody is holding. `lang` is `Language` on the same grounds and fails the same way — a language
tag nothing recognises is not an error, it is a screen reader picking the wrong voice and a
hyphenation dictionary picking the wrong words. These are server-only, so they have no TypeScript
mirror and none is wanted.

**A value with a *grammar* is a class, not a case**, and `attr()` takes one through an
`AttributeValue` interface — the same shape `HeaderValue` has on the HTTP side, for the same reason:
an `->attr(…)` call site is the one place a grammar cannot be checked. `ViewportContent` is the one
implementation: `width=device-width, initial-scale=1.0` is a descriptor list of name-value pairs. Its
width is a `ViewportWidth` case and its scale is a `float`, so neither half can be misspelled. Note the
two rules the class exists to keep: the scale renders `1.0` rather than PHP's `(string)` of it, which
is `1` — the same instinct that keeps `Charset` carrying two spellings of one encoding — and it is
formatted with `%F` rather than `%f`, because `%f` under a German locale writes a decimal comma and a
comma is this grammar's own separator.

**Unwrapping happens in `attr()`, and both guarantees stay in `render()`.** That is not a
contradiction of the rule in the next section: unwrapping is *normalisation* — shorthand for the
string a call site would otherwise have typed — where escaping and the scheme check are guarantees,
which have to hold for an element built any way at all. `Attribute` holds a `?string`, so what a
hostile `AttributeValue` returned is escaped exactly like anything else. `HtmlTest` builds one to
prove it.

### The two guarantees, and where they live

Both are enforced in **`render()`, not in the builders**, because `render()` is the only code that
turns a node into markup — so the guarantee holds for any element however it was assembled,
including one built by handing the constructor its attributes directly, which `attr()` is otherwise
the only thing standing in front of.

- **Escaping.** `Element` escapes an attribute value by rendering it as a `Text`, so
  `htmlspecialchars` is called in exactly one place on the whole site, with `ENT_QUOTES |
  ENT_SUBSTITUTE | ENT_HTML401` written out rather than inherited from the runtime. `HtmlTest` pins
  that call site the same way it pins `containingHtml()`'s.
- **Scheme.** An attribute the browser dereferences is asked what scheme it names, because escaping
  is the wrong tool for a URL — `javascript:alert(1)` contains not one character `htmlspecialchars`
  touches. `AttributeName::isUrl()` says which attributes those are, case by case and not enum by
  enum, since `href` and `class` live in the same one.

  The allowlist is site-relative, `https:` and `mailto:` — `UrlScheme` cases, since a scheme is a
  fact about a URL and not about markup, which is why the enum sits in `Support/` beside `Charset`
  and why the footer and the imprint build their `mailto:` through it. The *list* stays its own
  constant rather than collapsing to `UrlScheme::cases()`: the enum is the vocabulary a URL may be
  written in, the constant is what is switched on — the distinction `CspScheme::Data` makes too.

  **A leading slash is not the same claim as "somewhere on this site"**, so it is asked rather than
  assumed. `Element::staysOnThisOrigin()` resolves the value with the WHATWG parser PHP 8.5 ships —
  against a reserved `.invalid` base, the way a browser would — and asks whether it landed where it
  started. Never test for "starts with a slash", and never list the prefixes an authority can open
  with: the parser strips tab, CR and LF *before* parsing, so `/\r\n/host` is `//host` is
  `https://host`. `Navigation.ts` makes the same check on the client. `HtmlTest` pins every
  spelling, the two whitespace ones included, along with the marked attribute set in both directions.

### Pretty-printing is not cosmetic

An element whose children are all elements puts each on its own line; one with any `Text` among them
stays on one line. Whitespace between inline content is content — without that rule
`<h1>ill<span>.</span></h1>` would gain a space inside the title.

### Hand-authored markup: `MarkupParser` and `containingHtml()`

`data/privacy.de.html` and `data/privacy.en.html` are a hand-authored document rather than markup a
view assembles. `MarkupParser` reads them into the tree, and `Element::containingHtml()` is the door:

```php
new Element(HtmlTag::Section)
    ->attr(HtmlAttribute::Lang, $language)
    ->containingHtml($html);
```

It is the safe twin of `containing()`, which is the pair worth reading together: `containing('<b>x</b>')`
puts visible `&lt;b&gt;` on the page because a string is content, and `containingHtml('<b>x</b>')`
parses the same argument into a real `<b>` — after checking that `b` is an element this site emits and
that everything on it is an attribute this site emits. Because it builds through `attr()` and
`containing()` rather than around them, escaping and the scheme check apply to a parsed document
exactly as they do to one a view assembled; the parser itself never looks at a URL and does not need
to.

**The refusals are the point, so they are exhaustive rather than illustrative** — the same stance
`TarArchive` takes about a member name off the network. An unknown element, an unknown attribute
(which is what refuses an `onerror=`), a comment, a CDATA section, an element from another namespace,
content the parser hoists into `<head>`, a `<script>` — whose content is raw text that `Text` would
escape into meaning something else — and **any HTML5 parse error at all**, each a `ParserException`.
The last is the one that matters most: `Dom\HTMLDocument` reports a stray `</div>` as a warning and
then recovers silently, which for a hand-edited legal document would mean the rest of the policy
disappearing with nothing anywhere saying so. Both halves parse with zero errors, which is what makes
refusing on any of them affordable.

Four details are worth knowing before touching it:

- **It needs `ext/dom`**, one of the extensions asked for by name — bundled with PHP is not the same
  as built into a host's PHP — and the failure is a fatal on `/privacy` alone, the one page here that
  is a legal obligation rather than a choice.
- **The doctype it prepends is `Doctype::Html5`, not a literal**, which is what puts the parser in
  no-quirks mode; without it *every* fragment reports `unexpected-token-in-initial-mode` and the
  error trap is noise. Reusing the class that owns that string also means `MarkupParser` holds no `<`
  literal at all, so `Element` and `Doctype` stay the only two files that write one.
- **A name is resolved with `tryFrom()`**, where the honest question is which case has this
  `tagName()`. The two are one question only because every case of every vocabulary enum spells its
  name as its backing value, which `HtmlTest` pins — along with the parser's two registries of enums,
  in both directions against reflection. An enum missing from a registry does not break the parser,
  it makes every one of that enum's names unparseable, which reads as the *markup* being wrong.
- **The parsed nodes become children of the wrapping element rather than a `Fragment`**, and that is
  what keeps a document coming back out as it went in. A parse keeps the source's own whitespace as
  `Text`, and a `Text` among the children is what puts `renderChildren()` on its single-line branch —
  where nothing is re-indented and, more to the point, no newline is invented between inline content.
  The rendered text is identical to the source; the *bytes* are not, because a character reference
  comes back as the character it names.

**What it costs**, per half, with no Xdebug loaded: 0.071 ms to parse, 0.242 ms to walk into the
tree, 0.262 ms to render it back out — so `/privacy` pays about **+1.14 ms** for both halves. That is
the largest single price this site pays for a guarantee, and it is affordable only because it is one
route out of ten and the least-visited page on the site. Under Xdebug the walk is three times that
and the parse is unchanged, which is why [performance.md](https://github.com/neurosysgg/neurosys-webspace/blob/master/docs/performance.md) records +3.4 ms for the same
work: it measures in the environment that has it loaded.

`HtmlTest` pins the call sites, so a second one has to be argued for in a test named for the fact.
**Never parse anything a request can influence** — not because it would be an injection, which is
what the refusals are for, but because the vocabulary is this site's own, so a visitor would
otherwise get to choose which of our elements to build.

---
