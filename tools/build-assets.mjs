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
 * `tools/dev-router.php` under `php -S`. Those two are a mirror, and a site's end-to-end suite can
 * pin that they strip the same shape.
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
 * a URL in the manifest with no bytes behind it. A site's end-to-end suite can close the loop from
 * outside, by diffing the two manifests with the stamp normalised away.
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
import { mkdirSync, writeFileSync } from 'node:fs';
import { dirname, join, relative, resolve } from 'node:path';

import { ROOT, app, cli, read } from './build-cli.mjs';

const { fail, label, path } = cli('build-assets', ['js-dir', 'graph-dir', 'css', 'out', 'bundle']);

/** The URL prefixes public/assets/{js,css}/ are served under. */
const JS_BASE  = '/assets/js';
const CSS_BASE = '/assets/css';

/**
 * A comment, or a string literal — matched together, left to right, so a `/*` or a `//` inside a
 * string is read as part of the string it is in. Stripping comments alone once took everything from
 * a `'/assets/*'` to the next comment's end, imports and all, and the modules behind them went
 * missing from the preload list and the stamp while the build reported success.
 */
const COMMENT_OR_STRING = /'(?:[^'\\\n]|\\.)*'|"(?:[^"\\\n]|\\.)*"|`(?:[^`\\]|\\[\s\S])*`|\/\/[^\n]*|\/\*[\s\S]*?\*\//g;

/**
 * `source` with its comments taken out and its strings left as they are — what SPECIFIER reads.
 *
 * @param {string} source
 * @returns {string}
 */
function uncommented(source) {
  return source.replace(COMMENT_OR_STRING, (token) => (token.startsWith('/') ? '' : token));
}

/**
 * `source` with its comments taken out and every string emptied: the code alone, where a keyword is
 * a keyword and not a word some string holds.
 *
 * @param {string} source
 * @returns {string}
 */
function code(source) {
  return source.replace(COMMENT_OR_STRING, (token) => (token.startsWith('/') ? '' : '""'));
}

/** A static import or re-export in any spelling — minified onto a line of code with the rest. */
const ANY_IMPORT = /\bimport\b\s*[\w{*"]|\bexport\b[^;\n]*\bfrom\b/;

/**
 * `import x from 'y'`, `import 'y'` and `export … from 'y'` — the three forms that name a
 * dependency the browser must fetch. Bare `import()` is deliberately not matched: a dynamic import
 * is a decision to fetch later, and stamping it would undo the reason it was written that way.
 *
 * Anchored to a whole line, and `[^'"\n]` rather than `[^'"]`, because the loose version walked out
 * of `export class Config {` and into the string on the line below it, then reported the site's name as
 * an unresolvable import. tsc emits one statement per line and terminates each with a semicolon, so
 * requiring both costs nothing and makes that false match impossible.
 *
 * **Only those three forms**: an `import` its string directly follows, or an `import` or `export`
 * whose string follows `from`. Any string on an `export` line once matched, so
 * `export const glob = '/assets/*';` was read as a re-export of `/assets/*` and refused.
 */
const SPECIFIER = /^\s*(?:import\s*['"]|(?:import|export)\b[^'"\n]*?\bfrom\s*['"])([^'"\n]+)['"]\s*;\s*$/gm;

/**
 * The path segment carrying the build stamp. `public/.htaccess` and `tools/dev-router.php` both
 * strip `v-<8 hex>` directly under the asset root; a site's suite can pin that they agree.
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
       + '              The build refuses a cycle rather than preloading around one: break it.');
  }

  walking.add(file);

  const source = read(file) ?? fail(`${importedBy} imports ${label(file)}, which does not exist.\n`
                                  + '              Compile assets/ts/ with tsc — the committed JS is behind it.');

  const deps = [];

  for (const match of uncommented(source).matchAll(SPECIFIER)) {
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

// ── the stylesheet ──────────────────────────────────────────────────────────────────────────────

// Read here, before the stamp that hashes it, so a stylesheet that is not there is a sentence rather
// than a stack trace. The manifest needs nothing else from it but the path.
const css = read(CSS_FILE) ?? fail(`${label(CSS_FILE)} does not exist. Build the stylesheet first `
                                 + '(tools/build-css.mjs) — the manifest names it.');

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
 * The server strips the segment: `public/.htaccess` for production, `tools/dev-router.php` for
 * `php -S`. That pair is a mirror.
 */
const stamp = digest(
  (BUNDLE === ''
    ? [...graph.keys()].sort().map((file) => `${jsUrl(file)}\u0000${shipped(file)}`)
    : [`${JS_BASE}/main.js\u0000${bundled()}`])
    .concat(`${CSS_BASE}\u0000${css}`)
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

// An entry that reaches nothing is an app of one file — or a tree this cannot read, which is the
// build this guard was written about: minified, every import on a line of code, so SPECIFIER finds
// none and the manifest would name one file of many. The entry is asked whether it imports anything
// at all, to tell the two apart. Under --bundle the empty list is the expected answer.
if (BUNDLE === '' && modules.length === 0 && ANY_IMPORT.test(code(read(ENTRY) ?? ''))) {
  fail('main.js imports other modules, and none of its imports could be read — is the tree\n'
     + '              minified? Walk a readable one with --graph-dir, or say it is one file with --bundle.');
}

const stylesheet = versioned(`${CSS_BASE}/${relative(dirname(CSS_FILE), CSS_FILE)}`);

// ── the manifest ────────────────────────────────────────────────────────────────────────────────

const php = `<?php

declare(strict_types=1);

namespace ${APP.namespace};

/**
 * Generated by phpanta/tools/build-assets.mjs — do not edit.
 *
 * Every built asset the shell loads, as the URL the browser asks for, carrying the build's stamp.
 * The site's shell emits all three: the stylesheet, the entry script, and one
 * \`<link rel="modulepreload">\` per module so the graph is discovered in one round trip instead of
 * the wave-at-a-time walk an ES module tree is otherwise found by.
 *
 * The stamp is what lets \`public/.htaccess\` mark these \`immutable\` for a year. It is one hash
 * over every module's URL and shipped bytes — or the bundle's — and the stylesheet's, so a change to
 * any of them changes every URL here.
 *
 * These are the versioned URLs: the fact about which copy of each file, which is the build's. Where
 * each file lives is the site's own business, and stays where the site states it.
 *
 * Regenerate by running that tool from the project's root. An edit made here is lost at the next
 * build — the same arrangement as public/assets/css/style.css and public/assets/js/.
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
