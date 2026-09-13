# Security — the framework

What the framework does about the attack surface every site built on it shares: the headers,
the method gate, the parsing, the markup tree's output safety, and the signed API. A site's own
gates, cookies, data and hosting are its own, and belong in its own documents.

## 1. Transport — HTTPS and HSTS

A site's webroot redirects `http://` to `https://` before any PHP runs — that is a line of the
site's server configuration, not of the framework — and `Strict-Transport-Security` (one year,
`includeSubDomains`, by default) tells the browser never to try plaintext again. Both halves are
load-bearing and neither is optional: `Auth`'s two gates are HTTP Basic, Basic is base64 rather than
encryption, and the pre-launch gate runs on **every request that reaches PHP**. A request that
arrives in plaintext has already put its credentials on the wire — the redirect fixes the *next*
request, and HSTS removes there being a next plaintext one at all.

Read "every request that reaches PHP" literally. A webroot that passes real files through before its
rewrite to `index.php` serves **static assets without either gate**, so while the pre-launch gate is
up it covers the documents and not `/assets/**`. It is written down because "the gate runs on every
request" is the kind of sentence that gets relied on later. `SecurityHeaders` records the same fact
for its own half: static assets never reach PHP, so they get no security headers either.

**The prod build deletes every source map**, and `build-prod.mjs` refuses to finish if a `.map`
survives or a shipped module still names one, so there is nothing on a live host for that gap to
expose. The debug tree still has them, and a `tsconfig` that sets `inlineSources` puts the whole
commented TypeScript in each: on a dev server bound to anything but localhost they are readable, and
the fix there is dropping `inlineSources` rather than relying on a strip that only happens at the
edge.

Two subtleties live here. A host that terminates TLS at a proxy can report `%{HTTPS}` as `off` on a
request that was encrypted the whole way, and `X-Forwarded-Proto` is then the header telling the
truth — so a redirect behind such a proxy asks both and fires only when both say plaintext, where
asking `%{HTTPS}` alone is an infinite loop. And `preload` is deliberately **not** offered — it is a
one-way commitment for the whole apex domain that is hard to walk back; the class
`StrictTransportSecurity` documents why, and ships a `ONE_DAY` value for ramping an estate you have
not yet checked.

## 2. Response headers — typed, and sent before anything can fail

`SecurityHeaders::send()` is the first statement of `App::run()` after the error log, so the policy
covers **every** response, including the `401` the auth gate exits with, the `303` a redirect sends,
and the `405` the method gate refuses with. Every header is a typed object on both halves — a
`HeaderName` and a `HeaderValue`, so neither the name nor the grammar of the value is assembled as a
string at a call site. On the value side that is `CspDirective`, `CspKeyword`/`CspScheme`/`CspHost`
behind a `CspSource` interface, `ReferrerPolicy`, `PermissionsPolicyFeature`,
`StrictTransportSecurity`, `MimeType` — so a misspelled directive or an unquoted `'self'` is a parse
error at build time, not a header the browser silently drops.

The headers, as sent for an app that widens nothing — the framework's `TestApp`:

```
Strict-Transport-Security: max-age=31536000; includeSubDomains
Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self';
    frame-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'none'; object-src 'none'
Referrer-Policy: strict-origin-when-cross-origin
X-Content-Type-Options: nosniff
Permissions-Policy: geolocation=(), camera=(), microphone=(), payment=(), usb=(), midi=()
```

Those five are `SecurityHeader`'s whole set, and `SecurityHeadersTest` asserts both what an app that
widens nothing is sent and what one that widens all three is. Three are the app's to widen, and
each is at its strictest unless the app says otherwise: `App::contentHosts()` names third-party
origins under every fetch directive but `default-src` and `object-src` — `connect-src`, `media-src`
and `font-src` are written only when it names one, and `frame-src` is `'none'` until it does — and
`App::strictTransportSecurity()` and `App::permissionsPolicy()` answer for the other two. An app
that embeds a file host's images names it under `img-src`, as `https://images.example.test`, and
nothing else changes. A `Permissions-Policy` case is a feature browsers still recognise; one they do
not is a console error on every page, not a stricter policy.

A document carries three more, which are about caching rather than security and so live in
`ResponseHeader`:

```
Cache-Control: no-cache
ETag: "…"
Vary: X-Requested-With, Accept-Language, Cookie
```

`no-cache` is not `no-store` — it means keep the copy and revalidate before reusing it. A page
behind a gate says `no-store, private` instead, and a response that supplies its own
`Cache-Control` that way is also one `ViewResponse` gives no validator at all. Only a 2xx carries an
`ETag` or is answered with a `304`; a 404 still says `no-cache`, and every response `ViewResponse`
sends carries the `Vary` — plus whatever the view declares in `View::varyOn()` — because it says
what the body depends on rather than how long it may be kept. `If-None-Match` is read as a list and
compared weakly, and a `-gzip`, `-br` or `-deflate` that a compressing module appended inside the
quotes is dropped — compared verbatim, no compressed page was ever a 304. See
`ViewResponse::cacheHeaders()` and `ETag::matches()`.

The CSP's **absences** are the interesting part, because each is a scheme source someone debugging a
broken asset would paste straight back in:

- **No `'unsafe-inline'` on `script-src` or `style-src`**, and no hook to add it. A site keeps the
  policy by emitting no inline style and no event handler; an element that must style itself does
  it through the CSSOM — `element.style`, which CSP does not govern — rather than a `style`
  attribute, so there is nothing for the allowance to cover.
- **No `data:` on `img-src`.** A `data:` image is cheap; a `data:text/html` document runs script in
  the navigating origin, and an allowlist that has said `data:` once is easy to widen by accident.
  `contentHosts()` answers `CspSource`s, so a site could name `CspScheme::Data` — the absence is the
  default, and a site that wants it back is choosing it.
- **No `report-uri`/`report-to`.** A report is a `POST`, which the `405` gate refuses; a third-party
  collector is a third-party origin receiving a request from every visitor before any consent; and a
  report's `document-uri`/`blocked-uri` is data a privacy policy would have to claim first. The
  policy is asserted at **build time** — `SecurityHeadersTest` pins the directive set — rather than
  observed at run time, which is the job a `report-uri` would otherwise do. `report-to` would also
  want a sixth header, `Reporting-Endpoints`, naming an endpoint that does not exist.
- `object-src 'none'`, `frame-ancestors 'none'`, `base-uri 'self'`, and `form-action 'self'` round it
  out. `form-action` on a site with no form is belt over braces, and stays because the day a form
  appears is not the day anyone will remember to add it.

`SecurityHeaders::send()` also **removes** a header. PHP appends `X-Powered-By` with its exact patch
version before any of this code runs, so `expose_php` in php.ini (not the site's to set, on shared
hosting) is only half the switch; `header_remove()` is the half the framework has. It is invisible
under CLI and the built-in dev server — `header()`/`header_remove()` are no-ops there — and only
observable under a real SAPI, where the header is confirmed gone.

## 3 + 4. The method gate

`Router::dispatch()` matches the path first and then asks that route whether it answers the method,
refusing with a `405` before any controller is built. The `Allow` header is `Allow::readOnly()`,
derived by filtering `HttpMethod::cases()` on `isReadOnly()`, so it cannot advertise a verb the gate
does not honour — a hand-written `Allow: GET, HEAD` could drift; this cannot. An unrecognised method
(`REQUEST_METHOD` is whatever the client sent) parses to `null` rather than a guessed `GET`, and
`null` is not read-only — so a `PROPFIND` or a typo is refused, not silently treated as a read.

**`TRACE` is the web server's before it is the framework's.** Apache answers it itself while
`TraceEnable` is on (its default) and refuses it while it is off, and in neither case does PHP see
the request; `TraceEnable` is a server-level directive and invalid in `.htaccess`, so a site on
shared hosting that finds its host echoing `TRACE` has one lever, a `RewriteRule` refusing the
method. Should one reach the router, `HttpMethod::Trace` is a case and not read-only, so it is a
`405` like any other write.

**The method question lives on the route, as a `MethodPolicy` rather than a set of methods.** That
is not a stylistic choice. A route carrying its own set would make the `405` name it, so
`PUT /api/update/v1/patch` would answer `Allow: GET, HEAD, POST` — and that `POST` is precisely the
fact the endpoint exists to hide; an unrecognised verb, being in no set, would make the refusal name
the whole set. So there are two policies, not one set per route: every route a site registers is
`ReadOnly` unless it says otherwise, and the framework's own `ApiPath::Api` is `Delegated`, which
means the router forms **no opinion at all** and the controller answers every method itself. The
only `Allow` the router ever sends is `GET, HEAD`.

**That `null` has a second job at the gate**, and it is the sharper half. `ApiGate::accepts()`
refuses an unrecognised method on its first line, before anything else — because the envelope binds
a method, and comparing `null->value` against it would be an uncaught `TypeError`: a `500` where an
absent address sends a `405`. One differing status code and the whole property is gone, to anybody
who types `BREW` — which is why an end-to-end sweep of every real verb belongs beside it, `BREW`
included, at every depth.

## 2 (again). Parsing the request defensively

`Request::path()` is the one place a malformed request target is dealt with. It uses
`Uri\Rfc3986\Uri::parse()`, which returns **null** on a target it cannot read, so `??` is a real
guard. A genuinely unparseable target falls back to **its own path** — everything up to the first
`?` or `#`, which is what the parser would have answered had it succeeded — rather than to the home
page, which would be the quieter wrong. A target opening with `//` never reaches the parser at all:
RFC 3986 reads one as an authority, which would turn `//x/posts` into the page at `/posts`, and a
request target is a path.

That fallback **still matches placeholder routes**: `Route::matches()` compiles `{slug}` into
`([^/]+)`, which matches anything, so a malformed target reaches a controller like any other. What
keeps a hostile capture out of a response header is therefore not the router but whatever puts it
there: a gate a site builds on `Auth` that names its realm after a capture encodes the capture
first, and `BasicChallenge` refuses anything but RFC 9110's `qdtext` — so a realm built wrong is a
loud exception, and `header()` refuses CR and LF besides.

Do not reintroduce `parse_url()`: it signals failure with `false` rather than null, which `??` does
not guard, and `GET ///` then reaches `rtrim()` as a `TypeError` — a `500` ahead of the router and
the method gate.

The path is matched **raw, not decoded**: `%2f` stays `%2f` and `%2e%2e` stays `%2e%2e` while the
pattern is matched, so an encoded slash cannot split a segment into an extra route parameter, and
encoded dot-segments cannot walk anywhere. Each capture is decoded only after the match, so it is a
value inside one segment — a key a controller looks something up by, never a path it builds.
Directory traversal toward the credentials is in any case structurally impossible — `data/` sits
beside the webroot, not inside it (`App::data()`), and Apache itself refuses `..` in a request path
with a `400` before the app is even reached.

## 4 (again). Routing

A route pattern compiles to a regex anchored with `\A` and `\z`, not `$`: `$` also matches
immediately before a trailing newline, so `\z` is what actually means "the end of the string".
Every static part is quoted, so a `.` in `/feed.xml` matches itself. Placeholders capture `[^/]+`,
matching is case-sensitive, and there is no dot-segment normalisation that could resolve a
decorated path onto a gated route.

## 5. The response — output safety in the markup tree

**Nothing builds HTML from a string.** A view returns a `Node`; a page is a tree of them; the only
code that writes a `<` is `Element` and `Doctype`, and a site can hold its own code to that with a
check that fails on a heredoc or a `'<tag'` literal anywhere else. Two guarantees are enforced in
`Element::render()` — the *only* code that turns a node into markup — so they hold for any element
however it was built, including one assembled from an array:

- **Escaping happens in exactly one place.** An attribute value is escaped by rendering it as a
  `Text` node, so `htmlspecialchars` is called once in the framework, in `Text::render()`, with one
  stated set of flags.
- **A URL attribute is asked what scheme it names**, because escaping is the wrong tool for a URL and
  always was — `javascript:alert(1)` contains nothing `htmlspecialchars` touches. The allowlist is
  `Element::URL_SCHEMES` — `https:` and `mailto:` — site-relative, and a `#fragment` of the page,
  which is resolved the same way and cannot leave it. `http:` is absent because HSTS
  means a site on the framework does not emit one; `data:` is absent because a `data:text/html`
  document runs script in the origin that navigated to it. A leading slash is **resolved**, not
  assumed to be local: PHP 8.5's WHATWG URL parser strips tab, CR and LF from a URL before parsing,
  so `//host`, `/\host` and `/\t\n/host` are all `https://host` to a browser — the value is resolved
  the way a browser would and accepted only if it lands back on the origin it started from, which is
  `Element::staysOnThisOrigin()`. ([history](history/markup.md#attributes))

**Hand-authored markup goes through the same rules.** `MarkupParser` reads a hand-authored document
— a privacy policy kept in `data/`, say — into the tree, so it is subject to every rule above rather
than exempt from them: its element and attribute names have to be ones the app's `Vocabulary`
allows, its text is escaped by `Text`, and its `href`s go through the same scheme allowlist. That is
what refuses an `onerror=`, and it is checked when the file loads rather than trusted.
`Element::containingHtml()` is the only way in, and it is never handed anything a request can
influence. There is no node that emits markup verbatim.
([history](history/markup.md#hand-authored-markup))

Two things follow from this that are worth stating plainly:

- **Request data never needs to reach a URL attribute.** A page renders trusted data; where a site
  does reflect request input — a 404 echoing the path back, say — it belongs in a *text* node or a
  text attribute, escaped by the rule above, and a client-side element that reads it back sinks it
  via `textContent`, never `innerHTML`. So neither the HTML context nor the DOM context can break out.
- The **client** enforces the same origin rule as the server. `Navigation` intercepts internal link
  clicks by matching the `href` *attribute* but then uses the *resolved* `href`, reconciling the two
  so a protocol-relative or cross-origin URL is handed back to the browser rather than fetched and
  written into `#content`. Nothing the server emits is protocol-relative — `Element` refuses to write
  one — so both halves are the same check from opposite sides.

## Input validation at the boundary it is written

Values that come from an app's own data and config are validated at **construction**, so a bad
paste throws when the data file loads — where the mistake actually is — rather than breaking a
header or rendering a dead link when a visitor arrives. The framework's own:

| Type | Invariant |
|---|---|
| `CspHost` | a bare origin — scheme + host (+ optional port), no path or trailing slash |
| `MimeType` | a well-formed subtype token |
| `Location` | an absolute `https://` URL, or a path `Element::staysOnThisOrigin()` keeps on this origin — the one address the framework emits in a header |

All of them anchor with `\z`, not `$`, because `$` also matches before a trailing newline — the same
rule the router's patterns follow. A value type a site adds for its own data — a share id, a
profile URL — follows the same rule, and its bad-input cases include a trailing newline.

## The API

`/api/{service}/{version}/{action}` is the one address family that writes. It exists because a
deployment reachable only through a file-transfer mount pays for every `stat`, and walking a tree
file by file to find what changed costs seconds where one request carrying the whole tree costs
one round trip. A push's dry run reports the payload's size, which grows with the codebase.

**One `ApiPath` case matches the whole family**, and `App::routeTable()` appends it to every site's
routes, last, so a site can neither shadow it nor forget it. A new service is an `ApiService` case
and its handlers, with no route to register, and it inherits the silence, the method policy, the
key, the serial rule and the indistinguishability without a line arranging any of them. The signed
commands resolve an address through the same `ApiService` and `ApiAction`, so a new service needs
no change there either. There are no aliases: an endpoint whose design is to be unfindable does not
want two doors.

`health` and `capability` are the second and third services, and each cost exactly that: an
`ApiService` case, an action enum and its handlers.

- `health` checks the site's declared requirements and answers `503` when a required one is unmet.
- `capability` lists what the host has: every extension, every directive, the SAPI, the clock, the
  `data/` files, the error log's tail.

In anyone else's hands, either answer is reconnaissance. That is exactly why they are services
behind this signature rather than the public `/health` a monitor would ping. See
[health.md](health.md).

### It answers as though it is not there

An unsigned request gets **exactly** what the site gives for an address that does not exist: the
app's `404` for a read method, the `text/plain` `405` with `Allow: GET, HEAD` for anything else,
and the same for a verb the framework does not recognise. Not a `401`, which would prompt; not a
`403`, which would confirm; not a `405` naming `POST`, which would confirm more precisely.

That is a property of the structure rather than of two implementations kept in step: both responses
come from `UnroutedController`, the very object `Router` delegates to when no route matches at all.
`ApiController` hands it anything it will not verify. The claim is about status codes, headers and
bodies, so it is worth checking over real HTTP, per method **and per depth**, against an address
like `/no-such-page` — only a real server has those.

**`public/api/` must never exist.** A webroot that passes real files and directories straight
through (`RewriteCond !-f` / `!-d`) would have a directory there answered by Apache — a listing or a
`403` — and `/api` would stop looking like a typo without a line of PHP being involved. A push is no
guard against it: `public/` is a root, so a signed member named `public/api/...` would be written.
The rule is about what a site's repository holds, not about what a signed caller can do.

**That scope is deliberate: status, headers and bodies, and not timing.** A request carrying an
`NS1` credential reaches `ApiGate`, which reads the key and — once the frame parses — runs
`openssl_verify`; a path that matches no route never does either, because it never leaves
`UnroutedController`. Measured on localhost, the gap between the `/api` shape and a typo of the
same length, both carrying a well-formed-but-bogus `NS1` header, is about **180 µs**. It is not a
usable oracle, and the reason is its precondition rather than its size: the gap appears only for a
caller already sending an `NS1`-framed `Authorization`, and knowing that scheme exists — the source
is public — already implies knowing `/api` does. It is well below WAN jitter, and it vanishes
entirely on a deployment holding no key, which is the one place the silence has to be perfect. It is
written down because the "same `null` reaching the same line" phrasing below reads as a timing
identity it does not claim; closing the axis for real would mean a constant-time dummy verify on
every unrouted path, which protects nothing a reader of the source could not already know.

**The gate verifies before it resolves**, which is what keeps that structural. Asking "does this
service exist" first would answer an unsigned caller through a different path depending on what they
guessed, and two paths that agree today are two paths free to stop agreeing. Verified first, a
service that does not exist and a signature that does not verify are the same `null` reaching the
same line. Past the gate the posture inverts: an unknown action is a real `404` with a sentence, a
verb that is not the action's is a real `405` naming the one that is, and only the key holder ever
sees either.

### The credential is a key the server cannot use

`data/update.pub` (`CredentialFile::UpdateKey`) holds an **ECDSA P-256 public key**. The private
half lives on the machine that signs, outside the repository entirely: no `.gitignore` entry and no
deploy flag is what stands between it and a webroot, because it was never in reach of either.

The server therefore holds nothing replayable. A full compromise of the account yields the public
half and no ability to push anything. That asymmetry is the reason this gate is a signature rather
than another bcrypt digest — the Basic gates protect pages, and this one protects the code that
serves them.

**P-256 rather than Ed25519, by measurement rather than taste.** `ext/sodium` is absent on the
development machine, and Ed25519 does not work through PHP's openssl binding at all — it fails with
`Provider routines::invalid digest`, because the binding drives the digest-based API and Ed25519 is
one-shot. P-256 was verified end to end on a live shared host before it was relied on, and
`PublicKey` accepts that curve and no other: an EC key on P-384 or secp112r1 parses and verifies a
SHA-256 signature just as happily, which would widen the algorithm without anybody having decided to.

**Its absence is the off switch, with the opposite polarity to `data/site_auth.php`.** No key file,
no endpoint, for everyone, forever — and a deployment holding no key does no verification work at
all. So a fresh clone and every machine that has not deliberately been given a key are closed rather
than open — worth reading twice, because the two files look alike and mean opposite things.

`PublicKey` is the only `openssl_*` call site under `src/`. It asks `=== 1`, because
`openssl_verify()` returns `1`, `0` **or `-1`**, and a call site written `if (openssl_verify(...))`
would read the error case as a pass. Nothing under `src/` names a signing or key-minting call at
all, so a private key arriving on the server would have nothing to use it — both are worth a check
that fails the build.

### What a signature covers, and why replay is closed

The credential rides in `Authorization` as `NS1 <base64>`: a length-prefixed manifest and the
signature over it. The signature covers the manifest; the manifest covers the body by SHA-256. One
signature over a couple of hundred bytes therefore protects a payload of any size — **and a payload
of no size at all**, which is why it rides in a header: a `GET` has nothing to frame a credential
into, and every action after the first one is a read.

`ApiCredential::parse()` bounds every length in the frame against what is actually present before
using it as an offset, and each failure is an exception the gate turns into the same silence as any
other — a malformed credential is never a `500`. One over the server's header size limit is refused
by Apache with a `400` before PHP sees it, which is a refusal from the wrong layer but not one that
says `/api` is there. The envelope is not even parsed until the signature has verified.

**The manifest binds the request, not just the payload.** It carries `method` and `path` beside the
digest, and both are checked against the request carrying them. Without them a credential would
authenticate *some* request rather than one: a credential minted for a read would replay as a
write, and one minted for one action would verify at another.

Three details of that binding are worth stating, because each is the kind of thing that is
discovered rather than read:

- The digest is checked for **every** method, never skipped for one "with no body". A read signs
  `sha256('')` and a size of zero, so no branch is needed — and a signed `GET` cannot smuggle a body
  past it for some later action to read unsigned.
- What `path` binds is `Request::path()`'s output, not the wire target: normalised, so a trailing
  slash is the same signed path. It is compared against that string directly and never against one
  rebuilt from the router's captures: `Route::matches()` decodes each capture and `Path::to()`
  encodes it again, which round-trips the value but not the spelling — `%7E` comes out as `~` and
  goes back in as `~` — so a rebuilt path is not always the path that was signed.
- The **query string is not covered**, because nothing under `src/` reads one — no code touches
  `$_GET` or `QUERY_STRING`. An API action must therefore never read a query parameter: it would be
  the one input reaching a verified caller's handler unsigned. A parameter belongs in the manifest
  or in the body. `health` and `capability` have an obvious temptation here, a `?verbose` or an
  `?area=`, and take none. One area is asked for by an address of its own (`health v1 settings`),
  and each action reports everything it reports, always. The note saying so is on `HealthCheck`
  and `ApiEnvelope` as well as here.

**Cross-deployment replay is closed by key separation rather than by an audience field.** A key is
per deployment and uploaded by hand, so no two deployments hold the same one and a credential minted
for one verifies nowhere else. The tools hold the signing side to it: `ApiTarget` gives every origin
but the default one a key of its own, and refuses the default key for any other origin — by path or
by content, so a copy under another name is refused as well. An `aud` field would have to be checked
against something the server knows independently of the request, and `Host` is whatever the caller
sent — so it would bind nothing. If two deployments ever share a key, that is the field to add.

The manifest also carries a `serial` doing double duty: it must be within **±300 s** of the server's
clock *and* strictly greater than the highest serial already accepted, which `App::updateSerial()`
records in `.update-serial` beside the webroot — in neither mirrored tree, and not in `data/`, which
a site deploys from whichever machine deployed last and so could move the number backwards. The
monotonic half alone would accept a credential signed long ago and never sent; the skew half alone
would leave a five-minute replay window. A **dry run deliberately does not advance the serial**, and
neither does a **read**, so a captured one replays to nothing and a real push of the same payload is
still possible. A read not spending one is also what lets two calls be made in the same second,
since a serial is `time()` and the rule is strictly greater.

The accepted cost of that is narrow and stated rather than left implicit: a captured **read**
credential is replayable for the remainder of the skew window. What it yields is the answer to a
read — `update v1 version`'s deployed serial, entry URL (and so build stamp) and PHP version, or a
`health` or `capability` report — to somebody who has already broken TLS. Any write landing in the
meantime kills it, since the monotonic half moves.

**A write spends its serial before the action runs, not after.** So a failure to record it is a
refusal with nothing written, rather than a deployment that has been updated by a credential that
could update it again. It also means a push that fails partway has still spent its serial, which is
correct: the bytes that produced it must never be accepted twice, and a corrected payload is
different bytes with a fresh `time()` on them anyway.

**A write holds a lock from spending to the end of the action.** `ApiGate::spend()` takes an
exclusive, non-blocking `flock()` on `.update-serial.lock` beside the serial, asks freshness again
under it, and records the larger of the two serials — so two overlapping writes can neither run at
once (each would mirror over the other) nor move the record backwards (which would reopen the newer
credential to replay). A write that finds the lock held, or finds a newer serial recorded since it
was verified, is a **`409`** that spends nothing and touches nothing; one whose serial cannot be
recorded is a `500`, with nothing written. The caller is already verified by then, so the sentence
is allowed. Reads never lock. A lock file is never deleted, since deleting it would let two
processes hold locks on two inodes under one name.

**Why a header is acceptable here.** `Authorization` is a `ServerVariable`, not a `RequestHeader`,
so it has no TypeScript mirror and puts nothing in the browser's bundle. The live risk is that a
header is the part of a request most likely to be rewritten in transit: Apache hands PHP no
`Authorization` unless the site's `.htaccess` puts it back with `E=HTTP_AUTHORIZATION`, and a
variable set before an internal redirect arrives renamed, so `Request` reads both
`HTTP_AUTHORIZATION` and `REDIRECT_HTTP_AUTHORIZATION`. It is the one thing here that should be
re-checked on the live host rather than reasoned about, because a proxy that strips it fails
**closed and in silence**, looking exactly like a bad key.

The size is arithmetic against a real limit rather than a guess. `LimitRequestFieldSize` is 8190
bytes for the whole field line; `Authorization: NS1 ` is 19 of them; base64 is 4 out for every 3 in;
the frame carries a 4-byte length and at most 256 bytes of signature. A 2048-byte manifest cap
therefore costs at most **3099 bytes**, leaving 62% of the line spare. Measured against a real P-256
pair, a push's header is **367 bytes** and a read's **331**, and the DER signature is **71**.

### What a verified payload may write

Four roots, and `data` is conspicuously not among them: `public/`, `src/`, `phpanta/` and the single
file `autoload.php` — `UpdateRoot`'s cases. That one rule is what keeps the credentials under
`data/` and everything else a site keeps there out of reach of any push, however well signed — there
is no destination to compute for them rather than a destination computed and then rejected.

Every member of the archive is checked **before anything is written**, so a payload with one bad
name writes nothing at all:

- the tar reader is hand-rolled rather than `PharData`, because `PharData::extractTo()` decides for
  itself what a member name means and what a link points at, and those are exactly the decisions
  that must not be delegated when the names came off the network;
- only a **regular file or a directory** survives. A symlink, a hardlink, a device node, a fifo, a
  GNU long-name record and a pax header are each refused **by name** — not skipped, refused, since an
  archive containing one is not an archive the packer produced;
- a name must match `[A-Za-z0-9._-]` segments separated by `/`, with no leading slash, no backslash,
  no empty segment and no `.` or `..`, and be at most 255 bytes. There is **no `..` handling and no
  `realpath()` fallback**: a name that would need either is refused outright, which is why nothing
  downstream carries a traversal guard;
- the ustar header checksum is verified, because a signature says the bytes are ours and the
  checksum says they are a tar — and in a format that is nothing but offsets, bad framing means every
  name after it is read out of the middle of somebody's file;
- the archive must end in its zero block, with no partial block after it, so a truncated archive is
  refused rather than read as a shorter one;
- a name that is a file and also the directory of another member (`a` and `a/b`) is refused, since
  one of the two writes would fail after the other had landed;
- the gzip layer is decoded under `UpdateApplier::MAX_EXPANDED` — twice `ApiGate::MAX_BODY` — and
  the length is asked as well, because `gzdecode()`'s cap is only as fine as zlib's output buffer.

Each file then lands through `File::write()`, which writes beside the target and renames over it, so
every file appears atomically and within one filesystem. A file whose bytes are already there is
left alone, because rewriting a file the request is executing makes NFS silly-rename it into an
undeletable `.nfsXXXXXXXX`. The mirror that removes what a payload omits is an **enumerated
delete**: the tree is walked, diffed, and each surplus path is checked by the same rules an added
path passes before `File::delete()` is called on it, one named file at a time. `Directory::remove()`
is never used for it — that method deletes the files a directory holds, which is right for tearing
down a fixture and catastrophic here.

**The mirror sweeps only a root the payload carries a file under**, and **nothing after a write that
failed.** A root the push did not carry is left exactly as it is, never read as "delete all of it";
a failed write leaves the old tree's leftovers where they are rather than deleting around a version
that did not land. The report says which in a `note:` line, so a dry run shows it first. The walk is
`scandir()` rather than a glob, so a deployment path holding `[` or `*` lists its files.

**The walk never follows a symlink.** `UpdateApplier`'s walk and sweep ask `!is_link()` before
descending, so a stray link under a root is a leaf, and unlinking it removes the link and not what
it points at. No payload can carry one — `TarArchive` refuses a symlink member — so a link there is
the mark of a compromise that already holds the filesystem, and the one code path here that deletes
must not be a second way outside the roots. `UpdateTest` plants one and asserts the target survives.

### Where the roots resolve

`Deployment` maps a root to a directory; `UpdateRoot` is only the vocabulary. They are separate
because deciding whether a name is *under* `public/` must never require resolving where `public/`
**is** — the second question reaches `DOCUMENT_ROOT`, and the first is asked of names off the
network.

`App::webroot()` takes only the **basename** of `DOCUMENT_ROOT` and hangs it off `App::above()`, the
derivation every other path here uses, because a shared host can report `DOCUMENT_ROOT` through one
mount of an NFS export while `__DIR__` reads the same directory through another —
`/home/…/htdocs/webroot` against `/mnt/…/htdocs/webroot` — and a path built from the wrong one
compares equal to nothing. A basename grafted onto a different tree names a real directory somewhere
else, so it **refuses** rather than guesses, because every candidate guess is a directory something
would then be willing to delete:

- a `DOCUMENT_ROOT` that is unset or blank after trimming;
- one that is not absolute — for a bare relative name `dirname()` is `.`, whose realpath is the
  working directory, which under the test runner is the deployment itself;
- one whose last segment is `.` or `..`, which names the deployment or the directory above it;
- one that is not a directory inside the deployment, comparing `realpath()` on both sides;
- one that names no directory, even when its parent is the deployment — otherwise the first push
  would create a second webroot beside the real one and write the whole site into it.

And `UpdateApplier` takes its `Deployment` as a constructor argument, so a test hands it a fixture
rather than the live tree — and under `TestApp`, whose `above()` is `test/fixture/app/`, even the
default `Deployment::current()` resolves inside the fixture.
