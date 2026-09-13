/**
 * The command-line plumbing phpanta/tools/build-*.mjs share: a way to fail, a way to name a file, and an
 * argv parsed against the flags a tool actually declares.
 *
 * **This is `Phpanta\Tool\Cli` on the other side of the language boundary**, and it is here for the
 * reason that layer exists. `Cli\Command::options()`'s docblock names the failure: both hand-rolled
 * parsers the PHP tooling had grown *"dropped an unrecognised flag in silence, which for
 * merge-coverage meant a mistyped `--clover` reported success and wrote no report"*. The three
 * builders were the parsers nobody came back for — each one its own
 * `process.argv.indexOf('--out')`, so `node tools/build-css.mjs --ou scratch.css` overwrote the
 * committed stylesheet and said it had done what was asked.
 *
 * `fail` and `label` were copied into all three files besides. Neither is interesting; both being
 * in three places is.
 *
 * **Every flag here takes a path**, which is not a simplification of a general parser — it is the
 * whole vocabulary these tools have. `--out`, `--css`, `--js-dir` and `--graph-dir` are the four
 * that exist, and a flag that stood alone would be a different kind of tool. The PHP side has
 * `Option::takesValue()` because `--check` and `--upload` are real there; nothing here needs it,
 * and inventing it would be a case with nothing on the other end of it.
 *
 * No dependencies, and nothing runs on import: these tools have to work on a clone that has never
 * seen `npm install`, which is why `test/basic_test.sh` can rebuild the stylesheet on a bare
 * checkout while it skips everything that needs `tsc`.
 */

import { existsSync, readFileSync } from 'node:fs';
import { dirname, join, relative, resolve } from 'node:path';

/**
 * The project being built: the nearest directory, at or above the one the tool was run from, that
 * holds a `composer.json`.
 *
 * **Not where this file sits.** That is the framework's tooling, vendored into the project as
 * `phpanta/`, and a root worked out from it would be `phpanta/` itself — every path below would
 * then name the framework's tree rather than the site's, and `build-assets` would write the site's
 * manifest somewhere nothing reads it. The project is where npm runs a script from and where the
 * verify script is run from; walking up is what lets a tool be run from a subdirectory as well.
 */
export const ROOT = findRoot(process.cwd());

/**
 * @param {string} from
 * @returns {string}
 */
function findRoot(from) {
  for (let directory = resolve(from); ; directory = dirname(directory)) {
    if (existsSync(join(directory, 'composer.json'))) {
      return directory;
    }

    if (dirname(directory) === directory) {
      console.error(`no composer.json at or above ${from} — run this from the project it builds.`);
      process.exit(1);
    }
  }
}

/**
 * The app being built: the namespace `composer.json` maps to a directory under `src/`, and so what
 * its generated manifest is declared in and where it is written.
 *
 * Read from `composer.json` because that is where the namespace is already stated — `autoload.php`
 * states it once more, for the server, which has no composer — so a third statement here would be
 * one more place a rename has to reach. **Exactly one entry qualifies, or this refuses**: fewer
 * means there is no app to build, and more would be a guess, and a wrong guess writes the manifest
 * into the wrong namespace with nothing anywhere saying so.
 *
 * @returns {{ namespace: string, manifest: string }}
 */
export function app() {
  const composer = JSON.parse(read(join(ROOT, 'composer.json')) ?? '{}');
  /** @type {[string, unknown][]} */
  const entries = Object.entries(composer?.autoload?.['psr-4'] ?? {}).filter(
    ([namespace, directory]) => namespace !== 'Phpanta\\'
      && typeof directory === 'string'
      && directory.startsWith('src/'),
  );
  const entry = entries[0];

  if (entries.length !== 1 || entry === undefined || typeof entry[1] !== 'string') {
    console.error(`composer.json maps ${entries.length} namespaces to src/, and a build needs exactly one.`);
    process.exit(1);
  }

  return { namespace: entry[0].replace(/\\+$/, ''), manifest: join(ROOT, entry[1], 'AssetManifest.php') };
}

/**
 * A file's text, or `null` for any reason it could not be read.
 *
 * `Support\File::read()` on the other side of the boundary, and collapsed for the same reason: a
 * file that is absent and a file that is present and unreadable are two causes every caller here
 * was already turning into one message. Answering `null` rather than throwing is what lets the
 * caller write `read(file) ?? fail(…)`, which is both the message and the narrowing in one line —
 * see `fail`'s note on why the `if` form does not narrow.
 *
 * @param {string} file
 * @returns {string | null}
 */
export function read(file) {
  try {
    return readFileSync(file, 'utf8');
  } catch {
    return null;
  }
}

/**
 * The three things a build tool needs from its command line.
 *
 * Parsing happens here rather than at the first `path()` call, so an unknown flag is refused before
 * the tool has read a file or deleted a tree — `build-prod.mjs` clears build/dist/ as its first
 * real act, and doing that on the way to reporting a typo would be the wrong order.
 *
 * @param {string} command The tool's name, which prefixes every message it writes.
 * @param {string[]} declared Every flag it accepts, without the dashes. Anything else is refused.
 * @returns {{fail: (message: string) => never, label: (file: string) => string,
 *            path: (name: string, fallback: string) => string}}
 */
export function cli(command, declared) {
  /**
   * Exits with a reason. Declared as returning `never` so it can stand in an expression — it is
   * `return`ed out of `build-assets.mjs`'s `shipped()`, where the alternative is a `catch` block
   * that falls off the end and hands `undefined` back as if it were a module's bytes.
   *
   * It is also what narrows a value the type checker cannot: `list[0] ?? fail(…)` is a `string`
   * where the same check written as an `if` around a `fail(…)` statement is not, because control
   * flow does not follow `never` through a destructured binding. The expression form is therefore
   * the one to reach for, and the three builders do.
   *
   * @param {string} message
   * @returns {never}
   */
  const fail = (message) => {
    console.error(`${command}: ${message}`);
    process.exit(1);
  };

  /**
   * Repo-relative, forward-slashed — what the markers and the error messages say.
   *
   * @param {string} file
   * @returns {string}
   */
  const label = (file) => {
    const path = relative(ROOT, file);

    return path.startsWith('..') ? file : path.split(/[\\/]/).join('/');
  };

  const given = new Map();
  const argv = process.argv.slice(2);

  for (let i = 0; i < argv.length; i++) {
    // `i < argv.length` is the proof that this is a string. noUncheckedIndexedAccess cannot read
    // a loop bound, and a guard for it would be the dead defensive branch this project refuses
    // elsewhere — so the cast states what the loop already guarantees.
    const argument = /** @type {string} */ (argv[i]);

    if (!argument.startsWith('--')) {
      fail(`'${argument}' is not an option. This tool takes flags only: ${list(declared)}.`);
    }

    const split = argument.indexOf('=');
    const name = split === -1 ? argument.slice(2) : argument.slice(2, split);

    if (!declared.includes(name)) {
      fail(`unknown option '--${name}'. This tool takes: ${list(declared)}.`);
    }

    // `--out=` and a bare `--out` at the end of the line are the same mistake typed two ways, so
    // they are refused together — the same rule `Cli\Input::parse()` states on the PHP side.
    const value = split === -1 ? argv[++i] : argument.slice(split + 1);

    if (value === undefined || value === '') {
      fail(`option '--${name}' needs a path.`);
    }

    given.set(name, value);
  }

  /**
   * A flag's value, resolved to an absolute path.
   *
   * **The fallback is required, so there is no such thing as a required flag here.** Every one of
   * the four these tools declare has a default — `--out` is where the committed artefact already
   * lives, `--js-dir` and `--css` are what a normal build reads — because the whole point of the
   * defaults is that `npm run build` takes no arguments at all. A branch for a flag with no default
   * would be a case with nothing on the other end of it.
   *
   * @param {string} name
   * @param {string} fallback Where the tool reads or writes when the flag is absent.
   * @returns {string}
   */
  const path = (name, fallback) => {
    const value = given.get(name);

    return value !== undefined ? resolve(value) : fallback;
  };

  return { fail, label, path };
}

/**
 * The declared flags, as a sentence names them.
 *
 * @param {string[]} declared
 * @returns {string}
 */
function list(declared) {
  return declared.map((name) => `--${name}`).join(', ');
}
