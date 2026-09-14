/**
 * Builds the tree that ships, out of the tree that is committed.
 *
 * `public/` is three things at once — what the browser loads, what `test/js/` imports by path, and
 * what a site's suite diffs byte-for-byte against a fresh `tsc`. The last two are why it stays
 * readable and mapped: a minified `public/assets/js/` would make the drift check a diff between two
 * things a person cannot compare, and `?v=`-style attribution problems aside, coverage is pinned to
 * those exact paths. So prod is a **second tree**, derived from the first and never committed:
 *
 *     public/                       ← readable, mapped, committed, tested
 *           ↓ tools/build-prod.mjs
 *     build/dist/public/            ← one bundled module, minified, no maps; what a deploy ships
 *     build/dist/src/<App>/AssetManifest.php
 *
 * Three things change, and all three are only worth doing here:
 *
 *   1. **The maps go.** They are several times the JS they describe, because `tsconfig`'s
 *      `inlineSources` puts the whole commented TypeScript inside each one. Static
 *      assets are served straight by Apache and reach neither auth gate (docs/security.md), so on
 *      the live host those are public files. A site whose source is public anyway has a reason not
 *      to worry about it, and still no reason to serve a second copy from its host.
 *   2. **The graph is bundled into one module**, which is the change that pays for the rest.
 *      gzip's window then spans the whole graph instead of restarting at every small module, so
 *      one response compresses to well under half of what forty-nine separate ones do. It also
 *      turns forty-nine requests into one and empties the preload list, which takes forty-six
 *      `modulepreload` links off *every document* — see the site's shell, which emits them.
 *   3. **The JS is minified.** Still worth doing, though the compression above already does most
 *      of the work identifier mangling would.
 *
 * **Why the debug tree does not get any of this.** public/ is imported by test/js/ by path, pinned
 * by a site's 100% coverage gate, and diffed byte-for-byte against a fresh `tsc`. All three want
 * output a person can read. Bundling it would cost every one of them; bundling here costs nothing,
 * because the tests reach the elements through one `import main.js` and the DOM — so re-running
 * them with PHPANTA_JS_DIR set still executes exactly the bytes the server sends.
 *
 * **`keep_classnames` is load-bearing, not a default left alone.** `NestedElement.tagOf()` falls
 * back to `constructor.name` when `customElements.getName` is missing, and that is the text of the
 * error a misnested tag throws — the whole reason those classes are not empty. Mangling class names
 * would turn `<list-item> must be inside <list-box>` into `must be inside <e>`.
 *
 * **It takes both tools to keep, and terser's option alone is not enough.** Bundling rewrites some
 * `class X extends Y {}` declarations into `var X = class extends Y {}`, whose name is inferred
 * from the binding rather than declared — and `keep_classnames` only protects a declared one. So
 * terser mangles the binding and the error becomes `<list-item> must be inside <P>`. esbuild's
 * `keepNames` emits an explicit name assignment that survives it. Its `__name` helper is emitted
 * once in the bundle, for about 256 gzipped bytes.
 *
 * A site's suite checks this rather than the reasoning being trusted: its verify script can re-run
 * every client test against these bytes.
 *
 * `mangle.properties` stays off for the same kind of reason one step further out: `connectedCallback`,
 * `observedAttributes` and `attributeChangedCallback` are contracts with the browser rather than
 * with us, and renaming them would leave elements that register and then never fire.
 *
 * Usage:
 *   node tools/build-prod.mjs                # build/dist/, from the committed public/
 *   node tools/build-prod.mjs --out <dir>    # elsewhere — never above the project, and in it only under build/
 *
 * Assumes `public/` is current — a site's script for it runs the debug build first rather than
 * trusting that. Exits non-zero with the reason on stderr; it clears the tree before it starts, so
 * a failed build leaves no partial one to be deployed by mistake.
 */

import { build as esbuild } from 'esbuild';
import { minify } from 'terser';
import { cpSync, mkdirSync, readdirSync, readFileSync, rmSync, statSync, writeFileSync }
  from 'node:fs';
import { dirname, isAbsolute, join, relative, sep } from 'node:path';
import { fileURLToPath } from 'node:url';
import { execFileSync } from 'node:child_process';

import { ROOT, app, cli } from './build-cli.mjs';

const { fail, label, path } = cli('build-prod', ['out']);

const PUBLIC = join(ROOT, 'public');
const JS     = join(PUBLIC, 'assets/js');

const DIST     = path('out', join(ROOT, 'build/dist'));
const DIST_PUB = join(DIST, 'public');
const DIST_JS  = join(DIST_PUB, 'assets/js');

/**
 * Whether `path` is `directory` itself or somewhere under it.
 *
 * @param {string} path
 * @param {string} directory
 * @returns {boolean}
 */
function within(path, directory) {
  const rel = relative(directory, path);

  return rel === '' || (rel !== '..' && !rel.startsWith(`..${sep}`) && !isAbsolute(rel));
}

// --out is deleted before anything is built into it, so where it points is settled before that
// happens. The project, or any directory above it, would take the working tree with it; anywhere in
// public/ is the tree the build copies from, deleted and then copied into itself.
if (within(ROOT, DIST)) {
  fail(`--out ${DIST} is the project or a directory above it, and the build deletes --out first.`);
}

if (within(DIST, PUBLIC)) {
  fail(`--out ${label(DIST)} is inside public/, which the build copies from.`);
}

// And anywhere else in the project but build/: `--out src` passed both checks above, and deleted the
// site's source before building into its place.
if (within(DIST, ROOT) && !within(DIST, join(ROOT, 'build'))) {
  fail(`--out ${label(DIST)} is inside the project and outside build/, and the build deletes --out first.`);
}

/**
 * Every file under `dir` whose name ends in `suffix`, as absolute paths, sorted.
 *
 * @param {string} dir
 * @param {string} suffix
 * @returns {string[]}
 */
function filesEnding(dir, suffix) {
  const found = [];

  (function descend(at) {
    for (const entry of readdirSync(at).sort()) {
      const child = join(at, entry);

      if (statSync(child).isDirectory()) {
        descend(child);
      } else if (child.endsWith(suffix)) {
        found.push(child);
      }
    }
  })(dir);

  return found;
}

// ── the copy ────────────────────────────────────────────────────────────────────────────────────

// Cleared rather than merged into: rsync ships this tree wholesale, so a file left behind from a
// build two commits ago would be deployed as though it were current.
rmSync(DIST, { recursive: true, force: true });
mkdirSync(DIST, { recursive: true });

// All of public/, not just the JS. That is what makes build/dist/public/ exactly what lands in the
// webroot — one directory that can be listed, measured and diffed, rather than a deploy script
// assembling the answer out of two trees and getting the precedence right every time.
cpSync(PUBLIC, DIST_PUB, { recursive: true });

// ── the minify ──────────────────────────────────────────────────────────────────────────────────

const sources = filesEnding(JS, '.js');

if (sources.length === 0) {
  fail(`${label(JS)} holds no modules. Compile assets/ts/ with tsc first — there is nothing here to ship.`);
}

// One pass over the graph from the entry, concatenating it into a single ES module. esbuild is a
// bundler here and nothing else: no minify, no target lowering beyond what tsc already emitted.
// That division is the whole reason it can be used at all — see `keep_classnames` at the top.
const graph = await esbuild({
  entryPoints: [join(JS, 'main.js')],
  bundle:      true,
  format:      'esm',
  target:      'es2022',
  write:       false,

  // Load-bearing, and terser's keep_classnames is not enough on its own — see the note at the top.
  // Bundling rewrites some `class X extends Y {}` declarations into `var X = class extends Y {}`,
  // whose name is inferred from the binding; terser then mangles that binding to `P` and
  // NestedElement.tagOf() starts reporting `<list-item> must be inside <P>`. keepNames emits an
  // explicit name assignment that survives any mangling. Measured at 256 gzipped bytes.
  keepNames: true,

  // Nothing here is fetched from a CDN and nothing is a bare specifier — build-assets.mjs fails the
  // build over either — so every import resolves on disk. A `platform` would only start inventing
  // node or browser resolution rules for a graph that needs neither.
  logLevel: 'silent',
});

const output = graph.outputFiles[0] ?? fail('esbuild produced no output file for the entry point.');
const bundledCode = Buffer.from(output.contents).toString('utf8');

const before = sources.reduce((sum, file) => sum + Buffer.byteLength(readFileSync(file, 'utf8')), 0);
let   after  = 0;

{
  const result = await minify(bundledCode, {
    ecma: 2022,

    // Without this terser parses the file as a script, where a top-level `import` is a syntax
    // error. It also lets it drop unreferenced top-level bindings, which a script cannot promise.
    module: true,

    compress: { passes: 2 },

    // Property names are the browser's vocabulary here, not ours — see the note at the top.
    mangle: { properties: false },

    // NestedElement.tagOf()'s fallback reads constructor.name. See the note at the top.
    keep_classnames: true,

    // Drops tsc's `//# sourceMappingURL=` line along with everything else, which is the half of
    // "no maps" that matters: an unstripped comment is a 404 in every DevTools that opens the page.
    format: { comments: false },
  });

  // terser *rejects* on a parse or compress failure rather than reporting one on the result, so a
  // real failure never arrives here — it comes out of the `await` above and exits non-zero on its
  // own. This is the other case: a resolved call that carried no code, which has no message to
  // quote — terser 5's result has no `error` property to read one from.
  const minified = result.code ?? fail('terser resolved without producing any code for the bundle.');

  after = Buffer.byteLength(minified);

  // The copy above put all forty-nine readable modules and their maps here. Every one of them is
  // now dead — the bundle contains them, and nothing imports them — so the directory is replaced
  // rather than written into. Left behind they would ship as unreferenced files under a manifest
  // that names none of them: three times the payload, with the maps that were deleted for a reason
  // among them, and no symptom in a browser at all.
  rmSync(DIST_JS, { recursive: true, force: true });
  mkdirSync(DIST_JS, { recursive: true });

  writeFileSync(join(DIST_JS, 'main.js'), minified, 'utf8');
}

// ── everything was actually minified ────────────────────────────────────────────────────────────

// The copy above put a readable module at every path this tree serves, and the bundle step then
// replaced the whole directory with one file. A step that did not happen does not leave a hole — it
// leaves the *originals*, which work perfectly and ship under a stamp claiming otherwise, and the
// only visible symptom is a page quietly bigger than it says it is. So the tree is refused unless it
// holds exactly one module and no map.
const shippedJs  = filesEnding(DIST_JS, '.js');
const shippedMaps = filesEnding(DIST_PUB, '.map');

if (shippedJs.length !== 1) {
  fail(`the shipped tree holds ${shippedJs.length} modules where it should hold one bundle:\n`
     + shippedJs.map((file) => `              ${label(file)}`).join('\n'));
}

if (shippedMaps.length > 0) {
  fail(`${shippedMaps.length} source map(s) survived into the shipped tree:\n`
     + shippedMaps.map((file) => `              ${label(file)}`).join('\n'));
}

// The length check above is the proof that this is a string; noUncheckedIndexedAccess cannot read
// it, and a second guard here would be the dead defensive branch this project refuses elsewhere.
const bundlePath = /** @type {string} */ (shippedJs[0]);

const bundle = readFileSync(bundlePath, 'utf8');

// The sharpest of the three, and the reason this block exists at all. A surviving relative import
// means esbuild resolved nothing and this file is main.js by itself — while every module it asks
// for was just deleted with the rest of the copy. The page then 404s its way down the graph one
// wave at a time, and nothing says so until it is live.
if (/(?:^|\n)\s*(?:import|export)\b[^\n]*?['"]\.[^'"\n]*['"]/.test(bundle)) {
  fail(`${label(bundlePath)} still names a relative import, so it was never bundled.\n`
     + '              Everything it imports was removed along with the rest of the copy.');
}

if (after >= before) {
  fail(`the bundle is ${after} bytes against ${before} for the readable tree, and should be far\n`
     + '              smaller. Either the bundling or the minification did not happen.');
}

// Belt over braces on `format.comments`, because the failure it guards is invisible from here: a
// surviving reference costs nothing until somebody opens DevTools on the live site, and then it is
// a 404 per module with no other symptom.
const stragglers = filesEnding(DIST_JS, '.js')
  .filter((file) => readFileSync(file, 'utf8').includes('sourceMappingURL'))
  .map((file) => label(file));

if (stragglers.length > 0) {
  fail(`${stragglers.length} shipped module(s) still name a source map that is not there:\n`
     + stragglers.map((file) => `              ${file}`).join('\n'));
}

// ── the manifest ────────────────────────────────────────────────────────────────────────────────

// The same stylesheet and entry URLs as the committed manifest under a different stamp — the bytes
// at those URLs are different bytes, and a stamp is a claim about content. What differs beyond the
// stamp is MODULES, which is empty here: the graph ships as one file, so there is no wave-at-a-time
// discovery for a preload hint to flatten. The committed manifest still lists all forty-six,
// because the debug tree still ships forty-nine modules and is still discovered that way.
//
// The graph is walked in the readable tree and the bytes are read from the bundle. That is the same
// split --graph-dir was added for, taken one step further: the shape of the graph is a property of
// the sources, and after bundling there is no shipped tree left with a shape to read.
const MANIFEST = join(DIST, relative(ROOT, app().manifest));

try {
  execFileSync(process.execPath, [
    // The sibling tool, found beside this one — a tool, not a project file, so not under ROOT.
    join(dirname(fileURLToPath(import.meta.url)), 'build-assets.mjs'),
    '--graph-dir', JS,
    '--bundle', join(DIST_JS, 'main.js'),
    '--css', join(DIST_PUB, 'assets/css/style.css'),
    '--out', MANIFEST,
  ], { stdio: ['ignore', 'ignore', 'inherit'] });
} catch {
  fail('the prod manifest could not be generated — see build-assets above.');
}

const percent = (100 * (1 - after / before)).toFixed(1);

console.log(`build-prod: ${sources.length} modules bundled into one, ${before} → ${after} bytes `
          + `(${percent}% off), ${sources.length} maps dropped → ${label(DIST)}`);
