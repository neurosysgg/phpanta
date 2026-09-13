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
unqualified name its namespace resolves — breaks the moment it is used anywhere else. neuro.SYS's
`BoundaryTest` resolves every name the framework's code writes and fails on any site class, and its
verify script fails on any mention of the site's namespace anywhere under `phpanta/`, comments and
docs included. A site's facts reach the framework through the app, never the other way round.

## Stack

Plain PHP 8.5 / HTML / CSS, **no runtime dependencies**. PHP ≥ 8.5 is load-bearing three times: the
pipe operator in `autoload.php`, `#[\NoDiscard]` on the copy-returning builders, and `ext/uri`, the
WHATWG and RFC 3986 parsers `Element` and `Request` put their URL questions to. The runtime also
needs `ext/dom` (`MarkupParser`), `ext/intl` (`MessageFormatter`), `ext/openssl` (`PublicKey`, the
API's signature check) and `ext/zlib` (`UpdateApplier`'s `gzdecode()`); `ext/curl` is tooling only.
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
- **A method that ends the request has a public twin that decides it** — `Auth::accepts()`,
  `SecurityHeaders::headers()` — so the decision can be asserted.

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
- A `{placeholder}` compiles to `[^/]+` and matches an unparseable target too.
- A misspelled `ServerVariable` or `DataFileName` is not an error but a default.

**The API and deploying**
- **`data/update.pub` absent means `/api` is off; `data/site_auth.php` absent means the site gate is
  off.** The two files look alike and have opposite polarity.
- `public/api/` must never exist, and an API action never reads a query parameter.
- A write spends its serial **before** applying; a dry run and a read never spend one.
- `App::webroot()` takes only `DOCUMENT_ROOT`'s basename and refuses a blank, relative,
  outside-the-deployment or nonexistent root.
- The mirror is an enumerated delete: it never follows a symlink and never calls `Directory::remove()`.
- **The server writes a push in the order it is packed**: the framework, the site's source, the
  autoloader, the webroot, the stamped manifest last.
- A push packs `phpanta/` out of the working tree, so `push-update` refuses a framework that is not
  checked out, has changes that are not committed, or is not the commit the site's `HEAD` records.
  `--any-framework` is the deliberate way past; a copied-in `phpanta/` has nothing to compare and passes.
- Rewriting a file the request is executing makes NFS silly-rename it into an undeletable
  `.nfsXXXXXXXX`; a push leaves byte-identical files untouched for that reason.
- A health check **returns** its 503, and `Requirement::check()` never throws.

**Front end and builds**
- The build tools find the project from where they are **run**, not from where they sit — which is
  inside `phpanta/`. The dev router and the coverage prepend take it from `DOCUMENT_ROOT`.
- A bundled class name needs **both** esbuild `keepNames` and terser `keep_classnames`.
- Never cache-bust with `?v=` on an import specifier; the build stamp is a path segment.
- The site reaches `assets/ts/` through a symlink; without `preserveSymlinks` it compiles outside
  `rootDir` and refuses.
- **An export's base path is applied to files, not at runtime**: HTML attributes and stylesheet
  `url()`s are rewritten, scripts are not. A script that builds an address from the root breaks
  under `--base`; build it from a link on the page instead. The export fails on any address it
  finds without the base, and on any link to a file it did not write.
- A static host answers `Navigation`'s fetch with the whole page, not a fragment; `Navigation` takes
  `#content` and the title out of it, and hands a page with no `#content` back to the browser.

## Commands

```bash
vendor/bin/phpunit -c phpanta/phpunit.xml.dist   # the framework's suite, under TestApp
node phpanta/tools/build-assets.mjs              # run from the site's root
php -S localhost:8080 -t public phpanta/tools/dev-router.php
php phpanta/tools/export.php --out <dir> [--base /path/] [--debug]   # a static copy of the site
```

Checked out on its own, `composer install`, `npm install`, then `vendor/bin/phpunit`, `npm run
check`, and `npm run site:build` / `site:dev` / `site:prod` / `site:export` for its own site in
`site/` — a site like any other, whose framework is `..` rather than `phpanta/`. GitHub Pages
serves its export; `.github/workflows/pages.yml` runs the suite first and publishes on every push.

## Documents

| Document | Read before touching |
|---|---|
| [docs/architecture.md](docs/architecture.md) | the request, the app, the layers, exceptions, the markup tree |
| [docs/collections.md](docs/collections.md) | anything that holds a group |
| [docs/guidelines.md](docs/guidelines.md) | a bare array, a bare string, an `array_*` call, an `@`, a `throw` |
| [docs/language.md](docs/language.md) | any visible word, `Translation`, `Languages` |
| [docs/frontend.md](docs/frontend.md) | the build, the element model, SPA navigation |
| [docs/security.md](docs/security.md) | headers, the method gate, the guards, the API |
| [docs/health.md](docs/health.md) | `health` and `capability`, or a requirement to declare |
| [docs/testing.md](docs/testing.md) | the framework's suite and `TestApp` |
| [docs/tooling.md](docs/tooling.md) | the CLI layer, the signed commands, the build tools |
| [docs/history/](docs/history/README.md) | nothing — it is how things got this way |
