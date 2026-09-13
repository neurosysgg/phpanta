/**
 * Stamps every built asset with a hash of its content, and writes what came out to the site's
 * src/<App>/AssetManifest.php, which its shell reads to emit the stylesheet, the entry script and the
 * modulepreload list.
 *
 * Two jobs that have to be one tool, because the second depends on the first:
 *
 *   1. Version. `/assets/js/v-a1b2c3d4/main.js` is a different URL from the same file at a
 *      different version, so it can be cached for a year with `immutable`. Without it the site
 *      asks for `/assets/js/main.js` by that exact name forever, and a long max-age would mean
 *      "keep serving the old one after we replace it" rather than "keep this one".
 *   2. Preload. An ES module graph is discovered a wave at a time and this one is five deep, so
 *      the browser would otherwise spend five sequential round trips learning what to fetch.
 *      `<link rel="modulepreload">` in <head> flattens that to one.
 *
 * The href in a preload hint must match the URL the module actually resolves to, stamp and all, or
 * the browser fetches the file twice and the hint is worse than useless. That is why one tool owns
 * both: the stamp in every URL the manifest names is the one every import resolves under.
 *
 * **A version segment in the path, not a renamed file and not a query.** `Status.a1b2c3d4.js` is the
 * conventional shape and would break every test that imports `public/assets/js/model/Status.js` by
 * name — costing the property that the client tests load exactly what the browser loads. `?v=` on
 * each import specifier reads well and cost the front end's 100% coverage gate, for the reason
 * written at the stamp below. A path segment costs neither: a relative specifier resolves against
 * the URL it was loaded from, so `/assets/js/v-a1b2c3d4/main.js` importing `./model/Status.js` asks
 * for `/assets/js/v-a1b2c3d4/model/Status.js` with nothing having to rewrite anything.
 *
 * So this tool **writes no file but the manifest**. The compiled JS is byte-identical to what tsc
 * emitted, which is what keeps the drift check a straight diff, the tests importing plain paths,
 * and coverage attributing to the file it is measuring.
 *
 * The segment is stripped by the server — `public/.htaccess` in production and
 * `tools/dev-router.php` under the `php -S` the verify script runs. Those two are a mirror, and the
 * verify script pins that they strip the same shape.
 *
 * Deliberately not versioned: everything under `assets/img/`. Those are vendored, hand-placed and
 * referenced from the site's PHP as plain constants — teaching a model enum to consult a build
 * artefact would cost more than a calendar TTL on files that change
 * about never. The line is: assets the build generates get a content hash, assets a person drops in
 * keep a date. `public/.htaccess` gives those thirty days.
 *
 * **The graph and the bytes need not come from the same tree**, which is what `--graph-dir` is for.
 * `tools/build-prod.mjs` minifies a copy of public/, and terser puts an entire module on one line —
 * so the whole-line anchoring `SPECIFIER` relies on (see its note) finds no imports there at all,
 * and this tool would report main.js as reaching nothing. The shape of the graph is a property of
 * the sources rather than of the formatting, so the readable tree is walked for *which files import
 * which* and the shipped tree is read for *what is in them*. Every module the walk names must exist
 * in the shipped tree or the stamp fails, which is the half of "the two trees agree" that matters:
 * a URL in the manifest with no bytes behind it. The verify script closes the loop from outside, by
 * diffing the two manifests with the stamp normalised away.
 *
 * Usage:
 *   node tools/build-assets.mjs                       # stamps public/, writes the manifest
 *   node tools/build-assets.mjs --js-dir <dir> \      # against a scratch tree, for the drift check
 *                              --css <file> --out <path>
 *   node tools/build-assets.mjs --graph-dir <dir> \   # walk one tree, hash another
 *                              --js-dir <dir> --css <file> --out <path>
 *   node tools/build-assets.mjs --graph-dir <dir> \   # walk the graph, hash one bundle — the prod
 *                              --bundle <file> --css <file> --out <path>      # build
 *
 * No dependencies. Exits non-zero with the reason on stderr; it never writes a partial manifest.
 */

import { createHash } from 'node:crypto';
import { readFileSync, mkdirSync, writeFileSync } from 'node:fs';
import { dirname, join, relative, resolve } from 'node:path';

import { ROOT, app, cli, read } from './build-cli.mjs';

const { fail, label, path } = cli('build-assets', ['js-dir', 'graph-dir', 'css', 'out', 'bundle']);

/** The URL prefixes public/assets/{js,css}/ are served under. */
const JS_BASE  = '/assets/js';
const CSS_BASE = '/assets/css';

/** A line comment, and a block comment, non-greedy — stripped before scanning for imports. */
const COMMENT = /\/\/[^\n]*|\/\*[\s\S]*?\*\//g;

/**
 * `import x from 'y'`, `import 'y'` and `export … from 'y'` — the three forms that name a
 * dependency the browser must fetch. Bare `import()` is deliberately not matched: a dynamic import
 * is a decision to fetch later, and stamping it would undo the reason it was written that way.
 *
 * Anchored to a whole line, and `[^'"\n]` rather than `[^'"]`, because the loose version walked out
 * of `export class Config {` and into the string on the line below it, then reported the site's name as
 * an unresolvable import. tsc emits one statement per line and terminates each with a semicolon, so
 * requiring both costs nothing and makes that false match impossible.
 */
const SPECIFIER = /^\s*(?:import|export)\b[^'"\n]*?['"]([^'"\n]+)['"]\s*;\s*$/gm;

/**
 * The path segment carrying the build stamp. `public/.htaccess` and `tools/dev-router.php` both
 * strip `v-<8 hex>` directly under the asset root; the verify script pins that they agree.
 */
const VERSION_PREFIX = 'v-';

/** The tree whose bytes ship, and whose relative paths become the URLs in the manifest. */
const JS_DIR = path('js-dir', join(ROOT, 'public/assets/js'));

/** The tree the import graph is read from. The same one unless a build has made it unreadable. */
const GRAPH_DIR = path('graph-dir', JS_DIR);

const CSS_FILE = path('css', join(ROOT, 'public/assets/css/style.css'));
const APP      = app();
const MANIFEST = path('out', APP.manifest);
const ENTRY    = join(GRAPH_DIR, 'main.js');

/**
 * The one file the whole graph was bundled into, or `''` when nothing was bundled.
 *
 * Its presence is the mode switch, which is why it names the bundle rather than being a boolean:
 * every flag in tools/build-cli.mjs takes a path, deliberately, and a single caller is a poor
 * reason to teach that helper a second kind of argument. `path()` hands the fallback back
 * unresolved, so `''` is a value no real path collides with.
 *
 * What changes under it is narrow, and worth stating because everything else is the same tool
 * doing the same job. The graph is still walked — it is what proves the entry reaches every module
 * and what catches a cycle — and the stamp is still one hash over what ships. Only two things
 * move: the bytes hashed are the bundle's rather than forty-seven files', and MODULES is empty,
 * because a bundle has no waterfall for a preload hint to flatten.
 */
const BUNDLE = path('bundle', '');

/**
 * Eight hex characters of SHA-256 — 32 bits over forty-two files, so a collision is not a risk.
 *
 * @param {string} content
 * @returns {string}
 */
function digest(content) {
  return createHash('sha256').update(content).digest('hex').slice(0, 8);
}

/**
 * The unversioned path below the js dir, under the served prefix.
 *
 * @param {string} file
 * @returns {string}
 */
function jsUrl(file) {
  return `${JS_BASE}/${relative(GRAPH_DIR, file).split(/[\\/]/).join('/')}`;
}

/**
 * The bytes served at a walked file's URL — the same relative path, taken from the shipped tree.
 *
 * Identical to reading the file itself when the two trees are one, which is the usual case. When
 * they differ it is the whole point: the hash has to be over what the browser receives, or a
 * version segment would name content nobody has.
 *
 * @param {string} file
 * @returns {string}
 */
function shipped(file) {
  const at = join(JS_DIR, relative(GRAPH_DIR, file));

  return read(at) ?? fail(`${label(file)} is in the module graph, but ${label(at)} does not exist.\n`
                        + '              The tree being hashed is missing a module the tree being walked has.');
}

/**
 * The bundle's bytes — what the browser receives when the whole graph ships as one file.
 *
 * @returns {string}
 */
function bundled() {
  return read(BUNDLE) ?? fail(`${label(BUNDLE)} was named with --bundle, and does not exist.\n`
                            + '              That flag says the graph ships as one file; this is the file.');
}

/**
 * Inserts the build stamp as a path segment: /assets/js/x.js -> /assets/js/v-a1b2c3d4/x.js
 *
 * Directly after the asset root and before everything else, because that is the only position a
 * relative specifier carries with it. A stamp at the end would not survive `./model/Status.js`.
 *
 * @param {string} url
 * @returns {string}
 */
function versioned(url) {
  return url.replace(/^(\/assets\/(?:js|css))\//, `$1/${VERSION_PREFIX}${stamp}/`);
}

// ── walk ────────────────────────────────────────────────────────────────────────────────────────

/** file → its dependencies, in the order they are imported. */
const graph = new Map();

/** Grey while a file's subtree is being walked, so a cycle is reported rather than looped on. */
const walking = new Set();

/**
 * @param {string} file
 * @param {string} importedBy
 * @returns {void}
 */
function walk(file, importedBy) {
  if (graph.has(file)) {
    return;
  }

  if (walking.has(file)) {
    fail(`${label(file)} is part of an import cycle, reached again from ${importedBy}.\n`
       + '              A per-file content hash has no fixpoint around a cycle: each file\'s hash\n'
       + '              would depend on its own. Break the cycle, or stamp one hash per build.');
  }

  walking.add(file);

  const source = read(file) ?? fail(`${importedBy} imports ${label(file)}, which does not exist.\n`
                                  + '              Run `npm run build` — the committed JS is behind assets/ts/.');

  const deps = [];

  for (const match of source.replace(COMMENT, '').matchAll(SPECIFIER)) {
    // SPECIFIER has one group and it is not optional, so a match always carries it.
    const bare = /** @type {string} */ (match[1]);

    if (!bare.startsWith('.')) {
      fail(`${label(file)} imports "${bare}", which is not a relative path.\n`
         + '              Nothing here is bundled, so a bare specifier is a URL the browser 404s on.');
    }

    if (!bare.endsWith('.js')) {
      fail(`${label(file)} imports "${bare}" without a .js extension.\n`
         + '              tsconfig\'s nodenext should have made that a compile error — rebuild.');
    }

    const dep = resolve(dirname(file), bare);

    deps.push(dep);
    walk(dep, label(file));
  }

  walking.delete(file);
  graph.set(file, deps);
}

walk(ENTRY, 'the build');

// ── the build stamp ────────────────────────────────────────────────────────────────────────────

/**
 * One hash over every built asset, rather than one per file.
 *
 * Per-file would bust less — editing one element would bust that element and its ancestors instead
 * of all forty-nine — but the only per-file shape that keeps every path intact is `?v=` on each
 * import specifier, and V8 attributes a module reached that way to `…/Status.js?v=48f0b166`,
 * which `--test-coverage-include` does not match: every module the tests reach through `main.js`
 * would report zero. The gate is a deliberate property and worth more than per-file granularity
 * over a few kilobytes.
 *
 * Putting the version in the *path* instead costs nothing, because a relative specifier resolves
 * against the URL it was loaded from: `/assets/js/v-a1b2c3d4/main.js` importing `./model/Status.js`
 * asks for `/assets/js/v-a1b2c3d4/model/Status.js` without anything having to rewrite it. So the
 * committed JS is byte-identical to what tsc emitted, which is what keeps the drift check a
 * straight diff and the client tests loading exactly the files the browser runs.
 *
 * The server strips the segment: `public/.htaccess` for production, `tools/dev-router.php` for the
 * `php -S` the verify script runs. That pair is a mirror, and pinned like the others.
 */
const stamp = digest(
  (BUNDLE === ''
    ? [...graph.keys()].sort().map((file) => `${jsUrl(file)}\u0000${shipped(file)}`)
    : [`${JS_BASE}/main.js\u0000${bundled()}`])
    .concat(`${CSS_BASE}\u0000${readFileSync(CSS_FILE, 'utf8')}`)
    .join('\u0000'),
);

// main.js is the <script src> itself; hinting the browser to preload the file it is already
// fetching is noise, so the list is everything the entry reaches and not the entry.
//
// A bundle has nothing to list, and that is the feature switching itself off rather than a build
// that found nothing. The waterfall a preload hint exists to flatten is the graph being discovered
// a wave at a time; once it is one file there is no graph left to discover. The site's shell
// spreads this into containing(), so empty emits no links at all.
const modules = BUNDLE === ''
  ? [...graph.keys()]
      .filter((file) => file !== ENTRY)
      .map((file) => versioned(jsUrl(file)))
      .sort()
  : [];

// Only meaningful when the modules ship as modules. An entry that reaches nothing is a broken
// build — this graph is forty-odd files deep — so the guard stays exactly as strict as it was for
// the tree it was written about. Under --bundle the empty list is the expected answer, and the
// flag is how that is asked for deliberately rather than arrived at by accident.
if (BUNDLE === '' && modules.length === 0) {
  fail('main.js reaches no other module. That is a broken build: the graph is forty-odd files\n'
     + '              deep. If the tree really is one file now, say so with --bundle.');
}

// ── the stylesheet ──────────────────────────────────────────────────────────────────────────────

// Read to prove it is there and to fail with a useful sentence if it is not. Its content is already
// inside the build stamp above, so the manifest needs nothing from it but the path.
try {
  readFileSync(CSS_FILE, 'utf8');
} catch {
  fail(`${label(CSS_FILE)} does not exist. Run \`npm run build:css\` first — the manifest names it.`);
}

const stylesheet = versioned(`${CSS_BASE}/${relative(dirname(CSS_FILE), CSS_FILE)}`);

// ── the manifest ────────────────────────────────────────────────────────────────────────────────

const php = `<?php

declare(strict_types=1);

namespace ${APP.namespace};

/**
 * Generated by phpanta/tools/build-assets.mjs — do not edit.
 *
 * Every built asset the shell loads, as the URL the browser asks for, carrying a hash of the
 * content at that URL. The site's shell emits all three: the stylesheet, the entry script, and one
 * \`<link rel="modulepreload">\` per module so the graph is discovered in one round trip instead of
 * the wave-at-a-time walk an ES module tree is otherwise found by.
 *
 * The version is what lets \`public/.htaccess\` mark these \`immutable\` for a year. It is a hash of
 * the file's content *after* its own imports were stamped, so a change to a leaf module changes
 * every hash above it and none beside it.
 *
 * These are the versioned URLs: the fact about which copy of each file, which is the build's. Where
 * each file lives is the site's own business, and stays where the site states it.
 *
 * Regenerate with \`npm run build\`. The verify script rebuilds this file and diffs, so an edit
 * made here is lost at the next build and fails the verify script in the meantime — the same
 * arrangement as public/assets/css/style.css and public/assets/js/.
 */
final class AssetManifest
{
    /** The stylesheet, versioned. */
    public const string STYLESHEET = '${stylesheet}';

    /** The entry point — the only \`<script>\` the site loads — versioned. */
    public const string SCRIPT = '${versioned(jsUrl(ENTRY))}';

    /**
     * @var list<string> Every module the entry reaches, versioned, sorted, the entry excluded.
     *
     * **Empty when the graph shipped as one bundle**, which is the feature switching itself off
     * rather than a manifest that failed to generate. A preload hint flattens the wave-at-a-time
     * walk an ES module tree is discovered by; one file has no walk left to flatten.
     * The shell maps over this, so empty emits no links at all.
     */
    public const array MODULES = [${modules.length === 0 ? '' : `\n${
      modules.map((module) => `        '${module}',`).join('\n')}\n    `}];
}
`;

mkdirSync(dirname(MANIFEST), { recursive: true });
writeFileSync(MANIFEST, php, 'utf8');

console.log(BUNDLE === ''
  ? `build-assets: ${graph.size} modules at ${VERSION_PREFIX}${stamp}, ${modules.length} preloaded → ${label(MANIFEST)}`
  : `build-assets: ${graph.size} modules bundled into one at ${VERSION_PREFIX}${stamp}, none preloaded → ${label(MANIFEST)}`);
