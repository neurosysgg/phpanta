# Tooling — the framework

The command-line layer every tool is built on, and the second autoloader that keeps the tooling
off the server. A site's own commands are its own; neuro.SYS's are in [its tooling](https://github.com/neurosysgg/neurosys-webspace/blob/master/docs/tooling.md).

## The CLI layer

**`Command::options()` is not decoration.** It is what lets `Input` refuse a flag the command never
declared: a flag dropped in silence reports success and does nothing, which for `merge-coverage`
would mean a mistyped `--clover` writing no report. `getopt()` is not the answer: it stops at the
first non-option argument, and `composer coverage` passes both of its paths first.

The verify script asserts every class under `tools/lib/` loads, the way it already does for `src/`.
Nothing else reaches them — the CLI layer is outside the coverage source and the commands are run by
hand — so a namespace disagreeing with its path would otherwise surface the first time someone ran
the tool.

## A second autoloader, and it is not optional

A site's autoloader maps its namespace to `src/<Namespace>/`, and `deploy.sh` uploads `src/` with `--delete` — so a
tooling class under it would ship to Strato and join `phpunit.xml.dist`'s coverage source. Composer's
`autoload-dev` was the other candidate and was turned down for the reason `autoload.php` exists at
all: `stage-release` runs on a clone that has never seen `composer install`.

That autoloader is also what makes the typed design affordable. `phpcs` holds `tools/` to PSR-12,
where a class-like symbol needs a namespace *and* a file of its own, and one class per file costs
nothing when a set of commands shares one loader.

Two dependencies are declared rather than inherited:

- **`merge-coverage` needs `vendor/`**, and the library it needs is **`phpunit/php-code-coverage`,
  a `require-dev` entry in its own right** rather than whatever PHPUnit happens to drag in. It reads
  twelve classes out of it, `Serialization\Unserializer` among them; left transitive, a PHPUnit major
  bumping that constraint would break `composer coverage` with a class-not-found and nothing in
  `composer.json` to explain it. A direct dependency is declared or it is luck.
- **`release-track` needs `ext/curl`**, which is a `require-dev` entry for exactly that reason —
  `composer.json`'s `require` states what the *site* needs, and the site makes no outbound request at
  all. That is a property the verify script asserts, alongside the one that says curl is called in
  exactly one class, the way `Probe` is the one class that shells out.

## The static export

`php phpanta/tools/export.php --out <dir> [--base /path/] [--debug]`, run from the project it
exports, writes the site as files a static host can serve — which is how
[this framework's own site](https://neurosysgg.github.io/phpanta/) is published. It is the one
command the framework has an entry point for, because the booted app is all it needs.

- **Every page is the one the running site would send.** A route that only reads and is one
  address is a page; a route with placeholders is one page per value its `$exports` closure
  answers, and none without one — see `Route::exportedPaths()`. Each is answered by its own
  controller for a `Request::synthetic()` in the app's default language, and written with
  `ViewResponse::render()`, `send()`'s public twin, so there is no second renderer to drift. A
  route that claims to be a page and answers with anything but a `ViewResponse` and a 200 fails the
  export: a static host would serve whatever was written there as a 200.
- **`x.html` for `/x`, `index.html` for `/`, `404.html` for the app's not-found page** — the names
  a static host, and GitHub Pages in particular, looks for.
- **The prod tree by default.** It exports `build/dist/public/`, and loads `build/dist/`'s manifest
  before anything can autoload the working tree's, so every page names the bundled assets it ships
  with; `--debug` exports `public/` as it stands.
- **The stamped asset directories are written as directories**, because a static host has no
  rewrite to strip the stamp — and `.nojekyll`, because Pages otherwise hides every file whose
  name starts with an underscore. `*.php`, `.htaccess` and `.user.ini` stay behind.
- **The base path is applied to what was written, not at runtime.** `BasePath` puts `--base` in
  front of every attribute value and stylesheet `url()` that starts at the root, then reads every
  page back with a real HTML parser; an address still without the base, or one naming a file the
  export did not write, fails the export. Scripts are not rewritten — a script that builds an
  address from the root has to build it from a link on the page instead.
- **It empties `--out` only if an export wrote it**, which is what `.nojekyll` marks. Anything else
  that is not empty is refused, so a mistyped `--out .` cannot delete a repository.
