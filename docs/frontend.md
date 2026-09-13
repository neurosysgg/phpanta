# Front end — the framework

The build that turns a site's TypeScript and CSS into what ships, the guards every element can
lean on, and SPA navigation. A site's own elements and stylesheet are its own, and are documented
with it.

## The build

```
assets/ts/  ──tsc──────────────────────────────→  public/assets/js/               ← the debug tree: generated, committed
assets/css/ ──phpanta/tools/build-css.mjs──────→  public/assets/css/style.css     ← generated, committed
both        ──phpanta/tools/build-assets.mjs───→  src/<Site>/AssetManifest.php    ← generated, committed
public/     ──phpanta/tools/build-prod.mjs─────→  build/dist/                     ← the prod tree: generated, gitignored, deployed
```

Every tool is run from the site's root. The project is the nearest directory, at or above the one a
tool is run from, that holds a `composer.json`. It is never the directory the tool sits in, because
that directory is inside `phpanta/`.

| Tool | Does |
|---|---|
| `tsc` | the site's own `tsconfig.json`: compiles `assets/ts/`, the framework's modules included, into the debug tree |
| `node phpanta/tools/build-css.mjs [--out <file>]` | inlines `assets/css/main.css`'s `@import` list into the stylesheet; `--out` writes somewhere else, for a drift check |
| `node phpanta/tools/build-assets.mjs [--js-dir <dir>] [--css <file>] [--graph-dir <dir>] [--bundle <file>] [--out <file>]` | stamps the built assets and writes the manifest. It runs last, because it hashes both outputs |
| `node phpanta/tools/build-prod.mjs [--out <dir>]` | derives `build/dist/` from a current `public/`. See *Debug and prod* |
| `php -S localhost:8080 -t public phpanta/tools/dev-router.php` | the dev server. The router is not optional. See *Cache versioning* |
| `php phpanta/tools/export.php --out <dir> [--base /path/] [--debug]` | a static copy of the site. See [tooling.md](tooling.md#the-static-export) |

**A site wires these into its own `package.json`.** It usually has a build script that runs `tsc`,
`build-css` and `build-assets` in that order, and a prod script that runs the build and then
`build-prod`. It also has a dev script for the server, and whatever runs its tests against the
compiled tree. A `tsc --watch` on its own rebuilds neither the stylesheet nor the manifest.

Phpanta's own `package.json` is for a checkout of Phpanta on its own, and a vendoring site never runs
it. Its scripts cover the framework and the framework's own site in `site/`:

| Script | Does |
|---|---|
| `npm run check` | `tsc` over the framework's `assets/ts/` under its own [`tsconfig.json`](../tsconfig.json), emitting nothing |
| `npm run site:build` | in `site/`: `tsc`, then the stylesheet, then the manifest |
| `npm run site:prod` | `site:build`, then the prod tree |
| `npm run site:dev` | the dev server for `site/`, on port 8081 |
| `npm run site:export` | exports `site/` to `build/pages/` under `--base /phpanta/` |

`composer test` runs the framework's PHPUnit suite. See [testing.md](testing.md).

**Never hand-edit `public/assets/js/`, `public/assets/css/style.css` or
`src/<Site>/AssetManifest.php`** (the manifest sits under the site's own namespace). They are build
output, and the next build overwrites them. The generated stylesheet carries a marker comment above
each block naming the part it came from. Edit that part.

### Why the output is committed

Nothing builds on the server. A site's deploy ships `public/` as it stands in the working tree, so a
forgotten rebuild would ship stale JS or a stale stylesheet, and nothing else would notice. A site's
suite therefore rebuilds all three outputs and diffs them, so a drifted output is a failing test.

The build tools keep that check affordable on any clone. They have no dependencies and nothing runs
on import, so `build-css` and `build-assets` work on a clone that has never seen `npm install`. Only
the TypeScript half needs `node_modules`.

### Debug and prod

`public/` is the **debug** tree, and it is not what ships. It stays committed, readable and
unbundled because a site's checks read it by path. Client tests import its modules. A coverage gate
is pinned to `public/assets/js/**`. A drift check diffs it byte-for-byte against a fresh `tsc`. All
three want output a person can read, which is also why the cache version is a path segment and not
a rewritten specifier (see [Cache versioning](#cache-versioning)).

`build-prod.mjs` derives the **prod** tree from it. It copies `public/` wholesale, bundles the whole
module graph into one file with esbuild, minifies that file with terser, deletes every source map,
and writes a manifest of its own:

```
build/dist/public/                        ← byte-for-byte what lands in the webroot
build/dist/src/<Site>/AssetManifest.php   ← the same two URLs under a different stamp, and no
                                            preloads: one file has no graph left to hint at
```

Three things change, and each is only worth doing at the edge:

- **The maps go.** A map is several times the size of the JS it describes, because `inlineSources`
  puts the whole commented TypeScript inside it. The web server serves static assets directly, past
  any gate the app keeps, so on a live host every map would be a public file: a second copy of the
  source, served from the one place it has no business being.
- **The graph is bundled.** This is the change that pays for the rest. One response replaces one per
  module, and gzip's window then spans the whole graph instead of restarting at every small file. No
  document carries a preload list either.
- **The JS is minified.** This earns a little on top of the bundle, since gzip over one stream
  already captures most of what identifier mangling would.

**Class names have to survive minification, and that takes both tools.** `NestedElement.tagOf()`
falls back to `constructor.name`, and that name is the text of the error a misnested tag throws. It
is the whole reason those classes are not empty. terser's `keep_classnames` protects only a
*declared* class name. Bundling rewrites some `class X extends Y {}` declarations into
`var X = class extends Y {}`, whose name is inferred from the binding. Without esbuild's `keepNames`
as well, `<card-title> must be inside <post-card>` reads `must be inside <P>`. The `__name` helper
that `keepNames` needs is emitted once in the bundle, for about 256 gzipped bytes.
`mangle.properties` stays off, because `connectedCallback` and `observedAttributes` are contracts
with the browser rather than with us.

**The manifest's stamp differs between the two trees, and that is correct.** A stamp is a claim
about content, and the two trees are different bytes at the same URLs. A push ships the prod
manifest with the prod tree. A deploy that uploads `src/` from the working tree has to put the prod
manifest over the one file that differs.

**Every failure here is invisible in a browser until it is live**, so a site's suite should build
the prod tree and check it:

- it ships no map and names none;
- its manifest points at the same entry and stylesheet as the committed one;
- its manifest preloads nothing;
- the URL its manifest names has bytes behind it;
- the **whole client-side suite passes again against the shipped bytes**.

Do not diff the two manifests against each other. The debug one lists every preload and the prod
one lists none, so a diff would assert away the thing the build exists to do. The re-run is the
check worth the most. It works across a bundle only if the tests reach the elements through one
import of `main.js` and the DOM, never by module path. A test that imports a module directly is tied
to the debug tree, whatever the prod tree ships.

`build-prod.mjs` refuses three things:

- a shipped tree holding anything but the one bundle;
- a bundle that still names a relative import, which would mean esbuild resolved nothing and every
  module the entry asks for was deleted with the rest of the copy;
- an `--out` that is the project, above it, or inside `public/`, since the tool empties its output
  first.

The first two exist because a prod tree that silently is not bundled still works, just bigger, and
shows no other symptom.

**`build-assets.mjs` takes a `--graph-dir`.** Its import scanner is anchored to whole lines on
purpose, because a looser match walks out of a line and into string literals. terser puts an entire
module on one line, so walking the minified tree would find no imports at all. The graph is a
property of the sources rather than of the formatting. So the tool walks the readable tree to learn
*which files import which*, and reads the shipped tree for *what is in them*.

### Preloading the module graph

This applies to the debug tree. The prod tree has no graph left to preload.

A browser discovers an ES module graph one wave at a time. It learns what a module imports only
after fetching and parsing it, so a graph *n* modules deep costs *n* sequential round trips before
the last module starts downloading. None of that cost is bytes, so compressing and stripping
comments leave it exactly where it was.

`build-assets.mjs` walks the compiled graph from `main.js` and writes every module it reaches into
the manifest. A site's `Shell` renders one `<link rel="modulepreload">` per entry. The links go after
the stylesheet, because the stylesheet blocks rendering and these do not. The preload scanner then
sees every module at once, and all the waves become one. A module that no module imports is not
preloaded, since the walk never reaches it. A coverage gate run with `--test-coverage-include-all`
still sees it. The framework contributes eleven modules to a site's graph: `Navigation`,
`NestedElement`, and nine mirrored enums under `model/`.

The hint is `modulepreload` rather than `preload as="script"` because it fetches, parses, compiles
*and* inserts into the module map, so the module is instantiated by the time `main.js` asks. The list
names every module rather than only the first wave. The spec lets a browser follow a preloaded
module's own imports, and Chrome does, but no browser is obliged to and Safari has been uneven, so
leaning on it would make the fix silently partial. `main.js` is deliberately absent, because it is
the `<script src>` already in flight.

There are two ways this goes wrong, and a site's suite needs three checks to catch both:

- **A stale list.** A module missing from it brings its whole subtree's waterfall back. Rebuild the
  manifest and diff it.
- **A list that points at nothing.** The list can be perfectly in step with the graph and still name
  URLs with nothing behind them, because the URL base is written by hand in the tool. Ask the server
  for every hinted URL.
- **The same existence question in the fast suite.** Ask the filesystem, so the check fails without
  a server running.

**Neither failure is visible in a browser.** The page still works, just more slowly, which is why all
three checks exist.

### Cache versioning

Every built asset is served under a path segment naming a hash of the build:
`/assets/js/v-a1b2c3d4/main.js`. That segment is what lets a site mark those assets
`immutable, max-age=31536000`. A URL that names its own content cannot come to mean something else,
so a returning visitor fetches none of it again. The segment is not a directory, and the server
strips it. A site on Apache does that with a `RewriteRule` in `public/.htaccess`. The framework ships
no `.htaccess`, since the rule belongs to whatever serves the site. The dev server strips it through
`phpanta/tools/dev-router.php`.

**Why a path segment and not a filename or a query.** A name like `Thing.a1b2c3d4.js` would break
every test that imports `public/assets/js/model/Thing.js` by name. A `?v=` query on each import
specifier is ruled out because V8 attributes a module loaded through a stamped specifier to a URL
that `--test-coverage-include` does not match. That zeroes everything the tests reach through
`main.js` and fails a 100% gate. A path segment costs neither, because a relative specifier resolves
against the URL it was loaded from. `/assets/js/v-a1b2c3d4/main.js` importing `./model/Thing.js` asks
for `/assets/js/v-a1b2c3d4/model/Thing.js` with **no file rewritten at all**. The compiled JS stays
byte-identical to tsc's output, which keeps the drift check a straight diff.

The price is one stamp per build rather than one per file, so any change busts the whole tree: every
module in the debug tree, and the single bundle in the tree that ships. For one bundle, that is not
worth a second thought.

**The rewrite and `dev-router.php` are a mirror**: one rule, written twice in two languages. A site's
suite should pin that both strip the same pattern, and that every `php -S` it starts loads the router.
The router also sends every dot segment to the site's own 404: `.user.ini`, `.htaccess` and `..`,
whether written plainly or percent-encoded. It serves a stamped file only from inside the webroot's
own `assets/js/` or `assets/css/`. The built-in server would refuse nothing and would resolve `..`
itself, where Apache handles both.

**Images are deliberately not versioned.** A person drops them in by hand, and the code names them
with plain constants. Teaching those constants to consult a build artefact costs more than a calendar
TTL on files that change about never. `build-assets.mjs` leaves everything under `assets/img/` alone.
The line is: assets the build generates get a content hash, and assets a person drops in keep a date.

### Why the sources sit outside `public/`

The sources are neither web-served nor deployed. Source maps still work in the debug tree:
`inlineSources` embeds the TypeScript in the map itself, so DevTools shows `Navigation.ts` without
`assets/ts/` being served. Some hosts serve only the static types they have a handler for. On such a
host, the debug tree needs a handler for `map`. The prod tree ships none.

### The compiler settings that are load-bearing

A site's `tsconfig.json` runs `strict` plus the settings below. [The framework's own](../tsconfig.json)
checks the framework's modules under the same strictness and emits nothing, so a module that passes
there compiles in a site. [`site/tsconfig.json`](../site/tsconfig.json) is a whole site's config.

| Setting | Catches |
|---|---|
| `module: nodenext` | an extensionless relative import. A specifier the browser would 404 on cannot ship |
| `noUncheckedIndexedAccess` | an array index assumed to be present |
| `exactOptionalPropertyTypes` | `undefined` smuggled into an optional property |
| `noEmitOnError` | a type error leaving stale or half-written JS in `public/` |
| `preserveSymlinks` | the framework's `assets/ts/` reached through a symlink (`assets/ts/phpanta`) being compiled at its real path. That path is outside `rootDir`, so tsc would refuse |

`removeComments` is **on**. The comments already travel inside each map's `inlineSources`, so
DevTools shows the commented source either way. Keeping them in the `.js` as well would be a copy
only the network pays for. The debug tree is still readable (formatted `tsc` output, one module per
source file); it just has no comments.

---

## Guards — `NestedElement`

Some tags only mean anything inside another element. One of those loose in a page is the same
mistake as a misspelled tag, and it fails the same silent way: an inert inline box, styled by a
selector that does not match, with nothing in the console.

[`NestedElement`](../assets/ts/elements/NestedElement.ts) walks up from itself looking for an
instance of the element it belongs inside, and throws if it finds none:

```ts
export class CardTitle extends NestedElement {
  protected parent(): CustomElementConstructor { return PostCard; }
}
```

The check is **"somewhere inside", not "directly under"**. A card's tags can sit inside the anchor
that has to stay a real link: `<post-card>` wraps `<a>`, which wraps `<card-title>`.

A throw in `connectedCallback` does not reach whoever inserted the element. The browser reports it as
an uncaught error, which is loud enough to notice and is how tests capture it.

## SPA navigation

[`Navigation`](../assets/ts/Navigation.ts) intercepts internal link clicks, fetches the page as a
content fragment, and swaps `#content`. A link carrying `data-no-spa` is left to the browser, and so
is one with a `download` attribute. Use `data-no-spa` for an address whose answer is a redirect to a
file, because the fetch would otherwise consume the redirect silently.

```
click on a[href^="/"]
  → not already cancelled, not modified/middle-click, no data-no-spa, no download, no other target
  → resolved origin === location.origin, and not a #fragment of the page already showing
  → note the scroll on the entry being left, preventDefault, pushState a new keyed entry
  → fetch with X-Requested-With: XMLHttpRequest
  → not ok, or not text/html → location.replace(url)
  → a server running the framework: ViewResponse sends <title> + the fragment
      → read and decode the title, strip it, assign the rest to #content.innerHTML
  → a static host (an export): the whole page, since there is no header to read
      → parse it, take its title and its #content — or, with no #content, location.replace(url)
  → dispatch phpanta:navigate
  → focus #content, announce the title, then scroll: to where the entry was left, else to the
    element the fragment names, else to the top
```

`Navigation` tells the two answers apart by the doctype a whole document starts with. The static
host is not a fallback path. It is how the framework's own site, in `site/`, is served on GitHub
Pages, where every navigation takes the second branch.

### The six things to understand before touching it

**1. The selector matches the href *attribute*, but the code uses the resolved `link.href`.**
`//evil.example/x` starts with a slash exactly as `/posts` does: a protocol-relative URL is a
different origin wearing a path's clothes. So `onClick` reconciles the two readings and hands
anything cross-origin back to the browser. Nothing the server emits is protocol-relative, because
`Element` refuses to write one, so this is the client's half of the same rule.

**2. `go()` ends in an `innerHTML` assignment.** That is safe only because the fragment is
same-origin and was built by the server's markup tree, where `Text` escapes every value and every
URL attribute is scheme-checked. An exported page is the same markup, written to disk by the same
tree, so taking `#content` out of one relies on the same guarantee and no other. The guarantee is
*inherited*, not enforced here. Anything that ever puts markup into `#content` from another source
reopens DOM XSS, and nothing in that file would notice.

**3. Only the most recent navigation may write to the page.** `pushState` runs before the fetch, so
the address bar already shows where the *last* click went. Without a guard, whichever response lands
last wins `#content`. A slow first click beating a fast second one would leave the URL and the page
disagreeing, with nothing reporting it. So `go()` takes a number on the way in and checks after each
`await` that it is still the current one. There is an `AbortController` as well, but the counter is
what makes the guarantee. A fetch can resolve in the instant before an abort is observed, and then
only the number stands between a stale response and the page.

**4. Nothing re-runs after a swap.** The browser upgrades any custom element it parses, including
markup assigned through `innerHTML`, so a site's elements wire themselves on arrival. The
`phpanta:navigate` event is for anything that is *not* an element. Subscribe with
`Navigation.onNavigate()` rather than with the event's name as a string.

**5. The scroll is `Navigation`'s.** `start()` sets `history.scrollRestoration = 'manual'`. The
browser would otherwise restore an entry's position the moment back or forward reaches it, before
the page that belongs there has been fetched, so it would scroll the page being left. Each entry
carries a key in `history.state`, and where the visitor left it is kept in two ways:

- **on the entry itself, while it can still be written.** That happens before a click moves off it,
  and on `pagehide`, so a reload or a return from another site lands where it was;
- **in memory, once back or forward has already moved off it.** That is what the forward button
  returns to.

Back and forward between two fragments of one page fetch nothing and only scroll. A
`phpanta:navigate` subscriber runs before the scroll is decided, so one that scrolls is overridden.

**6. A swap says that it happened.** A page load moves focus to the top of the document and a screen
reader announces the new page. A swap does neither on its own, so the script makes it:

- It gives `#content` `tabindex="-1"`, which makes it focusable by the script but never a tab stop,
  and focuses it after every swap.
- It writes the new title into a polite live region it creates. The region is hidden from sight
  through the CSSOM rather than a `style` attribute, which the CSP's `style-src` would refuse.

A site's stylesheet will usually want `#content:focus { outline: none; }`. `#content` is not a
control, and a ring around the whole page would say it was one.

### Failure is always "hand it back to the browser"

A non-`ok` response, a response that is not `text/html` (a file a route answers with), and a thrown
fetch all call `location.replace(url)`. The exception is an abort. An abort is the router cancelling
itself rather than a failure, and handing the browser a URL the visitor has already left would undo
the navigation that replaced it. The call is `replace()` rather than `assign()` because the entry for
that URL already exists: `pushState` made it, or back and forward arrived on it. `assign()` would put
a second entry behind it for back to land on. Likewise `forDocument()` returns `null` when there is
no `#content`, which switches the whole router off with every link still working.

Back and forward re-fetch the whole URL they arrive on, query and fragment included.

---

## The build tools' command line

`build-css.mjs`, `build-assets.mjs` and `build-prod.mjs` share
[`build-cli.mjs`](../tools/build-cli.mjs), which provides a `fail`, a `label`, a `read`, and an argv
parsed against the flags a tool declares. It is `Cli` on the other side of the language boundary, and
it is there for the reason that layer exists. An undeclared flag, a flag with no path, and `--out=`
are all refused by name. A misspelled `--out` that was silently ignored would overwrite the committed
stylesheet and report success. Every flag there takes a path. That is not a simplification but the
whole vocabulary, and there is no `takesValue()` because nothing on that side stands alone.
`build-cli.mjs` has no dependencies and nothing runs on import, so the stylesheet rebuilds on a clone
that has never seen `npm install`.

`read(file) ?? fail(…)` is the idiom for reading a file. `read` is `Support\File::read()` in the
other language. Absent and unreadable get one answer, because every caller turns them into one
message. The expression also narrows to a `string`, which a `try`/`catch` around a `never`-returning
`fail` does not.
