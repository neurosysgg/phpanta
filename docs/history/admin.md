# History — the admin

How the admin in [../security.md](../security.md#the-admin) came to be the way it is. The signed
endpoint is older than the framework's name, and its first form — one address becoming a family of
them, the credential moving into `Authorization`, the serial, the mirror — stays where the
[README](README.md) says the rest of that history is.

## `/api` becomes `/admin`

### 2026-09-14 — an address that says it is there, and nothing about what is in it

*From security.md's "The API", "It answers as though it is not there" and "What a verified caller
gets back", and CLAUDE.md's "The API and deploying".*

The signed address family moved from `/api/{service}/{version}/{action}` to `/admin`, at four
depths — the entrance, a service, a version, an action — and the three above an action came to list
what is under them. `ApiPath` became `AdminPath`, with a case per depth, and `App::apiRoute()` became
`App::adminRoutes()`. Nothing answers under `/api` any more, and there is no alias.

It was asked for: an admin a person can find and walk, a page by default and data on request, where
the old family could be walked by nobody, the key holder included, without knowing every address in
advance. And the property that family was built around had stopped paying for itself. The
framework's source is public, and a site may link to its admin, so whether one exists is not a
secret anybody can keep; what a stranger must not learn is what is in it. **So indistinguishability
from an absent address was given up for uniformity inside the admin**: one answer at every depth
below the entrance, whether the address exists or not. Negotiation moved ahead of the gate with it,
because a stranger's one answer now depends on what they asked for — a `303` for a page, a `401`
for data — where before it was the same `404` whatever they named.

The paragraph introducing the family ended:

There are no aliases: an endpoint whose design is to be unfindable does not want two doors.

and a new service was said to inherit "the silence, the method policy, the key, the serial rule and
the indistinguishability without a line arranging any of them". The posture it gave up stood as:

**It answers as though it is not there.** An unsigned request gets **exactly** what the site gives
for an address that does not exist: the app's `404` for a read method, the `text/plain` `405` with
`Allow: GET, HEAD` for anything else, and the same for a verb the framework does not recognise. Not
a `401`, which would prompt; not a `403`, which would confirm; not a `405` naming `POST`, which would
confirm more precisely.

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

The negotiation was placed the other way round: **that question is asked after the gate and before
the action**, so an unverified caller is never answered differently for what it named, and a write is
never carried out for a caller who then could not be told how it went.

CLAUDE.md's traps said `data/update.pub` absent meant `/api` was off, and that `CsrfGuard` and
`LoginGate` go on routes because as app layers "they would tell an absent address from the API".

## A browser in the admin

### 2026-09-14 — passkeys, enrolled by the signing key

*From security.md's "The admin", "What a verified caller gets back" and "Sessions, the form token and
the login", and CLAUDE.md's "The API and deploying".*

The admin came to let a browser in, where before only a signed call got past its entrance. It was
asked for with the move to `/admin`: a page by default, opened by something built into the browser
rather than a password.

**A client certificate was the first idea, and could not work.** A shared host that ends TLS at its
front proxy — the kind whose redirect has to ask `X-Forwarded-Proto` — never shows the handshake to
Apache or PHP, and `.htaccess` has nothing to request one with. The browser credential that does
work over plain HTTPS is WebAuthn, and it has the property the signing key has: the server keeps only
a public key. Its default algorithm, ES256, is exactly what `PublicKey::verifies()` already checked,
and a registering browser's `getPublicKey()` hands over SPKI DER, so it needed no new cryptography
and no CBOR reader.

**Sodium was weighed and left out.** It would have allowed Ed25519 passkeys, mostly a hardware-key
option; Ed25519 for `NS1`, whose signer already had a proper RNG; and XChaCha20 for the session seal,
whose nonce size mattered at no scale this has. None closed a real hole, and each would have added a
floor and an extension the host had and the local runtimes did not. One family, P-256 through
openssl, served both callers, and the client asks for ES256 alone so a key sodium would be needed for
is never made.

Four decisions shaped the rest:

- **Enrolment is rooted in the signing key.** Registering at the entrance stores nothing and earns a
  sealed code; only the signed `access v1 enrol` turns it into a device, so no unauthenticated
  request ever writes to the server and a browser never vouches for itself.
- **One challenge for both of the entrance's ceremonies**, so the page holds one pending challenge
  in the session, spent by whichever answers it.
- **A passkey tap for every browser write**, with `update v1 patch` and `access v1 enrol` kept for
  the signing key. The plan bound each tap to a hash of the write's fields; it was built bound to
  `POST <path>`, with the fields held by the form token and the session.
- **The form token checked by the admin, not by `CsrfGuard`** — the plan had put the guard on the
  route, where it would have refused every signed write.

The client was planned as a custom element, `<admin-passkey>`, posting with `fetch`. It became a
plain module that holds a real form's submit and sends it with the same button, and `data-passkey`
became an attribute enum of its own rather than a `RegionAttribute` case. It is started
unconditionally, because the admin's pages arrive by `Navigation` swaps after the entry script has
run. Passkey autofill was deferred; the unlock is one button.

security.md said of a verified caller:

Today the only caller the gate verifies is one whose `NS1` signature checks out, so a browser, which
cannot sign, sees the entrance and nothing else.

and of the entrance, that it was "a `200` that says only that everything there needs a credential".
`ApiAction::fromBrowser()` was "false only for `update v1 patch`". CLAUDE.md's trap read:
`data/update.pub` absent means the admin lets nobody past its entrance.

## The pre-launch gate goes

### 2026-09-14 — nothing of the framework's stands around every request

*From architecture.md's "③ The pre-launch gate" and "Layers".*

`App::layerTable()` put the framework's `SiteGate` first, ahead of anything a site listed, so that no
site could forget it or list something ahead of it. A fresh-eyes review found the cost: standing
first on every request, it stood in front of `/admin` too, and while `data/site_auth.php` existed no
signed call could pass — `NS1` and Basic share the one `Authorization` header. It was removed rather
than taught to stand aside for a verified signature, since a site that wants a password on its
pages has `LoginGate`, on the routes it guards. With it went the one credential file whose absence
was the open state.

architecture.md said:

> [`SiteGate`], the first of the app's layers, asks `Auth::siteGate()`, which checks for
> `data/site_auth.php`. Refusing, it returns the `401` as a response, which is then the answer in the
> router's place. If the file is absent it returns null immediately — *that absence is how the gate
> is switched off*, and a site gitignores the file precisely so the repository's copy cannot switch it
> on. It is also why a misspelled `DataFileName` case there would not fail but stand the gate down.
