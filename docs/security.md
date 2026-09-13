# Security — the framework

What the framework does about the attack surface every site built on it shares: the headers,
the method gate, the parsing, the markup tree's output safety, and the signed API. A site's own
gates, cookies and data are its own; neuro.SYS's are in [its security](https://github.com/neurosysgg/neurosys-webspace/blob/master/docs/security.md).

## 1. Transport — HTTPS and HSTS

`public/.htaccess` redirects `http://` to `https://` before any PHP runs, and
`Strict-Transport-Security` (one year, `includeSubDomains`) tells the browser never to try plaintext
again. Both halves are load-bearing and neither is optional: both auth gates are HTTP Basic, Basic
is base64 rather than encryption, and the pre-launch gate runs on **every request that reaches
PHP**. A request that arrives in plaintext has already put its credentials on the wire — the
redirect fixes the *next* request, and HSTS removes there being a next plaintext one at all.

Read "every request that reaches PHP" literally, because `.htaccess` passes real files through
before the rewrite to `index.php`: **static assets are served without either gate**. So while the
pre-launch gate is up it covers the documents and not `/assets/**`. It is written down because "the
gate runs on every request" is the kind of sentence that gets relied on later. `SecurityHeaders`
records the same fact for its own half: static assets never reach PHP, so they get no security
headers either.

**The prod build deletes every source map** and the verify script asserts none ships and no shipped
module names one, so there is nothing on the live host for that gap to expose. `public/` still has
them — `tsconfig` sets `inlineSources`, so each carries the whole commented TypeScript — and that is
what the dev server serves: on a dev server bound to anything but localhost they are readable, and
the fix there is dropping `inlineSources` rather than relying on a strip that only happens at the
edge. ([history](https://github.com/neurosysgg/neurosys-webspace/blob/master/docs/history/security.md))

Two subtleties live here. Strato terminates TLS at its proxy, where `%{HTTPS}` can read `off` on a
request that was encrypted the whole way; `X-Forwarded-Proto` is the header telling the truth, so the
redirect asks both and fires only when both say plaintext. And `preload` is deliberately **not**
offered — it is a one-way commitment for the whole apex domain that is hard to walk back; the class
`StrictTransportSecurity` documents why, and ships a `ONE_DAY` value for ramping an estate you have
not yet checked.

## 2. Response headers — typed, and sent before anything can fail

`SecurityHeaders::send()` is the first statement after the handler, so the policy covers **every**
response, including the `401` the auth gate exits with, the `303` a download redirects with, and the
`405` the method gate refuses with. Every header is a typed object on both halves — a `HeaderName`
and a `HeaderValue`, so neither the name nor the grammar of the value is assembled as a string at a
call site. On the value side that is `CspDirective`, `CspKeyword`/`CspScheme`/`CspHost`
behind a `CspSource` interface, `ReferrerPolicy`, `PermissionsPolicyFeature`,
`StrictTransportSecurity`, `MimeType` — so a misspelled directive or an unquoted `'self'` is a parse
error at build time, not a header the browser silently drops.

The headers, as sent:

```
Strict-Transport-Security: max-age=31536000; includeSubDomains
Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self';
    img-src 'self' https://my.hidrive.com; frame-src https://w.soundcloud.com;
    base-uri 'self'; form-action 'self'; frame-ancestors 'none'; object-src 'none'
Referrer-Policy: strict-origin-when-cross-origin
X-Content-Type-Options: nosniff
Permissions-Policy: geolocation=(), camera=(), microphone=(), payment=(), usb=(), midi=()
```

Those five are `SecurityHeader`'s whole set, and a test asserts the enum and what is sent match
exactly. Three are the app's to widen, and each is at its strictest unless the app says otherwise:
`App::contentHosts()` names third-party origins under every fetch directive but `default-src` and
`object-src` — `connect-src`, `media-src` and `font-src` are written only when it names one — and
`App::strictTransportSecurity()` and `App::permissionsPolicy()` answer for the other two. A
`Permissions-Policy` case is a feature browsers still recognise; one they do not is a console error
on every page, not a stricter policy.

A document carries three more, which are about caching rather than security and so live in
`ResponseHeader`:

```
Cache-Control: no-cache
ETag: "…"
Vary: X-Requested-With, Accept-Language, Cookie
```

`no-cache` is not `no-store` — it means keep the copy and revalidate before reusing it. The one page
behind the admin gate says `no-store, private` instead, and opting out that way is also what stops
`ViewResponse` giving it a validator at all. Only a 2xx carries an `ETag` or is answered with a
`304`; a 404 still says `no-cache`, and every response `ViewResponse` sends carries the `Vary`,
because it says what the body depends on rather than how long it may be kept. `If-None-Match` is
read as a list and compared weakly, and a `-gzip`, `-br` or `-deflate` that a compressing module
appended inside the quotes is dropped — compared verbatim, no compressed page was ever a 304. See
`ViewResponse::cacheHeaders()` and `ETag::matches()`.

The CSP's **absences** are the interesting part, because each is a scheme source someone debugging a
broken asset would paste straight back in:

- **No `'unsafe-inline'` on `script-src` or `style-src`.** No view emits an inline style or an event
  handler (a test enforces it), and the SoundCloud player sets its accent and attribution styling
  through the CSSOM rather than a `style` attribute, so there is nothing for the allowance to cover.
- **No `data:` on `img-src`.** Nothing references one — the cover placeholder is a file. A `data:`
  image is cheap; a `data:text/html` document runs script in the navigating origin, and an allowlist
  that has said `data:` once is easy to widen by accident. Both suites assert the absence.
  ([history](https://github.com/neurosysgg/neurosys-webspace/blob/master/docs/history/security.md))
- **No `report-uri`/`report-to`.** A report is a `POST`, which the `405` gate refuses; a third-party
  collector is a third-party origin receiving a request from every visitor before any consent; and a
  report's `document-uri`/`blocked-uri` is data the privacy policy does not claim. The policy is
  asserted at **build time** — both test suites pin the directive set — rather than observed at run
  time, which is the job a `report-uri` would otherwise do.
- `object-src 'none'`, `frame-ancestors 'none'`, `base-uri 'self'`, and `form-action 'self'` round it
  out. `form-action` on a site with no form is belt over braces, and stays because the day a form
  appears is not the day anyone will remember to add it.

`SecurityHeaders::send()` also **removes** a header. PHP appends `X-Powered-By` with its exact patch
version before any of this code runs, so `expose_php` in php.ini (not ours, on shared hosting) is
only half the switch; `header_remove()` is the half we have. It is invisible under CLI and the
built-in dev server — `header()`/`header_remove()` are no-ops there — and only observable under a
real SAPI, where the header is confirmed gone.

## 3 + 4. The method gate

`Router::dispatch()` matches the path first and then asks that route whether it answers the method,
refusing with a `405` before any controller is built. The `Allow` header is `Allow::readOnly()`,
derived by filtering `HttpMethod::cases()` on `isReadOnly()`, so it cannot advertise a verb the gate
does not honour — a hand-written `Allow: GET, HEAD` could drift; this cannot. An unrecognised method
(`REQUEST_METHOD` is whatever the client sent) parses to `null` rather than a guessed `GET`, and
`null` is not read-only — so a `PROPFIND` or a typo is refused, not silently treated as a read.

**`TRACE` never reaches this gate on the live host.** Strato's Apache refuses it itself — `405`, an
empty `Allow`, Apache's own body, nothing echoed — on every path including static files (checked
2026-09-10 with `curl -X TRACE`). The local rig has `TraceEnable On`, so a local run is no evidence
either way. If the live answer ever changes, the only lever is a `RewriteRule` refusing the method:
`TraceEnable` is a server-level directive and invalid in `.htaccess`.

**The method question lives on the route, as a `MethodPolicy` rather than a set of methods.** That
is not a stylistic choice. A route carrying its own set would make the `405` name it, so
`PUT /api/update/v1/patch` would answer `Allow: GET, HEAD, POST` — and that `POST` is precisely the
fact the endpoint exists to hide; an unrecognised verb, being in no set, would make the refusal name
the whole set. So there are two policies, not ten sets: nine routes are `ReadOnly`, and `/api` is
`Delegated`, which means the router forms **no opinion at all** and the controller answers every
method itself. The only `Allow` the router ever sends is `GET, HEAD`.
([history](https://github.com/neurosysgg/neurosys-webspace/blob/master/docs/history/api.md))

**That `null` has a second job at the gate**, and it is the sharper half. `ApiGate::accepts()`
refuses an unrecognised method on its first line, before anything else — because the envelope binds
a method, and comparing `null->value` against it would be an uncaught `TypeError`: a `500` where an
absent address sends a `405`. One differing status code and the whole property is gone, to anybody
who types `BREW`. The verify script sweeps `BREW` alongside every real verb, at every depth.

## 2 (again). Parsing the request defensively

`Request::path()` is the one place a malformed request target is dealt with. It uses
`Uri\Rfc3986\Uri::parse()`, which returns **null** on a target it cannot read, so `??` is a real
guard. A genuinely unparseable target falls back to **its own path** — everything up to the first
`?` or `#`, which is what the parser would have answered had it succeeded — rather than to the home
page, which would be the quieter wrong.

That fallback **still matches placeholder routes**: `Route::matches()` compiles `{slug}` into
`([^/]+)`, which matches anything, so a malformed target reaches a controller like any other.
`RoutingTest` pins that it does and `RequestTest` pins the cut. What keeps a hostile slug out of a
response header is the realm handling under [Authentication](https://github.com/neurosysgg/neurosys-webspace/blob/master/docs/security.md#3-again-authentication), not the
router.

Do not reintroduce `parse_url()`: it signals failure with `false` rather than null, which `??` does
not guard, and `GET ///` then reaches `rtrim()` as a `TypeError` — a `500` ahead of the router and
the method gate. ([history](https://github.com/neurosysgg/neurosys-webspace/blob/master/docs/history/security.md))

The path is matched **raw, not decoded**: `%2f` stays `%2f` and `%2e%2e` stays `%2e%2e`, so an
encoded slash cannot split a segment into an extra route parameter, and encoded dot-segments cannot
walk anywhere. Directory traversal toward the credentials is in any case structurally impossible —
`data/` lives **outside** the webroot, and the web server itself refuses `..` in a request path with
a `400` before the app is even reached.

## 4 (again). Routing

A route pattern compiles to a regex anchored with `\z`, not `$`: `$` also matches immediately before
a trailing newline, so `\z` is what actually means "the end of the string". Placeholders capture
`[^/]+`, matching is case-sensitive, and there is no dot-segment normalisation that could resolve a
decorated path onto a gated route.

## 5. The response — output safety in the markup tree

**Nothing on the site builds HTML from a string.** A view returns a `Node`; a page is a tree of them;
the only code that writes a `<` is `Element` and `Doctype`, and a verify check fails the build if a
heredoc or a `'<tag'` literal appears anywhere else under `src/`. Two guarantees are enforced in
`Element::render()` — the *only* code that turns a node into markup — so they hold for any element
however it was built, including one assembled from an array:

- **Escaping happens in exactly one place.** An attribute value is escaped by rendering it as a
  `Text` node, so `htmlspecialchars` is called once on the whole site, with one stated set of flags.
  A test pins that call site.
- **A URL attribute is asked what scheme it names**, because escaping is the wrong tool for a URL and
  always was — `javascript:alert(1)` contains nothing `htmlspecialchars` touches. The allowlist is
  `https:`, `mailto:`, and site-relative. `http:` is absent because HSTS means we do not emit one;
  `data:` is absent because a `data:text/html` document runs script in the origin that navigated to
  it. A leading slash is **resolved**, not assumed to be local: PHP 8.5's WHATWG URL parser strips
  tab, CR and LF from a URL before parsing, so `//host`, `/\host` and `/\t\n/host` are all
  `https://host` to a browser — the value is resolved the way a browser would and accepted only if it
  lands back on the origin it started from. Every spelling is pinned by `HtmlTest`.

**Hand-authored markup goes through the same rules.** `MarkupParser` reads the two halves of the
privacy policy (`data/privacy.de.html`, `data/privacy.en.html`) into the tree, so a hand-authored
document is subject to every rule above rather than exempt from them — its element and attribute
names have to be ones this site emits, its text is escaped by `Text`, and its `href`s go through the
same scheme allowlist. That is what refuses an `onerror=`, and it is checked when the file loads
rather than trusted. `Element::containingHtml()` is the only way in, its call sites are pinned by a
test named for the fact, and it is never handed anything a request can influence. There is no node
that emits markup verbatim. ([history](https://github.com/neurosysgg/neurosys-webspace/blob/master/docs/history/security.md))

Two things follow from this that are worth stating plainly:

- **No request data reaches a URL attribute.** Release and profile pages render trusted data; the one
  place request input is reflected — the `404` page echoing the path into a terminal's `command` — is
  a *text* attribute, escaped by the rule above, and the client-side element sinks it via
  `textContent`, never `innerHTML`. So neither the HTML context nor the DOM context can break out.
- The **client** enforces the same origin rule as the server. `Navigation` intercepts internal link
  clicks by matching the `href` *attribute* but then uses the *resolved* `href`, reconciling the two
  so a protocol-relative or cross-origin URL is handed back to the browser rather than fetched and
  written into `#content`. Nothing the server emits is protocol-relative — `Element` refuses to write
  one — so both halves are the same check from opposite sides.

## Input validation at the boundary it is written

Values that come from the site's own data and config are validated at **construction**, so a bad
paste throws when the data file loads — where the mistake actually is — rather than `404`ing from a
file host, breaking a header, or rendering a dead link when a visitor arrives:

| Type | Invariant |
|---|---|
| `HiDriveLink` | share id is exactly nine alphanumerics |
| `CspHost` | a bare origin — scheme + host (+ optional port), no path or trailing slash |
| `MimeType` | a well-formed subtype token |
| `Profile` | an absolute `https://` URL |
| `Location` | an absolute `https://` URL, or a path the WHATWG parser keeps on this site — the one address the site emits in a header |

All of them anchor with `\z`, not `$`, because `$` also matches before a trailing newline — the same
rule the router's patterns follow. Each bad-input test provider carries a trailing-newline case.
([history](https://github.com/neurosysgg/neurosys-webspace/blob/master/docs/history/security.md))

## The API

`/api/{service}/{version}/{action}` is the one address family that writes. It exists because
deploying means `rsync -c` over a GVFS SFTP mount where a single `stat` costs **480 ms** and walking
`src/` alone costs **3.7 s**, across 269 files, for a payload that is **250 KB gzipped** — a figure
that grows with the codebase, so `php tools/push-update.php --dry-run` re-derives it. It replaces
minutes with one request.

**One `SitePath` case matches the whole family**, so a new service is an `ApiService` case and its
handlers, with no route to register, and it inherits the silence, the method policy, the key, the
serial rule and the indistinguishability sweep without a line arranging any of them.
`tools/api.php` resolves an address through the site's own `ApiService` and `ApiAction`, so a new
service needs no change there either. There are no aliases: an endpoint whose design is to be
unfindable does not want two doors. ([history](https://github.com/neurosysgg/neurosys-webspace/blob/master/docs/history/api.md))

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
rendered `404` for a read method, the `text/plain` `405` with `Allow: GET, HEAD` for anything else,
and the same for a verb the site does not recognise. Not a `401`, which would prompt; not a `403`,
which would confirm; not a `405` naming `POST`, which would confirm more precisely.

That is a property of the structure rather than of two implementations kept in step: both responses
come from `UnroutedController`, the very object `Router` delegates to when no route matches at all.
`ApiController` hands it anything it will not verify. The verify script checks the claim over real
HTTP, per method **and per depth**, against `/no-such-page` — because the claim is about status
codes, headers and bodies, and only a real server has those.

**`public/api/` must never exist.** The webroot passes real files and directories straight through
(`RewriteCond !-f` / `!-d`), so a directory there would be answered by Apache — a listing or a
`403` — and `/api` would stop looking like a typo without a line of PHP being involved. The verify
script asserts nothing is there. A push cannot be what creates it either way: `public/` is a root,
so a member named `public/api/...` would be written — the assertion is about what is in the
repository, not about what a signed caller can do.

**That scope is deliberate: status, headers and bodies, and not timing.** A request carrying an
`NS1` credential reaches `ApiGate`, which reads the key and — once the frame parses — runs
`openssl_verify`; a path that matches no route never does either, because it never leaves
`UnroutedController`. The 2026-09-09 pentest measured the gap at about **180 µs** on localhost
between the `/api` shape and a typo of the same length, both carrying a well-formed-but-bogus `NS1`
header. It is not a usable oracle, and the reason is its precondition rather than its size: the gap
appears only for a caller already sending an `NS1`-framed `Authorization`, and knowing that scheme
exists — the source is public — already implies knowing `/api` does. It is well below WAN jitter,
and it vanishes entirely on a deployment holding no key, which is the one place the silence has to
be perfect. It is written down because the "same `null` reaching the same line" phrasing below
reads as a timing identity it does not claim; closing the axis for real would mean a constant-time
dummy verify on every unrouted path, which protects nothing a reader of this repository could not
already know.

**The gate verifies before it resolves**, which is what keeps that structural. Asking "does this
service exist" first would answer an unsigned caller through a different path depending on what they
guessed, and two paths that agree today are two paths free to stop agreeing. Verified first, a
service that does not exist and a signature that does not verify are the same `null` reaching the
same line. Past the gate the posture inverts: an unknown action is a real `404` with a sentence, a
verb that is not the action's is a real `405` naming the one that is, and only the key holder ever
sees either.

### The credential is a key the server cannot use

`data/update.pub` holds an **ECDSA P-256 public key**. The private half lives at
`~/.config/neurosys/update.key`, outside the repository entirely, and is the same arrangement the
SoundCloud refresh token has: no `.gitignore` entry and no rsync flag is what stands between it and
a webroot, because it was never in reach of either.

The server therefore holds nothing replayable. A full compromise of the account yields the public
half and no ability to push anything. That asymmetry is the reason this gate is a signature rather
than a fourth bcrypt digest — the other three gates protect pages, and this one protects the code
that serves them.

**P-256 rather than Ed25519, by measurement rather than taste.** `ext/sodium` is absent on the
development machine, and Ed25519 does not work through PHP's openssl binding at all — it fails with
`Provider routines::invalid digest`, because the binding drives the digest-based API and Ed25519 is
one-shot. P-256 was verified end to end on the live host before it was relied on, and `PublicKey`
accepts that curve and no other: an EC key on P-384 or secp112r1 parses and verifies a SHA-256
signature just as happily, which would widen the algorithm without anybody having decided to.

**Its absence is the off switch, with the opposite polarity to `data/site_auth.php`.** No key file,
no endpoint, for everyone, forever. So a fresh clone and every machine that has not deliberately
been given a key are closed rather than open — worth reading twice, because the two files look
alike and mean opposite things.

`PublicKey` is the only `openssl_*` call site under `src/`, which the verify script pins the way it
pins `curl_` to one file under `tools/lib/`. It asks `=== 1`, because `openssl_verify()` returns
`1`, `0` **or `-1`**, and a call site written `if (openssl_verify(...))` would read the error case
as a pass. A second check asserts that nothing under `src/` names a signing or key-minting call at
all, so a private key arriving on the server would have nothing to use it.

### What a signature covers, and why replay is closed

The credential rides in `Authorization` as `NS1 <base64>`: a length-prefixed manifest and the
signature over it. The signature covers the manifest; the manifest covers the body by SHA-256. One
signature over a couple of hundred bytes therefore protects a payload of any size — **and a payload
of no size at all**, which is why it rides in a header: a `GET` has nothing to frame a credential
into, and every action after the first one is a read.

`ApiCredential::parse()` bounds every length in the frame against what is actually present before
using it as an offset, and each failure is an exception the gate turns into the same silence as any
other — a malformed credential is never a `500`. The verify script carries six malformed-credential
probes; the one over the header size limit is refused by Apache with a `400` before PHP sees it,
which is a refusal from the wrong layer but not one that says `/api` is there.

**The manifest binds the request, not just the payload.** It carries `method` and `path` beside the
digest, and both are checked against the request carrying them. Without them a credential would
authenticate *some* request rather than one: a credential minted for a read would replay as a
write, and one minted for one action would verify at another. ([history](https://github.com/neurosysgg/neurosys-webspace/blob/master/docs/history/api.md))

Three details of that binding are worth stating, because each is the kind of thing that is
discovered rather than read:

- The digest is checked for **every** method, never skipped for one "with no body". A read signs
  `sha256('')` and a size of zero, so no branch is needed — and a signed `GET` cannot smuggle a body
  past it for some later action to read unsigned.
- What `path` binds is `Request::path()`'s output, not the wire target: normalised, so a trailing
  slash is the same signed path. It is compared against that string directly and never against one
  rebuilt from the router's captures, because `SitePath::to()` `rawurlencode`s each value and is
  therefore **not** the inverse of `Route::matches()`.
- The **query string is not covered**, because nothing under `src/` reads one — no code touches
  `$_GET` or `QUERY_STRING`. An API action must therefore never read a query parameter: it would be
  the one input reaching a verified caller's handler unsigned. A parameter belongs in the manifest
  or in the body. `health` and `capability` have an obvious temptation here, a `?verbose` or an
  `?area=`, and take none. One area is asked for by an address of its own (`health v1 settings`),
  and each action reports everything it reports, always. The note saying so is on `HealthCheck`
  itself as well as here.

**Cross-deployment replay is closed by key separation rather than by an audience field.**
`data/update.pub` is gitignored, per-deployment and uploaded by hand, so no two deployments hold the
same key and a credential minted for one verifies nowhere else. The tools hold the signing side to
it: `ApiTarget` gives every origin but the default one a key of its own, and refuses the default key
for any other origin — by path or by content, so a copy under another name is refused as well. An
`aud` field would have to be
checked against something the server knows independently of the request, and `Host` is whatever the
caller sent — so it would bind nothing. If a second deployment ever shares this key, that is the
field to add.

The manifest also carries a `serial` doing double duty: it must be within **±300 s** of the server's
clock *and* strictly greater than the highest serial already accepted, which is recorded at
`cgi-bin/.update-serial` — above the webroot, in neither mirrored tree, and in no rsynced one. The
monotonic half alone would accept a credential signed long ago and never sent; the skew half alone
would leave a five-minute replay window. A **dry run deliberately does not advance the serial**, and
neither does a **read**, so a captured one replays to nothing and a real push of the same payload is
still possible. A read not spending one is also what lets two calls be made in the same second,
since a serial is `time()` and the rule is strictly greater.

The accepted cost of that is narrow and stated rather than left implicit: a captured **read**
credential is replayable for the remainder of the skew window. What it yields is the answer to a
read — the deployed serial, build stamp and PHP version — to somebody who has already broken TLS.
Any write landing in the meantime kills it, since the monotonic half moves.

**A write spends its serial before the action runs, not after.** So a failure to record it is a
refusal with nothing written, rather than a deployment that has been updated by a credential that
could update it again. It also means a push that fails partway has still spent its serial, which is
correct: the bytes that produced it must never be accepted twice, and a corrected payload is
different bytes with a fresh `time()` on them anyway. ([history](https://github.com/neurosysgg/neurosys-webspace/blob/master/docs/history/api.md))

**A write holds a lock from spending to the end of the action.** `ApiGate::spend()` takes an
exclusive, non-blocking `flock()` on `.update-serial.lock` beside the serial, asks freshness again
under it, and records the larger of the two serials — so two overlapping writes can neither run at
once (each would mirror over the other) nor move the record backwards (which would reopen the newer
credential to replay). A write that finds the lock held is a **`409`** that spends nothing and
touches nothing; the caller is already verified by then, so the sentence is allowed. Reads never
lock. A lock file is never deleted, since deleting it would let two processes hold locks on two
inodes under one name.

**Why a header is acceptable here.** `Authorization` is a `ServerVariable`, not a `RequestHeader`,
so it has no TypeScript mirror and puts nothing in the browser's bundle. The live risk is that a
header is the part of a request most likely to be rewritten in transit, which is exactly why
`public/.htaccess` puts this one back with `E=HTTP_AUTHORIZATION` and `Request::authorization()`
reads both spellings. Both Basic gates already depend on this header surviving Strato, which is the
strongest evidence available that it does — and it is the one thing here that should be re-checked
on the live host rather than reasoned about, because a proxy that strips it fails **closed and in
silence**, looking exactly like a bad key. ([history](https://github.com/neurosysgg/neurosys-webspace/blob/master/docs/history/api.md))

The size is arithmetic against a real limit rather than a guess. `LimitRequestFieldSize` is 8190
bytes for the whole field line; `Authorization: NS1 ` is 19 of them; base64 is 4 out for every 3 in;
the frame carries a 4-byte length and at most 256 bytes of signature. A 2048-byte manifest cap
therefore costs at most **3099 bytes**, leaving 62% of the line spare. Measured against a real P-256
pair, a push's header is **367 bytes** and a read's **331**, and the DER signature is **71**.

### What a verified payload may write

Three roots, and `data` is conspicuously not among them: `public/`, `src/`, and the single file
`autoload.php`. That one rule is what keeps `data/admin.php`, `data/site_auth.php`, `data/demos.php`
and 8.6 MB of unreleased audio out of reach of any push, however well signed — there is no
destination to compute for them rather than a destination computed and then rejected.

Every member of the archive is checked **before anything is written**, so a payload with one bad
name writes nothing at all:

- the tar reader is hand-rolled rather than `PharData`, because `PharData::extractTo()` decides for
  itself what a member name means and what a link points at, and those are exactly the decisions
  that must not be delegated when the names came off the network;
- only a **regular file or a directory** survives. A symlink, a hardlink, a device node, a fifo, a
  GNU long-name record and a pax header are each refused **by name** — not skipped, refused, since an
  archive containing one is not an archive this site produced;
- a name must match `[A-Za-z0-9._-]` segments separated by `/`, with no leading slash, no backslash,
  no empty segment and no `.` or `..`. There is **no `..` handling and no `realpath()` fallback**: a
  name that would need either is refused outright, which is why nothing downstream carries a
  traversal guard;
- the ustar header checksum is verified, because a signature says the bytes are ours and the
  checksum says they are a tar — and in a format that is nothing but offsets, bad framing means every
  name after it is read out of the middle of somebody's file;
- the archive must end in its zero block, with no partial block after it, so a truncated archive is
  refused rather than read as a shorter one;
- a name that is a file and also the directory of another member (`a` and `a/b`) is refused, since
  one of the two writes would fail after the other had landed;
- the gzip layer is decoded under `UpdateApplier::MAX_EXPANDED`, and the length is asked as well,
  because `gzdecode()`'s cap is only as fine as zlib's output buffer.

Each file then lands through `File::write()`, which writes beside the target and renames over it, so
every file appears atomically and within one filesystem. A file whose bytes are already there is
left alone — see [deployment.md](https://github.com/neurosysgg/neurosys-webspace/blob/master/docs/deployment.md) for why that matters on NFS. The mirror that removes
what a payload omits is an **enumerated delete**: the tree is walked, diffed, and each surplus path
is checked by the same rules an added path passes before `File::delete()` is called on it, one named
file at a time. `Directory::remove()` is never used for it — that method deletes the files a
directory holds, which is right for tearing down a fixture and catastrophic here.

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
([history](https://github.com/neurosysgg/neurosys-webspace/blob/master/docs/history/security.md))

### Where the roots resolve

`Deployment` maps a root to a directory; `UpdateRoot` is only the vocabulary. They are separate
because deciding whether a name is *under* `public/` must never require resolving where `public/`
**is** — the second question reaches `DOCUMENT_ROOT`, and the first is asked of names off the
network. ([history](https://github.com/neurosysgg/neurosys-webspace/blob/master/docs/history/api.md))

`Config::webroot()` takes only the **basename** of `DOCUMENT_ROOT` and hangs it off the derivation
every other path here uses, because on the live host the two spellings of one directory are
genuinely different strings — `/home/strato/…/cgi-bin/neurosys` against `/mnt/web505/…/cgi-bin/neurosys`
— and a path built from the wrong one compares equal to nothing. A basename grafted onto a different
tree names a real directory somewhere else, so it **refuses** rather than guesses, because every
candidate guess is a directory something would then be willing to delete:

- a `DOCUMENT_ROOT` that is unset or blank after trimming;
- one that is not absolute — for a bare relative name `dirname()` is `.`, whose realpath is the
  working directory, which under the test runner is the deployment itself;
- one that is not a directory inside the deployment, comparing `realpath()` on both sides;
- one that names no directory, even when its parent is the deployment — otherwise the first push
  would create a second webroot beside the real one and write the whole site into it.

And `UpdateApplier` takes its `Deployment` as a constructor argument, so a test is not merely
unlikely to reach the live tree — it cannot.
