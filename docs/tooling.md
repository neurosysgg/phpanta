# Tooling — the framework

The command-line layer every tool is built on, and the second autoloader that keeps the tooling
off the server. A site's own commands are its own; neuro.SYS's are in [its tooling](https://github.com/neurosysgg/neurosys-webspace/blob/master/docs/tooling.md).

## The CLI layer

**`Command::options()` is not decoration.** It is what lets `Input` refuse a flag the command never
declared: a flag dropped in silence reports success and does nothing, which for `merge-coverage`
would mean a mistyped `--clover` writing no report. `getopt()` is not the answer: it stops at the
first non-option argument, and `composer coverage` passes both of its paths first.

**`Command::operands()` does the same for what is left**: an `Arity` — `none()`, `exactly(n)`,
`atLeast(n)`, `between(min, max)` — that `Input` holds the count to before the command runs, so a
stray word is refused rather than ignored. `Input` also refuses a value on a boolean flag
(`--dry-run=no` would otherwise read as `--dry-run`, the opposite of what was typed) and any `-x`
short form, and it reads `--` as the end of the options.

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
  export: a static host would serve whatever was written there as a 200. **A route behind a
  password has to say `fn() => []`**, because its controller ends the process under the CLI; the
  export notices, names the path and exits 1, where it would otherwise stop half written with
  status 0.
- **`x.html` for `/x`, `index.html` for `/`, `404.html` for the app's not-found page** — the names
  a static host, and GitHub Pages in particular, looks for. A page is written under its path
  **decoded**, because a host decodes the address before it looks for a file: `/pages/caf%C3%A9` is
  `pages/café.html`. A segment that decodes to nothing, to `.` or `..`, or to something holding a
  slash is refused, and so is a routed `/404`, which would fight the not-found page for its file.
- **The prod tree by default.** It exports `build/dist/public/`, and loads `build/dist/`'s manifest
  before anything can autoload the working tree's, so every page names the bundled assets it ships
  with; `--debug` exports `public/` as it stands.
- **The stamped asset directories are written as directories**, because a static host has no
  rewrite to strip the stamp — `.nojekyll`, because Pages otherwise hides every file whose name
  starts with an underscore, and `.phpanta-export`, the marker below. `*.php`, `.htaccess` and
  `.user.ini` stay behind.
- **The base path is applied to what was written, not at runtime.** `BasePath` puts `--base` in
  front of every root-absolute value of an attribute the app's vocabulary says holds a URL — each
  `AttributeName::isUrl()`, so a site's own `<cover-art fallback>` moves and a `title` that happens
  to start with a slash does not — and of every stylesheet `url()`, `@import` string and
  `image-set()` string. Then it reads every page back with a real HTML parser; an address still
  without the base, in any attribute, or one naming a file the export did not write, fails the
  export. Scripts are not rewritten — a script that builds an address from the root has to build it
  from a link on the page instead.
- **It empties `--out` only if an export wrote it**, which is what `.phpanta-export` marks — a name
  only the export writes, where `.nojekyll` can be in any Pages directory. It is written before the
  first page, so an export that failed half way is still one the next may empty. Anything else that
  is not empty is refused, and so is a directory holding `.git` whatever else it holds, so a
  mistyped `--out .` cannot delete a repository.
