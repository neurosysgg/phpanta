# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this
repository — Phpanta, the framework, whether it is checked out on its own or vendored into a site
as `phpanta/`.

It is the short layer: what the framework is, the rules it keeps, the traps that fail silently, and
the commands. Each section ends in the document that carries the argument — read that before
changing what the section describes. How any of it got this way is in
[`docs/history/`](docs/history/README.md), and none of it is needed to change the code safely.

**Write docs the same way.** Present tense in `docs/`, past tense in `docs/history/`. When a rule
exists because of a story, the current document states the rule in one sentence and links the story.

## The boundary

**Nothing under this directory names a site.** A second site built on Phpanta has none of the first
one's classes, so a framework file that reaches one — by an import, a qualified name, or an
unqualified name its namespace resolves — breaks the moment it is used anywhere else. `test/unit/BoundaryTest.php`
holds that line from inside: it resolves every name the framework's code writes, and fails on
anything that is neither the framework's, PHP's own nor (in tools and tests) composer's. A vendoring
site holds it from outside too, with a verify script that fails on any mention of the site's
namespace anywhere under `phpanta/`, comments and docs included. Every `{@link}` lands on a framework class
or PHP's own, and every link in these documents stays inside this directory, so an example borrowed
from a site fails instead of dangling. A site's facts reach the framework through the app, never
the other way round.

## Stack

Plain PHP 8.5 / HTML / CSS, **no runtime dependencies**. PHP ≥ 8.5 is load-bearing three times: the
pipe operator in `autoload.php`, `#[\NoDiscard]` on the copy-returning builders, and `ext/uri`, the
WHATWG and RFC 3986 parsers `Element` and `Request` put their URL questions to. The runtime also
needs `ext/dom` (`MarkupParser`), `ext/intl` (`MessageFormatter`), `ext/openssl` (`PublicKey`, the
API's signature check, and `SessionSeal`), `ext/zlib` (`UpdateApplier`'s `gzdecode()`) and `ext/mbstring` (`Input`'s
UTF-8 check — every form read); `ext/curl` is tooling only.
Composer and npm are dev tooling; nothing on the PHP side is built, and composer never runs on the
server.

## The app

`Phpanta\App` is what a site tells the framework about itself, and the one place it is told.

- **One per process, booted by the site's autoloader** and read back with `App::current()`.
  Constructing one does nothing — no `DOCUMENT_ROOT`, no file — so booting is free; booting the same
  class twice is booting it once; a second class is refused.
- **Nothing caches what an app answers.** A test that needs a different answer passes a different
  object to the class that asks — `UpdateApplier` takes its `Deployment` — rather than swapping the
  booted app.
- **`above()` is the directory holding the site's `autoload.php`**, whatever the framework's own files
  sit in. The update serial and the push's mirror hang off it; a wrong answer moves the serial (a
  replay window, in silence) or points the mirror at the wrong tree.
- **What the framework derives from an app is final** — `data()`, `webroot()`, `updateSerial()`,
  `errorLog()`, `requirements()`, `routeTable()` — because each derivation was measured into its shape.

See [docs/architecture.md](docs/architecture.md#the-app).

## Rules

Each of these replaces a habit that fails silently with one that fails loudly.

- **Nothing builds HTML from a string.** A view returns a `Node`; only `Element` and `Doctype` write
  a `<`. Escaping and the URL-scheme check both live in `render()`. [architecture.md](docs/architecture.md#the-markup-tree)
- **Hand-authored HTML enters only through `Element::containingHtml()`**, which parses it against the
  app's `Vocabulary` and refuses the rest. Never parse anything a request can influence.
- **Visible text is a `Translatable`, and a view never names a language.** A catalog is an enum using
  `Translated`, a case carries `#[Translation(en: …, de: …)]`, and the tree puts it into the nearest
  `lang` when it renders. [language.md](docs/language.md)
- **Names and values are typed.** A header is a `HeaderName` case and a `HeaderValue`; an attribute
  is an `AttributeName` case; a value with a grammar is a class, a fixed vocabulary is an enum.
- **A group crossing a public boundary is a `Collection` or `SearchableCollection`** — immutable,
  lazy, and not a replacement for a variadic. [collections.md](docs/collections.md)
- **Five habits are refused**: a bare `array` in a declared type, a bare string that is really a name,
  an `array_*` call a collection has a member for, `@`, and an SPL exception. The first three may be
  excused with an attribute carrying a reason — `#[BareArray]`, `#[BareString]`, `#[BareCall]`.
  [guidelines.md](docs/guidelines.md)
- **Exceptions live in `Phpanta\Exception`, implement `SiteException`, and extend the SPL class they
  replace.** A site's own exceptions implement the same marker.
- **Builders and queries carry `#[\NoDiscard]` with a message.** A deliberate discard is `(void)`.
- **Every address is a `Path` case**, and a link is `->to(…)`, never a concatenation.
- **A data file is `App::current()->dataFile(X)`, a `File`**, named by a `DataFileName` case. A
  suppressed diagnostic is `Diagnostics::muted()`, never `@`.
- **What stands around a controller is listed, never discovered.** An app's layers are
  `App::layers()`, and the framework adds none of its own; a route's are `->through(…)`, and run
  only past its method gate. A gate belongs on the route, not in the controller and not around the
  app. [architecture.md](docs/architecture.md#layers)
- **Nothing ends the request but `App::run()`; every decision returns.** A response's `answer()`
  is an `Answer`, `App::handle()` answers a whole request without sending it, and a gate's refusal
  is a value it returns, `#[\NoDiscard]` — `Auth::adminGate()` — which the caller returns in turn. A test builds the request with `Phpanta\Test\TestRequest`. Never `exit`.

## Traps

These fail silently — no error, no log, a page that looks fine.

**PHP**
- `FillsPlaceholders::to()`'s filler must be `function () use (&$values)`. `fn()` captures by value,
  so every placeholder takes the first value — a URL that is well formed, matches a route, and is wrong.
- `!== []` is true of every `Collection`; ask `isEmpty()`.
- A mapped `SearchableCollection` keeps its string keys, and string keys spread as named arguments;
  spread `toValues()`.
- Never decide a URL is on-site by "starts with a slash": `/\r\n/host` is `https://host` to a
  browser. `Element::staysOnThisOrigin()` resolves it with the WHATWG parser.
- `File` never creates a directory; that is `Directory`'s job and the caller's decision.
- A regex that validates ends in `\z`, not `$` — `$` matches before a trailing newline.
- A page that reads a request header declares it in `View::varyOn()`, or a cache hands one visitor
  the copy built for another.

**Language**
- `Translatable` is asked before `BackedEnum`: a catalog case is both, and read as an enum it renders
  its key. A translatable with no `lang` above it throws — render with `->render(0, $language)`.
- A language the framework knows and an app does not offer is answered as if it were nothing.

**Requests and auth**
- Never `parse_url()` the request target: it fails with `false`, which `??` does not guard.
  `Request::path()` uses `Uri\Rfc3986\Uri::parse()`, which answers null.
- A target opening with `//` is a path, and `Uri::parse()` reads one as an authority — `//x/posts`
  as the page at `/posts` — so `Request` never hands one to the parser.
- An untyped `{placeholder}` compiles to `[^/]+` and matches an unparseable target too; a typed
  one, `{id:int}` or `{tag:slug}`, matches only its kind, and `to()` refuses a value it would not.
- Behind a compressing module, the `ETag` a browser echoes has `-gzip` inside the quotes;
  `ETag::matches()` drops it, and a verbatim compare never answers a 304.
- A misspelled `ServerVariable` or `DataFileName` is not an error but a default.
- **A multipart body is PHP's parse, not ours.** `MultipartParameters` is the one reader of `$_POST`
  and `$_FILES`; a name with a dot, a space or a bracket arrives renamed, a plain name sent twice
  keeps its last value, and a body over `post_max_size` arrives empty — which `Request::form()`
  turns into a 413 from the sender's `Content-Length`, rather than a form that sent nothing.

**The API and deploying**
- **Every credential file fails closed: `data/update.pub` absent means no signed call verifies and
  no device can be enrolled; `data/admin-passkeys.json` absent means no device is enrolled** — per
  deployment, written only by `access v1 enrol` and `revoke`, and never shipped. Keep it that way:
  a misspelled case reads as absent.
- **Passkeys are off unless the app names its origin** (`App::origin()`, never `Host`) **and the
  deployment has `data/session.key`.** In development from loopback only, the request's `Origin`
  comes first — before the app's — so a local copy runs a real ceremony where it is served. Otherwise the entrance says browsers cannot sign in, and nothing fails.
  `data/throttle/` missing makes the entrance a `503`.
- An unlocked session is re-checked against the passkey store on every request, so a revocation or
  a lock takes effect on the next one — a lock ends every session that passkey unlocked, copies of
  the cookie included. **A sealed session can be copied, so what must happen once is recorded on the
  server**: an unlock in the store, under the store's own lock; a browser write's tap as its serial,
  the moment its challenge was minted. A browser write's tap binds `POST <path>`, not the field
  values — those are held by the form token and the session.
- **The admin negotiates, then verifies, then resolves.** A caller it cannot verify gets one answer
  at every depth below `/admin`, whether the address exists or not — a `303` for a page, a `401`
  challenging for `NS1` for data, never an `Allow`. An answer that differs for a real address tells
  a stranger what is in it, and looks like nothing at all.
- `public/admin/` must never exist, and an admin action never reads a query parameter or a form
  field — `InputTest` reads the API's code and fails on either. A browser's form is read by
  `AdminBrowser` alone, into a manifest of the fields the action declares.
- A write spends its serial **before** applying, under a lock it holds to the end; a second write
  meanwhile is a 409 that spends nothing. A dry run and a read never spend one.
- `App::webroot()` takes only `DOCUMENT_ROOT`'s basename and refuses a blank, relative,
  outside-the-deployment or nonexistent root.
- The mirror is an enumerated delete: it never follows a symlink and never calls `Directory::remove()`.
  It sweeps only the directories it emptied, under a root the payload carries, and nothing after a
  failed write.
- A signing key belongs to one deployment: `ApiTarget` refuses the default key for any other origin.
- **The server stages every changed file beside the roots, then renames them in the order they are
  packed**: the framework, the site's source, the autoloader, the webroot, the stamped manifest last.
  A destination that cannot take a file refuses the push while staging, with nothing live written —
  which is what a file turned into a directory, or a directory into a file, meets: that change is a
  full deploy's.
- A push packs `phpanta/` out of the working tree, so `push-update` refuses a framework that is not
  checked out, has changes that are not committed, or is not the commit the site's `HEAD` records.
  `--any-framework` is the deliberate way past; a copied-in `phpanta/` has nothing to compare and passes.
- Rewriting a file the request is executing makes NFS silly-rename it into an `.nfsXXXXXXXX` that
  lives as long as the handle; a push leaves byte-identical files untouched for that reason. The name
  is the client's: no payload may carry it, and the mirror notes a stray rather than failing on it.
- `update v1 probe` measures what the deployment's filesystem lets a push do, in a scratch directory
  beside the roots it removes again. It is a write — the lock and a serial — and answers facts only.
- A health check **returns** its 503, and `Requirement::check()` never throws.
- **`data/session.key` is per deployment and never ships.** Nothing asks for it until something keeps
  a session; then its absence is a loud refusal, never a session sealed under something made up —
  except at the admin, which then lets no browser in. A session cookie that does not open is no
  session, not an error. `CsrfGuard` and `LoginGate` go on routes, never on the app — as app layers
  they would stand in front of the admin, refusing a signed write for the form token it does not
  carry and answering a stranger with a login page. `AdminGate` likewise: in front of the admin it
  would take the one `Authorization` header a signed call needs. The admin checks a browser's token
  itself.
- **A trace is shown only in development, and only to loopback.** Development is the server
  variable `PHPANTA_ENVIRONMENT=development`, exactly — `SetEnv` in a vhost, or the dev router for
  `php -S`, which hands its own environment to nothing. Any other value, a capital included, is
  production, and `health v1` warns on a deployment that says development.

**Front end and builds**
- The build tools find the project from where they are **run**, not from where they sit — which is
  inside `phpanta/`. The dev router and the coverage prepend take it from `DOCUMENT_ROOT`.
- A bundled class name needs **both** esbuild `keepNames` and terser `keep_classnames`.
- A site's entry script calls `Passkey.start()` **unconditionally**: a passkey form can arrive with a
  `Navigation` swap after the script ran, so it listens at the document rather than for the forms
  it finds at start.
- Never cache-bust with `?v=` on an import specifier; the build stamp is a path segment.
- The site reaches `assets/ts/` through a symlink; without `preserveSymlinks` it compiles outside
  `rootDir` and refuses.
- **An export's base path is applied to files, not at runtime**: HTML attributes and stylesheet
  `url()`s are rewritten, scripts are not. A script that builds an address from the root breaks
  under `--base`; build it from a link on the page instead. The export fails on any address it
  finds without the base, on any link to a file it did not write, and on any link to an anchor
  that page does not have.
- A static host answers `Navigation`'s fetch with the whole page, not a fragment; `Navigation` takes
  `#content` and the title out of it, and hands a page with no `#content` back to the browser.
- `Navigation` owns the scroll: it sets `history.scrollRestoration = 'manual'` and scrolls after the
  swap, so a `phpanta:navigate` subscriber that scrolls is overridden. Every fallback is
  `location.replace()` — `assign()` would leave the failed entry behind for back to land on.
- A part of the shell written in the page's language needs `data-language-bound`, or a navigation
  into another language leaves it in the old one. A language switch belongs inside `#content`: in
  the shell, a swap within one language would leave it naming the previous page.
- A `Sentence` is not ICU: a brace outside a `{name}` placeholder is refused, and one the prose needs
  goes inside a part. In a `Suffixed` app `Request::path()` has no language suffix, while
  `canonicalTarget()` keeps it.

## Commands

```bash
vendor/bin/phpunit -c phpanta/phpunit.xml.dist   # the framework's suite, under TestApp
node --test 'phpanta/test/js/*.test.mjs'         # its mirrored TypeScript enums against the PHP
node phpanta/tools/build-assets.mjs              # run from the site's root
php -S localhost:8080 -t public phpanta/tools/dev-router.php
php phpanta/tools/export.php --out <dir> [--base /path/] [--debug]   # a static copy of the site
```

Checked out on its own, `composer install`, `npm install`, then `vendor/bin/phpunit`, `npm run
check`, `npm test`, and `npm run site:build` / `site:test` / `site:dev` / `site:prod` / `site:export` for its own site in
`site/` — a site like any other, whose framework is `..` rather than `phpanta/`. GitHub Pages
serves its export; `.github/workflows/pages.yml` runs the suite first and publishes on every push.

## Documents

| Document | Read before touching |
|---|---|
| [docs/architecture.md](docs/architecture.md) | the request, the app, the layers, exceptions, the markup tree |
| [docs/collections.md](docs/collections.md) | anything that holds a group |
| [docs/guidelines.md](docs/guidelines.md) | a bare array, a bare string, an `array_*` call, an `@`, a `throw` |
| [docs/language.md](docs/language.md) | any visible word, `Translation`, `Languages` |
| [docs/frontend.md](docs/frontend.md) | the build, the element model, SPA navigation, the passkey forms |
| [docs/security.md](docs/security.md) | headers, the method gate, the guards, the admin — its signed calls and its passkeys |
| [docs/login.md](docs/login.md) | a login page — the recipe that puts `Form`, `Session`, the two guards and `Login` together |
| [docs/health.md](docs/health.md) | `health` and `capability`, or a requirement to declare |
| [docs/data.md](docs/data.md) | `Phpanta\Data` — a database, a statement, a row, a migration |
| [docs/testing.md](docs/testing.md) | the framework's suite and `TestApp` |
| [docs/tooling.md](docs/tooling.md) | the CLI layer, the signed commands, the build tools |
| [docs/history/](docs/history/README.md) | nothing — it is how things got this way |
