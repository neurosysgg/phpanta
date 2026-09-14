# Security — the framework

What the framework does about the attack surface every site built on it shares: the headers,
the method gate, the parsing, the markup tree's output safety, and the admin — its signed calls and
its passkeys. A site's own
gates, cookies, data and hosting are its own, and belong in its own documents.

## 1. Transport — HTTPS and HSTS

A site's webroot redirects `http://` to `https://` before any PHP runs — that is a line of the
site's server configuration, not of the framework — and `Strict-Transport-Security` (one year,
`includeSubDomains`, by default) tells the browser never to try plaintext again. Both halves are
load-bearing and neither is optional: a Basic gate — `AdminGate`, or one a site builds on `Auth` —
sends its credential with every request behind it, Basic is base64 rather than encryption, and the
admin's browser session is a cookie that opens the admin. A request that arrives in plaintext has
already put its credentials on the wire — the redirect fixes the *next* request, and HSTS removes
there being a next plaintext one at all.

Read "a request that reaches PHP" literally. A webroot that passes real files through before its
rewrite to `index.php` serves **static assets without any layer or gate**, so a layer an app lists
covers the documents and not `/assets/**`. It is written down because "the layer runs on every
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
covers **every** response, including the `401` the auth gate answers with, the `303` a redirect sends,
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
Cross-Origin-Opener-Policy: same-origin
Cross-Origin-Resource-Policy: same-origin
```

Those seven are `SecurityHeader`'s whole set, and `SecurityHeadersTest` asserts both what an app that
widens nothing is sent and what one that widens the rest is. Five are the app's to widen, and
each is at its strictest unless the app says otherwise: `App::contentHosts()` names third-party
origins under every fetch directive but `default-src` and `object-src` — `connect-src`, `media-src`
and `font-src` are written only when it names one, and `frame-src` is `'none'` until it does — and
`App::strictTransportSecurity()` and `App::permissionsPolicy()` answer for the other two.
`App::crossOriginOpenerPolicy()` and `App::crossOriginResourcePolicy()` answer for the two
cross-origin policies, both `same-origin`: no window keeps a handle on this site's pages across the
origin line, and nothing PHP answers — a page, a gated file — can be loaded as a resource by another
site. A site whose sign-in popup must talk back, or that answers something meant to be embedded,
says so there. The resource policy does not reach what the web server answers straight from the
webroot, which never passes through PHP. An app
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
  want an eighth header, `Reporting-Endpoints`, naming an endpoint that does not exist.
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
is not a stylistic choice. A route carrying its own set would make the `405` name it — right for
nearly every route, and wrong for the admin's, whose controller answers a caller it cannot verify
the same way at every depth, whether the address exists or not. A router that refused a `PUT` to
one depth with one `Allow` and let it through to another would say which depth is an action before
the caller had proved anything; an unrecognised verb, being in no set, would make the refusal name
the whole set. So there are two policies, not one set per route: every route a site registers is
`ReadOnly` unless it says otherwise, and the framework's four `AdminPath` routes are `Delegated`,
which means the router forms **no opinion at all** and the controller answers every method itself.
The only `Allow` the router ever sends is `GET, HEAD`, and the admin sends one only to a caller it
has verified.

**That `null` has a second job at the gate**, and it is the sharper half. `ApiGate::accepts()`
refuses an unrecognised method on its first line, before anything else — because the envelope binds
a method, and comparing `null->value` against it would be an uncaught `TypeError`: a `500` where
every other unverified request gets the admin's one answer. One differing status code and the
uniformity is gone, to anybody who types `BREW` — which is why an end-to-end sweep of every real
verb belongs beside it, `BREW` included, at every depth.

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

## Uploads

A file a form sends is an [`Upload`](../src/Http/Upload.php), and everything about it but its bytes
is the sender's word:

- **The name is shown, never used.** `clientName()` is what the browser sent, which PHP cuts to
  the last segment of any path and otherwise leaves as any name at all — a dotfile, `index.php`.
  A page keeps a file under a name of its own, outside the webroot, and shows the client's name
  escaped like any other text.
- **The claimed type is not read at all.** A part's `Content-Type` is the browser's guess, or a lie.
  The framework needs no `ext/fileinfo`, so a site that cares what a file is checks its bytes.
- **Size is bounded three times**: `upload_max_filesize` and `post_max_size` on the host, which
  `Upload::requirements()` states for `health v1`, and a field's own `MaxBytes`. A form over
  `post_max_size`, which PHP empties in silence, is a 413 — never a form that seems to have sent
  nothing, and never a form token refused for being absent.
- **Kept atomically, and only where asked.** `keepAs()` moves the file beside its target and
  renames it into place, creating no directory. It is not `move_uploaded_file()`, whose
  `is_uploaded_file()` guards a temporary name a request could write; here the name is PHP's, read
  from `$_FILES` by one class, [`MultipartParameters`](../src/Http/MultipartParameters.php).

A form that sends files carries its form token like any other: `CsrfGuard` reads it from what PHP
parsed.

## Sessions, the form token and the login

A site that has a form and a login keeps a [`Session`](../src/Http/Session.php). The framework holds
it to four things:

- **The visitor carries it, sealed.** A shared host gives a site no process to keep a session store
  in, so the whole session is one cookie, sealed by [`SessionSeal`](../src/Http/SessionSeal.php)
  with AES-256-GCM: the visitor can neither read it nor change a byte of it. Every seal takes a fresh
  nonce and binds a fixed context string as associated data. A cookie that does not open —
  tampered with, sealed under another key, cut short, or kept past its lifetime of two weeks — is
  simply no session. A session sealed larger than a cookie holds is refused rather than cut.
- **The cookie is `__Host-session`,** `Secure`, `HttpOnly`, `SameSite=Lax`, `Path=/`, and names no
  `Domain`. The `__Host-` prefix is the browser's own check that no other host under the domain can
  plant one.
- **The key is per deployment,** thirty-two random bytes in `data/session.key`. It is minted on the
  host it serves, gitignored, and excluded from a deploy like the other credentials. Nothing asks for
  it until something keeps a session, and a deployment that keeps one without it stops, saying how to
  mint it. The admin's browser side fails the other way: without the key it lets no browser in and
  says so, while the signed calls go on as before.
- **A login hands out a new form token,** so a token a page wrote before the login is worth nothing
  after it, and a logout forgets everything the session kept.

[`CsrfGuard`](../src/Service/Layer/CsrfGuard.php) holds every write to the token the visitor's session
handed out: a `_csrf` field that matches it, compared in constant time, or a 403 that no cache keeps.
A read passes untouched. [`LoginGate`](../src/Service/Layer/LoginGate.php) sends a visitor who is not
logged in to the login page for a read, and refuses a write. [login.md](login.md) puts the pieces
together into a working login page.

A [`Form`](../src/Form/Form.php) writes the `_csrf` field itself, from the token the page hands it,
so a form that renders is one the guard accepts and no page spells the field for itself; a
hand-authored `<form>` is refused by the parser for posting without one. Where a form may post is
bound twice: its `action` is scheme-checked like any `href`, and the policy's `form-action 'self'`
already refuses any other origin in the browser.

**Both are listed on the routes that take them, never on the app.** An app layer stands in front of
every address, the admin's included: as one, `CsrfGuard` would refuse every signed write, which
carries a signature rather than a form token, and `LoginGate` would answer a stranger at the admin
with a login page instead of the admin's one answer. On a route, past its method gate, only the
requests that route takes ever reach them. The admin's routes carry neither: it checks a browser's
form token itself — see [A browser, by passkey](#a-browser-by-passkey).

A site's own session keys are a [`SessionKey`](../src/Http/SessionKey.php) enum, and a key may not
begin with `_`, where the framework keeps who is logged in, the token, the admin's unlock and a
challenge a page handed out. Otherwise a site could log a visitor in, or unlock the admin, by setting
a value. A message left for the next page is a catalog case, so it is in the
visitor's language on whichever page shows it, and is shown once.

## The admin

`/admin` is where a deployment is administered, and the actions under it are the one address family
that writes. It exists because a deployment reachable only through a file-transfer mount pays for
every `stat`, and walking a tree file by file to find what changed costs seconds where one request
carrying the whole tree costs one round trip. A push's dry run reports the payload's size, which
grows with the codebase.

**Four `AdminPath` cases, one per depth**, and `App::adminRoutes()` makes a route of each: the
entrance at `/admin`, a service at `/admin/{service}`, one of its versions at
`/admin/{service}/{version}`, and an action at `/admin/{service}/{version}/{action}`.
`App::routeTable()` appends them to every site's routes, last, so a site can neither shadow them nor
forget them. All four are `Delegated`, and none is a page of a static export — an export makes only
anonymous requests, and the admin has nothing to show one. A new service is an `ApiService` case,
its action enum and its handlers, with no route to register: it inherits the gate, the method
policy, the key and the serial rule without a line arranging any of them, and it appears in the
listings the moment it exists. The signed commands resolve an address through the same
`ApiService` and `ApiAction`, so a new service needs no change there either. There are no aliases,
and nothing answers under any other prefix: an address outside `/admin` is a site's to route or to
leave unrouted.

`health`, `capability` and `access` are the other three services, and each cost exactly that: an
`ApiService` case, an action enum and its handlers.

- `health` checks the site's declared requirements and answers `503` when a required one is unmet.
- `capability` lists what the host has: every extension, every directive, the SAPI, the clock, the
  `data/` files, the error log's tail.
- `access` holds the devices that may open the admin in a browser: `enrol`, `passkeys` and `revoke`
  — see [A browser, by passkey](#a-browser-by-passkey).

In anyone else's hands, either answer is reconnaissance. That is exactly why they are services
behind this signature rather than the public `/health` a monitor would ping. See
[health.md](health.md).

### It says it is there, and nothing about what is in it

**That there is an admin is not a secret.** The framework's source is public, and a site may link to
its admin; an address pretending not to exist would be hiding a fact every reader already has. What
a stranger must not learn is what is *in* it — which services a deployment offers, which of them
write, what an action is called — and that is what the gate keeps, by giving one answer at every
depth. ([history](history/admin.md))

[`ApiController`](../src/Controller/ApiController.php) asks three questions, in this order, and the
order is the design:

1. **What can the caller read?** A request's `Accept` is negotiated first. Absent, `*/*` or a
   browser's header is a page, in the app's own shell; `application/json` is data, which is what the
   signing commands ask for; a request naming only other types gets a `406` in `text/plain` naming
   the two it can have. Asked before anything else, the `406` is the same at every address, and a
   write is never carried out for a caller who then could not be told how it went.
2. **Who is asking?** A signing command, which [`ApiGate`](../src/Service/ApiGate.php) verifies, or
   a browser whose session an enrolled passkey unlocked, which
   [`AdminBrowser`](../src/Service/Passkey/AdminBrowser.php) recognises. Anybody else gets **one
   answer at every depth below the entrance, whether the address exists or not**, under every verb —
   one the framework does not recognise included, since `Request::method()` is null for it and the
   routes are `Delegated`. A page request is a `303` to `/admin`; a request for data is a `401`
   carrying `WWW-Authenticate: NS1` — [`SignedChallenge`](../src/Http/SignedChallenge.php), a
   scheme no browser has a prompt for, so none shows one — and a JSON refusal. No `Allow` header is
   ever sent to a stranger. `GET` or `HEAD /admin` itself is the entrance,
   [`AdminEntranceView`](../src/View/AdminEntranceView.php), a `200` that offers a browser a way in
   where the deployment lets one in, and says that it does not otherwise; a `POST` there is one of
   the entrance's ceremonies, and anything else at `/admin` is a `303` to it.
3. **What is here?** Only past the gate, and reported in full, because the caller has proved it holds
   the key — see [What a verified caller gets back](#what-a-verified-caller-gets-back).

**It verifies before it resolves**, which is what keeps the second answer uniform. Asking "does this
service exist" first would answer a stranger through a different path depending on what they
guessed, and two paths that agree today are two paths free to stop agreeing. Verified first, a
service that does not exist and a signature that does not verify are the same `null` reaching the
same line. The claim is about status codes, headers and bodies, so it is worth checking over real
HTTP, per method **and per depth** — only a real server has those.

**`public/admin/` must never exist.** A webroot that passes real files and directories straight
through (`RewriteCond !-f` / `!-d`) would have a directory there answered by Apache — a listing or a
`403` — before the admin could give its own answer, and the one answer would no longer be one. A push
is no guard against it: `public/` is a root, so a signed member named `public/admin/...` would be
written. The rule is about what a site's repository holds, not about what a signed caller can do.

Every answer under `/admin`, at every depth and to every caller, says `Cache-Control: no-store,
private`, asks not to be indexed (`X-Robots-Tag`, `RobotsPolicy::hide()`), and varies on `Accept` —
a page through its view's `varyOn()`, data by saying so — so two callers asking one address for
different forms get different bytes, and none of it is for a cache to keep.

### What a verified caller gets back

Past the gate the posture inverts, and failures are reported in full. A browser the admin has let in
gets what a signed caller gets, less what an action keeps for the key, and a write is its form before
it is a write — see [A browser, by passkey](#a-browser-by-passkey).

- **Above an action, a listing.** At `/admin`, a service or a version, an
  [`ApiListing`](../src/Http/Api/ApiListing.php) names what is under it: for each entry its name,
  its address and a description, and for an action also its method, whether it writes, whether a
  browser may run it (`ApiAction::fromBrowser()`, false for `update v1 patch`, whose tree only the
  signing commands can send, and for `access v1 enrol`, where trust starts), and the fields it takes
  beside its address. A browser that asks for one of those two is refused with a `403`. A service or version
  that does not exist is a real `404` — `no such admin address: <path>` — and a listing answers reads
  only: anything else is a `405` with `Allow: GET, HEAD`.
- **At an action, its answer.** An action that does not exist is a `404` — `no such API action: …` —
  and a verb that is not the action's is a `405` whose `Allow` names the one that is. Otherwise the
  handler answers an [`ApiResult`](../src/Http/Api/ApiResult.php), a status and the sections of a
  report; an `ApiException` from building or running it is a `422`, `refused: …`, and a serial
  refusal is the `409` or `500` [below](#what-a-signature-covers-and-why-replay-is-closed).

Either is written in the form negotiated first: a page — `ApiListingView`, `ApiResultView` — or the
same value as JSON, whose keys are the [`ResultKey`](../src/Http/Api/ResultKey.php) cases, so the
commands that read it back read the server's own names.

**A listing and the resolver cannot disagree**, because both ask one method.
`ApiService::actions(ApiVersion)` is the only place a service is mapped to its actions;
`ApiService::versions()` is the versions at which it has any, and `ApiService::action()` resolves an
address through the same set. `ApiAction` extends `BackedEnum`, so an action's segment is its value,
spelled once. What a listing says of each entry is `describe()` — on `ApiService`, `ApiVersion` and
`ApiAction` alike — from the framework's own catalog, `AdminText`, in English and German. An action's
fields are [`ActionField`](../src/Http/Api/ActionField.php) cases — `apply` and `mirror`, whose
values are `UpdateManifest::APPLY` and `UpdateManifest::MIRROR`, and `code`, `name` and `passkey`
for `access` — one spelling for what a listing names, what a manifest carries and what a browser's
form sends.

### A browser, by passkey

A browser cannot sign: a navigation sends no header a page chose, so it never carries `NS1`. It is
let in by **a passkey** (WebAuthn) instead — a key pair per device, its private half in the
platform's secure hardware or its password manager, used with one tap or a biometric. The server
keeps only the public half, the property `data/update.pub` has: a key the server cannot use. A TLS
client certificate would be the other browser-native credential, and a host that ends TLS at its
front proxy never lets the application see one. ([history](history/admin.md))

**ES256, through the one key reader there is.** WebAuthn's default algorithm is ECDSA over P-256 with
SHA-256, and an assertion's signature is DER over `authenticatorData ‖ sha256(clientDataJSON)` —
which is what `PublicKey::verifies()` already checks for the signing key. The client asks for ES256
alone, so a key of another kind is never made, and no CBOR is parsed: a registering browser's own
`getPublicKey()` hands over SPKI DER, which `PublicKey` reads.
[`PasskeyVerifier`](../src/Service/Passkey/PasskeyVerifier.php) makes every check every time: the
ceremony's type, the challenge (`hash_equals`), the origin and that it did not run in another
origin's frame, the relying party's hash (`sha256` of the origin's host), the user-present and
user-verified flags, the signature, and a signature count that must rise whenever it is not zero —
the same count twice is two authenticators answering for one key. A registration also needs the
attested-credential flag. Nothing about the device is attested: trust comes from the signed
enrolment, not from the hardware.

**Enrolment is rooted in the signing key**, and nothing a browser does on its own writes to the
server:

1. At the entrance, *Register this device* runs `navigator.credentials.create()` over a challenge
   the session holds. The server checks the answer and stores nothing. It shows an **enrolment
   code** — the credential id, the key and the time, sealed under `data/session.key` and good for
   ten minutes — and the key's fingerprint, the first sixteen hex digits of `sha256` of its SPKI.
   [`AdminEnrolmentView`](../src/View/AdminEnrolmentView.php) writes the code as a command ready to
   run.
2. `access v1 enrol`, a signed write taking `code` and `name`, opens the code — which proves this
   deployment made it, and lately — and adds the device to `data/admin-passkeys.json`
   (`CredentialFile::AdminPasskeys`, kept by
   [`PasskeyRegistry`](../src/Service/Passkey/PasskeyRegistry.php)).
3. `access v1 passkeys` lists the devices — name, fingerprint, credential id, when each was added —
   and `access v1 revoke`, a write taking `passkey`, takes one away.

`enrol` is the signing key's alone: a browser that could enrol would be a browser vouching for
itself.

**Unlocking.** *Unlock with a passkey* runs `navigator.credentials.get()` over the same challenge:
the entrance mints **one challenge for both ceremonies**, held in the session for two minutes and
spent by whichever answers it. An enrolled passkey that answers puts the unlock in the session — the
credential id and the time — and hands out a new form token. The unlock lasts
`Session::ADMIN_LIFETIME`, eight hours, however long the cookie is kept, and **every request asks the
store again whether that passkey is still enrolled, and when the admin was last locked with it**, so
a revocation or a lock takes effect on the next request.

**A sealed session can be copied, so what has to happen once is recorded on the server.** The
session is the visitor's cookie, challenge and all, and the server can neither take one back nor
tell a copy from the original. So the store keeps two times per passkey (`Passkey::$unlocked`,
`$locked`):

- **An unlock records the moment its challenge was minted**, with the count the passkey reported,
  and the next unlock's challenge must be newer. The same answer sent twice — the session that
  carried its challenge copied, and the post sent again — opens the admin once.
- ***Lock the admin***, on a browser's listings, is a `POST` to the entrance that ends the session
  and records the lock. Every session that passkey unlocked until then opens nothing more, on any
  browser and in any copy of the cookie. Where the store cannot record it, the session still ends
  and the entrance says that a copy of it may not have.

Every write to the store — an enrolment, a revocation, an unlock, a lock — is made under a lock of
its own, a file beside the store, against the store as it stands by then: an unlock racing a
revocation cannot bring the device back, and a device enrolled beside an unlock is not lost. So
`data/` must be writable by PHP wherever a browser signs in, as it already is for `access v1 enrol`.

**Every write needs a fresh tap.** A browser's `GET` of a write action is its form —
[`ApiActionFormView`](../src/View/ApiActionFormView.php): the action's fields, *Dry run* and
*Apply* — holding a single-use challenge minted for `POST <path>`. The post must carry the session's
form token and that challenge answered by the passkey that unlocked the session. The challenge binds
the method and the address, not the field values, which the form token and the session hold. The
session drops it whether the write is let through or not, so a tap sent again with the session the
answer left is a `403`, as is a write with no answer. A copy of the session from before the tap
would answer the same challenge again, so that is not what the server rests on: **a browser's write
takes the moment its challenge was minted as its serial**, and the serial the first post spent
refuses the second as stale — a `409`, with nothing written. A signed `GET` of a write is still the
`405` it always was. A browser's write becomes the same
`VerifiedRequest` a signed one does (`ApiEnvelope::of()`), and takes the same lock and spends a
serial in the same record — [below](#what-a-signature-covers-and-why-replay-is-closed).

**The form token is the admin's to check, not `CsrfGuard`'s.** The guard reads a `_csrf` field, so
on the admin's routes it would refuse every signed write, which carries a signature instead.
`AdminBrowser` checks the token itself, for a browser only, and it is the one reader of a form under
`/admin`.

**The entrance counts its posts**: ten per remote address in fifteen minutes, through `Throttle`
over `data/throttle/`, counted before anything is read. Past that is a `429` with `Retry-After`. The
directory is made by hand and must be writable by PHP; without it the entrance answers `503` and
takes nothing, failing closed.

**Off unless the deployment says where it is.** A passkey is bound to an origin, and the admin takes
its origin from `App::origin()`, never from the `Host` a request names. An app whose `origin()` is
null lets no browser in. In development and from loopback only, the request's own `Origin` comes
first — before the app's — so a local copy of a site that names its public origin runs a real
ceremony at the address it is served on; a key registered there opens nothing anywhere else, since a
passkey is bound to its host. A deployment with no `data/session.key`
lets no browser in either. In both cases the entrance says browsers cannot sign in here, and the
signed calls go on as before. `data/admin-passkeys.json` absent is no device enrolled, with
`data/update.pub`'s polarity, and a store that does not parse reads the same way. The session key and
the store are per deployment and never shipped.

**A stranger still sees the entrance and nothing else.** A session cookie that no enrolled passkey
unlocked is no caller, so every depth below the entrance still gives the one answer. The entrance's
own answers — its page, a `303` back to it, a `429`, a `503` — say whether browsers may sign in here
and whether the sender has posted too often, never whether a credential is enrolled: an unlock by an
unknown passkey and one with a wrong signature leave the same message.

**The server still holds nothing that signs**: public keys, and `data/session.key`. Somebody who
takes the account could forge an unlocked session and read what a browser may read; a write still
needs the passkey's tap, and a push or an enrolment the signing key.

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

**Its absence is the off switch, and off is closed.** No key file,
no signed call verifies, for anyone, forever: the entrance still answers, and every stranger still
gets the one answer, but there is nobody the gate lets past, and no device can be enrolled — and a
deployment holding no key does no verification work at all. A browser whose device was enrolled
before the key went still opens the admin; revoking it needs the key or that browser. So a fresh clone and every machine that has not deliberately been given a key are closed rather
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
using it as an offset, and each failure is an exception the gate turns into the same `null` as any
other, so a malformed credential gets the stranger's one answer and is never a `500`. One over the
server's header size limit is refused by the web server — Apache's `400`, or a `431` — before PHP
sees it, which is a refusal from the wrong layer, and says no more than the admin's own would. The envelope is not even parsed until the signature has verified.

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
- The **query string is not covered**. A page may read one — `Request::query()` — but an API action
  never does, nor a form: either would be the one input reaching a verified caller's handler
  unsigned. `InputTest` reads the API's code and fails on a call to either, so this is held rather
  than remembered. A parameter belongs in the manifest or in the body. A browser's write is the one
  form read under `/admin`, by `AdminBrowser` before any handler runs: only the fields the action
  declares, and only once the passkey has answered, into a manifest of the shape a signed call
  carries — so a handler reads a manifest whichever door the call came through. `health` and `capability` have an obvious temptation here, a `?verbose` or an
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
processes hold locks on two inodes under one name. A browser's write takes the same lock and spends
a serial in the same record — the moment its challenge was minted, the page's equivalent of the
signing — so a browser's write and a push never overlap, and one minted before a write the server
has since accepted is refused the same way.

**A write's serial may not be ahead of the server's clock by more than five seconds.** The skew
window is symmetric, which is right for accepting a call and wrong for recording one: a serial from a
machine whose clock runs fast would sit in the record in the future, and every correctly timed call
would be refused as stale until the clock caught up — a signed one with the stranger's answer, since
freshness is asked before the signature's sender may be told anything. So `ApiGate::spend()` refuses
such a write with a `409` saying the signing machine's clock is ahead, and records nothing. A read
records nothing, and is held to the skew alone.

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

**Every file that changes is staged before any lands.** Each is written into `.update-stage/` in
`App::above()` — the roots' own filesystem, since the temporary directory may be another device,
where a rename is a copy — and staging asks the live tree whether each destination can take a file
at all: a directory where the file goes, or a file where one of its directories must be, refuses the
whole push with nothing live written and the record of the last push untouched. Only then is the
release recorded and each staged file renamed onto its live path, in pack order, so every file
appears atomically and within one filesystem, and a failure while writing can no longer leave half a
release live. A stage an earlier run left is cleared first; one that is a file or a link is refused.
A rollback restores through the same stage. A file whose bytes are already there is
left alone, because rewriting a file the request is executing makes NFS silly-rename it into an
`.nfsXXXXXXXX` that lives exactly as long as the handle holding it. **That name is the NFS client's,
not the site's**: no payload may carry it, the mirror never counts one as surplus or records it,
and on the way past it tries each stray once and says in a `note:` what came of that — removed, or
still held by a worker — never a failure, since what holds it is a process the push cannot reach.
The mirror that removes what a payload omits is an **enumerated
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

**Before its first write, a push records the release it replaces** — the one thing a verified
payload causes to be written outside the four roots. `ReleaseRecord` keeps it in `.update-previous/`
beside `.update-serial` in `App::above()`: above the webroot, under no root (so no payload can name
it and no mirror walks it), and not in `data/`. It saves the bytes of every file the push will
overwrite and every file the mirror will delete, lists every file it will add, and keeps two digests
per path — what was there, and what the push writes. A file whose bytes are already current is
neither saved nor listed, for the NFS reason above. The index is written marked incomplete, then each
copy, then the index again marked complete, each through `File::write()`; if any step fails the push
is **refused with nothing live written** — a `422` saying why, its serial spent like any write's.
Only the last release is kept: each push clears the record — an enumerated delete of what its own
index lists, never a walk and never `Directory::remove()` — and takes a new one, and a push that
changes nothing leaves the record as it found it. The record never goes through a symbolic link: not
its directory, not a directory under `saved/`, not a copy, not a live file it saves. A surplus link
is not kept, and a destination that is a link is recorded as added.

### Taking a push back

`update v1 rollback` is the second action that writes. It carries no body — everything it restores
is already on the server — and one manifest field, `apply`, so a dry run reports, changes nothing
and spends no serial, while a real one spends a serial like a push. The names it reads back out of
the record pass every rule an archive member passes (`UpdateApplier::claim()`), so an edited index
cannot point it at `data/` or outside the roots.

It moves each recorded path from the state the push left to the state before it, **and from nowhere
else**. A path holding neither the pushed bytes nor the earlier ones — a full deploy since, a hand
edit, a link — refuses the whole rollback before anything is written, and so does a saved copy whose
digest no longer matches. A path already back in its earlier state, a write the push never landed,
is left alone. Then what the mirror deleted is recreated, what the push changed is restored in the
order it was written, each through `File::write()`, and what it added is removed only if every
restore landed — by `File::delete()`, one named path at a time, with `rmdir()` for only the
directories those removals emptied. A rollback that completes clears the record, so a second is a
`422` saying there is nothing to roll back; one that fails partway keeps it, answers `500`, and can
be run again. It is one step back and never two, because the record holds one release.

### Measuring the host

`update v1 probe` is the third action that writes, though all it leaves behind is nothing. Like a
rollback it carries no body and one manifest field, `apply`. A real one takes the push's lock and
spends a serial, so it never runs beside a push and a captured one cannot be replayed into a stream
of directories on the server; a dry run only names the directory it would use.

It works in `.update-probe-<pid>-<random>/` beside the roots in `App::above()` — the filesystem a
push moves files on, which `sys_get_temp_dir()` may not be, and outside every root, so the mirror
never walks it. What it measures is what a push that stages its tree and swaps it in would rest on,
and none of it can be read off a shared host's manual:

| Line | What it measures |
|---|---|
| `devices` | whether the webroot and `sys_get_temp_dir()` are on the deployment's device |
| `free space` | what the filesystem says is free, and in all — perhaps the export's, not the account's quota |
| `file rename` | a file renamed within a directory — what every push already does |
| `directory rename` | a directory holding a file renamed, and how long it took |
| `with a file open` | the same while a file inside is held open: whether the handle still reads, and how many `.nfs` strays appeared |
| `over an open file` | a file renamed over one held open — a push rewriting `index.php` — counting strays while it is open and after it closes |
| `onto an empty dir` · `onto a full dir` | a directory renamed onto an existing one, which POSIX allows only when that one is empty |
| `swap window` | two directory renames, the live tree aside and the staged one in, and how long nothing was at the name |
| `hard link` · `symbolic link` | whether either can be made there |
| `left behind` | `nothing`, or the directory the host would not let go |

**Every step answers and none throws**: a refusal is that step's answer, in PHP's own words, so a
host that refuses half of it still reports the other half. Each step takes away what it made before
the next begins — named paths, `unlink()` and `rmdir()`, never a walk — and a probe that could not
make its directory, or could not remove it, answers `500`. Its lines are facts, not verdicts, in
`capability`'s format: whether a swap window is short enough is for whoever designs the swap.

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
