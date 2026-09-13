# Front end — the framework

The build that turns a site's TypeScript and CSS into what ships, the guards every element can
lean on, and SPA navigation. A site's own elements and stylesheet are its own; neuro.SYS's are in
[its front end](https://github.com/neurosysgg/neurosys-webspace/blob/master/docs/frontend.md).

## The build

```
assets/ts/  ──tsc──────────────────────→  public/assets/js/               ← the debug tree: generated, committed
assets/css/ ──phpanta/tools/build-css.mjs──────→  public/assets/css/style.css     ← generated, committed
both        ──phpanta/tools/build-assets.mjs───→  src/<Site>/AssetManifest.php    ← generated, committed
public/     ──phpanta/tools/build-prod.mjs─────→  build/dist/                     ← the prod tree: generated, gitignored, deployed
```

| Command | Does |
|---|---|
| `npm run build` | `tsc`, then the stylesheet, then the asset manifest — last, because it hashes both outputs |
| `npm run build:css` | the stylesheet only |
| `npm run build:assets` | the asset manifest only |
| `npm run build:prod` | `npm run build`, then derives `build/dist/` — see *Debug and prod* |
| `npm run watch` | `tsc --watch`. **Does not build the CSS or the manifest.** |
| `npm run dev` | `php -S` with `phpanta/tools/dev-router.php`. The router is not optional. |
| `npm run check` | `tsc` over all three trees — `assets/ts/`, `tools/*.mjs`, `test/js/*.mjs` |
| `npm test` | `node --test` against the compiled output |
| `npm run coverage` | the same, with 100% thresholds |

**Never hand-edit `public/assets/js/`, `public/assets/css/style.css` or
`src/<Site>/AssetManifest.php`** (under the site's own namespace). They are build output and the
next build overwrites them. The generated stylesheet carries a marker comment above each block
naming the part it came from — edit that part.

### Why the output is committed

`deploy.sh` builds the shipped tree out of `public/` in the working tree. Nothing builds on the
server, so a forgotten rebuild would ship stale JS or a stale stylesheet and nothing else would
notice. The verify script therefore rebuilds all three outputs and diffs — a drifted output is a
failing test.

The CSS check needs only `node`, so it runs on a bare clone. The TypeScript checks need
`node_modules` and are **skipped with a printed NOTE** when `npm install` has never run, so
`composer test` still works without the npm tooling.

### Debug and prod

`public/` is the **debug** tree and is not what ships. It is committed, readable and unbundled
because three things read it by path: `test/js/` imports the modules, `npm run coverage` pins its
100% gate to `public/assets/js/**`, and the verify script diffs it byte-for-byte against a fresh
`tsc`. All three want output a person can read — which is also why the cache version is a path
segment and not a rewritten specifier (see [Cache versioning](#cache-versioning)).

`npm run build:prod` derives the **prod** tree from it. `phpanta/tools/build-prod.mjs` copies `public/`
wholesale, bundles the whole module graph into one file with esbuild, minifies that with terser,
deletes every source map, and writes a manifest of its own:

```
build/dist/public/                        ← byte-for-byte what lands in the webroot
build/dist/src/<Site>/AssetManifest.php   ← the same two URLs under a different stamp, and no
                                            preloads: one file has no graph left to hint at
```

Three things change, and all three are only worth doing at the edge:

- **The maps go.** They are three times the JS they describe, and `inlineSources` puts the whole
  commented TypeScript inside each one. Static assets are served straight by Apache and reach
  neither auth gate, so on the live host those would be public files. The source is on GitHub — a
  reason not to worry about it, not a reason to serve a second copy from Strato.
- **The graph is bundled**, which is the change that pays for the rest: one ~6.8 KB gzipped response
  instead of 50, because gzip's window then spans the whole graph, and no preload list in any
  document. Sizes are in [performance.md](https://github.com/neurosysgg/neurosys-webspace/blob/master/docs/performance.md#the-front-end-payload).
- **The JS is minified.** It earns a little on top of the bundle; gzip over one stream already
  captures most of what identifier mangling would.

**Class names have to survive minification, and that takes both tools.** `NestedElement.tagOf()`
falls back to `constructor.name`, and that is the text of the error a misnested tag throws — the
whole reason those classes are not empty. terser's `keep_classnames` protects only a *declared*
class name, and bundling rewrites some `class X extends Y {}` declarations into
`var X = class extends Y {}`, whose name is inferred from the binding — so without esbuild's
`keepNames` as well, `<terminal-key> must be inside <terminal-field>` reads `must be inside <P>`.
`keepNames`' `__name` helper is emitted once in the bundle, about 256 gzipped bytes.
`mangle.properties` stays off, because `connectedCallback` and `observedAttributes` are contracts
with the browser rather than with us. (History: [history/frontend.md](https://github.com/neurosysgg/neurosys-webspace/blob/master/docs/history/frontend.md).)

**The manifest's stamp differs between the two trees, and that is correct** — a stamp is a claim
about content, and those are different bytes at the same URLs. `deploy.sh` uploads `src/` from the
working tree and then overlays the prod manifest over the one file that differs.

**Six checks, because every failure here is invisible in a browser until it is live.** The verify
script builds the prod tree; asserts it ships no map and names none; asserts the prod manifest
points at the same entry and stylesheet as the committed one, that it preloads nothing, and that the
URL it names has bytes behind it; and then re-runs the **whole client-side suite against the shipped
bytes**. The two manifests are deliberately *not* diffed against each other: the debug one lists
every preload and the prod one none, so a diff would assert away the thing the build exists to do.

The re-run is the check worth the most. `test/js/dom.mjs` takes its tree from `PHPANTA_JS_DIR`, so
the nesting guards, `TerminalWindow`'s subtree, both embeds and `Navigation` all execute what the
server will send. It works across a bundle because `dom.mjs` reaches the elements through one
`import main.js` and the DOM, never by module path; the three files that do import modules directly
— `enum-parity`, `navigation`, `vocabulary` — are hardcoded to the debug tree and unaffected by what
prod ships. `npm test` and `npm run coverage` use the debug tree by default.

`build-prod.mjs` also refuses a shipped tree holding anything but the one bundle, and refuses a
bundle that still names a relative import — which would mean esbuild resolved nothing and every
module the entry asks for was deleted with the rest of the copy. Both refusals exist because a prod
tree that silently is not bundled still works, just bigger, and has no other symptom.

**`build-assets.mjs` takes a `--graph-dir`.** Its import scanner is anchored to whole lines on
purpose — a looser match walks out of a line and into string literals. terser puts an entire module
on one line, so walking the minified tree would find no imports at all. The graph is a property of
the sources rather than of the formatting, so the readable tree is walked for *which files import
which* and the shipped tree is read for *what is in them*.

### Preloading the module graph

This applies to the debug tree; the prod tree has no graph left to preload.

An ES module graph is discovered a wave at a time, and this one is **five waves deep**: the browser
learns it needs `model/CssClass.js` only after parsing `ConsentGatedEmbed.js`, which it learned about
from `SoundCloudWidget.js`, from `SoundCloudPlayer.js`, from `main.js`. Five sequential round trips
before the last module starts downloading, and none of it is bytes — compressing and stripping
comments leave the number exactly where it was.

`phpanta/tools/build-assets.mjs` walks the compiled graph and generates `src/<Site>/AssetManifest.php`;
`Layout::modulePreloads()` renders one `<link rel="modulepreload">` per entry, after the stylesheet
because that one blocks rendering and these do not. The preload scanner then sees all of them at
once and the five waves become one, for ~420 gzipped bytes per page.

**The counts:** the debug tree has 52 modules. `main.js` is the `<script src>` itself, 49 more are
reachable from it and preloaded, and two — `model/SectionKind.js` and `model/ArrangementAttribute.js`
— are imported by no module at all: they are mirrors that only `enum-parity.test.mjs` reads, since
the arrangement is server-rendered and no element selects on its values.

`modulepreload` rather than `preload as="script"`: it fetches, parses, compiles *and* inserts into
the module map, so the module is instantiated by the time `main.js` asks. The list is every module
rather than the first wave — the spec lets a browser follow a preloaded module's own imports and
Chrome does, but it is not obliged to and Safari has been uneven, so leaning on it would make the
fix silently partial. `main.js` is deliberately absent: it is the `<script src>` already in flight.

Three checks, because the two failure modes are different. The verify script **rebuilds the manifest
and diffs** it (a module missing from a stale list brings its whole subtree's waterfall back), and
**asks the server for every hinted URL** (the list can be perfectly in step with the graph and still
point at nothing, since the URL base is written by hand in the tool). `ViewTest` asserts the same
existence question against the filesystem, so it fails in the fast suite without a server running.
**Neither failure is visible in a browser** — the page works, it is just slower — which is why all
three exist.

### Cache versioning

Every built asset is served under a path segment naming a hash of the build:
`/assets/js/v-a1b2c3d4/main.js`. That is what lets `public/.htaccess` mark them
`immutable, max-age=31536000` — a URL that names its own content cannot come to mean something else,
so a returning visitor fetches none of it. The segment is not a directory; the server strips it,
Apache by a `RewriteRule` and the dev server by `phpanta/tools/dev-router.php`.

**Why a path segment and not a filename or a query.** `Tag.a1b2c3d4.js` would break every test that
imports `public/assets/js/model/Tag.js` by name. A `?v=` query on each import specifier is ruled out
because V8 attributes a module loaded through a stamped specifier to a URL
`--test-coverage-include` does not match, which zeroes everything the tests reach through `main.js`
and fails the 100% gate. A path segment costs neither, because a relative specifier resolves against
the URL it was loaded from — so `/assets/js/v-a1b2c3d4/main.js` importing `./model/Tag.js` asks for
`/assets/js/v-a1b2c3d4/model/Tag.js` with **no file rewritten at all**. The compiled JS stays
byte-identical to tsc's output, which is what keeps the drift check a straight diff.

The price is one stamp per build rather than one per file, so any change busts the whole tree — all
52 modules in the debug tree, and the single bundle in the one that ships. At ~7 KB gzipped that is
not worth a second thought.

**`.htaccess` and `dev-router.php` are a mirror** — one rule, two languages — so the verify script
pins that they strip the same pattern, and that *both* `php -S` invocations in it load the router.

**Images are deliberately not versioned.** They are vendored and hand-placed, and reached through
`Platform::icon()` and `Config::COVER_PLACEHOLDER` as plain constants — teaching a Model enum to
consult a build artefact costs more than a calendar TTL on files that change about never. The line
is: assets the build generates get a content hash, assets a person drops in keep a date.

### Why the sources sit outside `public/`

They are neither web-served nor deployed. Source maps still work in the debug tree: `inlineSources`
embeds the TypeScript in the map itself, so DevTools shows `Navigation.ts` without `assets/ts/` being
served. That is why `public/.htaccess` lists `map` — Strato 500s any static file it has no
`SetHandler` for. The prod tree ships no maps; the handler stays for the debug tree, which is what
`npm run dev` serves.

### The compiler settings that are load-bearing

[`tsconfig.json`](https://github.com/neurosysgg/neurosys-webspace/blob/master/tsconfig.json) runs `strict` plus:

| Setting | Catches |
|---|---|
| `module: nodenext` | an extensionless relative import — a specifier the browser would 404 on cannot ship |
| `noUncheckedIndexedAccess` | an array index assumed to be present |
| `exactOptionalPropertyTypes` | `undefined` smuggled into an optional property |
| `noEmitOnError` | a type error leaving stale or half-written JS in `public/` |

`removeComments` is **on**: the comments already travel inside each map's `inlineSources`, so
DevTools shows the commented source either way, and keeping them in the `.js` as well would be a
copy only the network pays for. The debug tree is still readable — formatted `tsc` output, one
module per source file — it is just uncommented.

---

## Guards — `NestedElement`

`<terminal-key>` loose in a page is the same mistake as a misspelled tag, and would fail the same
silent way: an inert inline box, styled by a selector that does not match, nothing in the console.

[`NestedElement`](https://github.com/neurosysgg/neurosys-webspace/blob/master/assets/ts/elements/NestedElement.ts) walks up from itself looking for an
instance of the element it belongs inside, and throws if it does not find one:

```ts
export class TerminalKey extends NestedElement {
  protected parent(): CustomElementConstructor { return TerminalField; }
}
```

The check is **"somewhere inside", not "directly under"** — a card's tags sit inside the anchor that
has to stay a real link: `<download-card>` wraps `<a>` wraps `<download-label>`.

Note that a throw in `connectedCallback` does not reach whoever inserted the element. The browser
reports it as an uncaught error, which is loud enough to notice and is how the tests capture it.

## SPA navigation

[`Navigation`](../assets/ts/Navigation.ts) intercepts internal link clicks, fetches the page as a
content fragment, and swaps `#content`. Download links carry `data-no-spa` to bypass it and trigger
a real navigation — otherwise the 303 would be consumed silently by the fetch.

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

The two answers are told apart by the doctype a whole document starts with. A static host is not a
fallback path: it is how [the framework's own site](https://neurosysgg.github.io/phpanta/) is
served, where every navigation takes the second branch.

### The six things to understand before touching it

**1. The selector matches the href *attribute*; the code uses the resolved `link.href`.**
`//evil.example/x` starts with a slash exactly as `/releases` does — a protocol-relative URL is a
different origin wearing a path's clothes. So `onClick` reconciles the two readings and hands
anything cross-origin back to the browser. Nothing the server emits is protocol-relative —
`Element` refuses to write one — so this is the client's half of the same rule.

**2. `go()` ends in an `innerHTML` assignment.** That is safe only because the fragment is
same-origin and was built by the server's markup tree, where every value is escaped by `Text` and
every URL attribute is scheme-checked. An exported page is the same markup, written to disk by the
same tree, so taking `#content` out of one spends the same guarantee and no other. The guarantee is
*inherited*, not enforced here — anything
that ever puts markup into `#content` from another source reopens DOM XSS, and nothing in that file
would notice.

**3. Only the most recent navigation may write to the page.** `pushState` runs before the fetch, so
the address bar already says where the *last* click went — and without a guard whichever response
lands last wins `#content`, so a slow first click beating a fast second one leaves the URL and the
page disagreeing with nothing reporting it. `go()` takes a number on the way in and checks it is
still the current one after each `await`. There is an `AbortController` as well, but the counter is
what makes the guarantee: a fetch can resolve in the instant before an abort is observed, and then
only the number stands between a stale response and the page. `navigation.test.mjs` stages that gap
deliberately, one microtask wide.

**4. Nothing re-runs after a swap.** The browser upgrades any custom element it parses, including
markup assigned through `innerHTML`, so the gate and the cover wire themselves on arrival. The
`phpanta:navigate` event stays for anything that is *not* an element — subscribe with
`Navigation.onNavigate()` rather than the string.

**5. The scroll is `Navigation`'s.** `start()` sets `history.scrollRestoration = 'manual'`, because
the browser restores an entry's position the moment back or forward reaches it — before the page
that belongs there has been fetched, so it would scroll the page being left. Each entry carries a
key in `history.state`, and where it was left is kept two ways: on the entry itself while it can
still be written — before a click moves off it, and on `pagehide`, so a reload or a return from
another site lands where it was — and in memory once back or forward has already moved off it,
which is what the forward button returns to. Back and forward between two fragments of one page
fetch nothing and only scroll. A `phpanta:navigate` subscriber runs before the scroll is decided,
so one that scrolls is overridden.

**6. A swap says that it happened.** A page load moves focus to the top of the document and a
screen reader announces the new page; a swap does neither on its own. So `#content` is given
`tabindex="-1"` by the script — focusable by it, never a tab stop — and focused after every swap,
and the new title is written into a polite live region the script creates, hidden from sight
through the CSSOM rather than a `style` attribute the CSP's `style-src` would refuse. A site's
stylesheet will usually want `#content:focus { outline: none; }`: it is no control, and a ring
around the whole page would say it was one.

### Failure is always "hand it back to the browser"

A non-`ok` response, a response that is not `text/html` — a file a route answers with — and a
thrown fetch all call `location.replace(url)`, except an abort, which is the router cancelling
itself rather than a failure: handing the browser a URL the visitor has already left would undo the
navigation that replaced it. `replace()` rather than `assign()`, because the entry for that URL
already exists — `pushState` made it, or back and forward arrived on it — and `assign()` would put
a second one behind it for back to land on. Likewise `forDocument()` returns `null` when there is no
`#content`, which switches the whole router off with every link still working.

Back and forward re-fetch the whole URL they arrive on, query and fragment included.

---

## The build tools' command line

`build-css.mjs`, `build-assets.mjs` and `build-prod.mjs` share
[`phpanta/tools/build-cli.mjs`](https://github.com/neurosysgg/neurosys-webspace/blob/master/tools/build-cli.mjs) — a `fail`, a `label`, a `read`, and an argv parsed
against the flags a tool declares. It is `Cli` on the other side of the language
boundary, and there for the reason that layer exists: an undeclared flag, a flag with no path and
`--out=` are all refused by name, because a misspelled `--out` that is silently ignored overwrites
the committed stylesheet and reports success. Every flag there takes a path, which is not a
simplification but the whole vocabulary — there is no `takesValue()` because nothing on that side
stands alone. No dependencies and nothing runs on import, so the stylesheet rebuilds on a clone that
has never seen `npm install`.

`read(file) ?? fail(…)` is the idiom for reading a file. `read` is `Support\File::read()` in the
other language — absent and unreadable are one answer, because every caller turns them into one
message — and the expression narrows to a `string`, where a `try`/`catch` around a `never`-returning
`fail` does not. (History: [history/frontend.md](https://github.com/neurosysgg/neurosys-webspace/blob/master/docs/history/frontend.md).)
