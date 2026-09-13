# Tooling — the framework

The command-line layer every tool is built on, the commands the framework ships, and the second
autoloader that keeps the tooling off the server. A site's own commands are its own.

## What is here

```
tools/
├── autoload.php          ← Phpanta\Tool\ → tools/lib/
├── export.php            ← the one entry point the framework has; see The static export
├── dev-router.php        ← php -S's router; see frontend.md
├── coverage-prepend.php  ← auto_prepend_file that records an end-to-end server's coverage
├── build-cli.mjs  build-css.mjs  build-assets.mjs  build-prod.mjs   ← the build; see frontend.md
└── lib/
    ├── Cli/              ← Command, Option, Arity, Input, Output, ExitCode, UsageException, Runner
    ├── Command/          ← ApiCall, PushUpdate, MergeCoverage, Export, each with its option enum
    ├── Api/              ← the signing side: ApiTarget, PrivateKey, SignedCredential, SignedRequest,
    │                       and ResultReader and ListingReader, which read an answer back
    ├── Http/             ← outbound requests: Transport + CurlTransport, Request/Response, Url,
    │                       JsonBody, FormField, FilePart, OutboundHeader
    ├── Update/           ← the push side: TarWriter, PackedFile, FrameworkCheckout. The reader
    │                       lives under src/ because the server needs it; the writer lives here
    │                       because the server must not have it
    ├── Export/           ← BasePath
    └── Php/              ← an expression tree for emitting PHP source, so none is built from a string
```

**Three of the four commands need something only a site knows**, so the framework ships them as
classes, and a site gives each one an entry script of its own:

- `ApiCall` takes the deployment's origin and the path of its key, relative to `$HOME`;
- `PushUpdate` takes the project root as well;
- `MergeCoverage` takes the root whose `src/` it measures.

Each entry script is a few lines long:

```php
Runner::run(new ApiCall('https://example.test', '.config/example/update.key'), $argv);
```

`Export` needs only the booted app, so it is the framework's own entry point, `tools/export.php`.

**The signed commands ask for data and print text.** `SignedRequest` sends `Accept:
application/json`, and `ResultReader` reads the answer back into the server's own `ApiResult`, whose
`text()` is what the terminal shows. So the report a terminal reads is written by the model that
wrote the data, not by a second formatter here, and the keys it reads are the server's own
`ResultKey` cases. `ListingReader` does the same for a listing, one line an entry.

**`ApiCall` takes up to three operands, and fewer than three is a question.** `api`, `api update`
and `api update v1` each ask the server, as a signed `GET`, what it offers at that depth, and print
its listing: services, versions, or actions with their method and description. A whole address runs
the action, and that one the local enums must know — a listing says what the server has, and the
command signs only what it can name.

**A refusal is explained as what it is.** A `401` is the admin's one answer to a request it cannot
verify, which does not say which check failed; `ApiCall` lists the two a signing machine can check,
a key that does not match `data/update.pub` and a clock more than five minutes out, and `PushUpdate`
adds a third, another call signed in the same second. An answer that is neither a result nor a
listing — a site's own 404 page — is not the admin's at all, and the command says the server is
older than `/admin`, which a full deploy updates.

`dev-router.php` and `coverage-prepend.php` are not commands, and cannot be. PHP loads each of them
itself: one is handed to `php -S` per request, and the other is an `auto_prepend_file`. Neither has
an argv or an exit code for the interface to attach to.

## The CLI layer

**`Command::options()` is not decoration.** It is what lets `Input` refuse a flag the command never
declared. A flag dropped in silence reports success and does nothing, which for `merge-coverage`
would mean a mistyped `--clover` writing no report. `getopt()` is not the answer, because it stops
at the first non-option argument, and a coverage script passes `merge-coverage` its paths first.

**`Command::operands()` does the same for what is left.** It returns an `Arity` (`none()`,
`exactly(n)`, `atLeast(n)` or `between(min, max)`), and `Input` holds the operand count to it before
the command runs, so a stray word is refused rather than ignored. `Input` also refuses:

- a value on a boolean flag, since `--dry-run=no` would otherwise read as `--dry-run`, the opposite
  of what was typed;
- any `-x` short form.

It reads `--` as the end of the options.

Only a person runs these classes: the CLI layer is outside the coverage source, and the commands are
run by hand. So a vendoring site's suite should assert that every class under `phpanta/tools/lib/`
loads. Otherwise a namespace disagreeing with its path surfaces only the first time someone runs the
tool.

## A second autoloader, and it is not optional

A site's autoloader maps its namespace to `src/<Namespace>/`. A site's deploy uploads `src/`
wholesale, and a push carries `phpanta/src/` too, so a tooling class under either would ship to the
server and join PHPUnit's coverage source. Composer's `autoload-dev` was the other candidate. It was
turned down for the reason `autoload.php` exists at all: a command has to run on a clone that has
never seen `composer install`. `tools/autoload.php` maps `Phpanta\Tool\` to `lib/`, and a site's own
tooling autoloader requires it.

That autoloader is also what makes the typed design affordable. Under PSR-12, a class-like symbol
needs a namespace *and* a file of its own, and one class per file costs nothing when a set of
commands shares one loader.

Two dependencies are declared rather than inherited. The project that runs the commands declares
them, which for a vendoring site is its own `composer.json`:

- **`MergeCoverage` needs `vendor/`**, and the library it needs is **`phpunit/php-code-coverage`,
  a `require-dev` entry in its own right** rather than whatever PHPUnit happens to pull in. It
  imports nine classes from that library, `Serialization\Unserializer` among them. Left transitive,
  a PHPUnit major that bumped the constraint would break the merge with a class-not-found, and
  nothing in `composer.json` would explain it. A direct dependency is either declared, or it works
  by luck.
- **`ApiCall` and `PushUpdate` need `ext/curl`**, through `CurlTransport`, the one class that calls
  curl. That makes it a `require-dev` entry, because `require` states what runs on the server, and
  nothing under `src/` makes an outbound request.

Phpanta's own `composer.json` declares neither. Checked out on its own, it runs these commands only
in its tests, over a `Transport` that answers from memory.

## The static export

`php phpanta/tools/export.php --out <dir> [--base /path/] [--debug]` writes the site as files a
static host can serve. It is run from the project it exports, and it is how the framework's own
site, in `site/`, is published to GitHub Pages. It is the one command the framework has an entry
point for, because the booted app is all it needs.

- **Every page is the one the running site would send.** A route that only reads and has one
  address is a page. A route with placeholders is one page per value its `$exports` closure answers,
  and no page at all without such a closure; see `Route::exportedPaths()`. Each page is answered by
  its own controller for a `Request::synthetic()` in the app's default language, and written with
  `ViewResponse::render()`, the body `answer()` would send, so there is no second renderer to drift.
  - **A route that claims to be a page but answers with anything other than a `ViewResponse` and a
    200 fails the export.** A static host would serve whatever was written there as a 200.
  - **A route behind a password says `fn() => []`.** The export's request carries no credential, so
    the controller answers its 401, and the export fails naming the path and the status rather than
    writing the refusal to a file a static host would serve as the page.
- **Files are named the way a static host looks for them**: `x.html` for `/x`, `index.html` for `/`,
  and `404.html` for the app's not-found page. GitHub Pages in particular looks for those names.
  - **A page is written under its path decoded**, because a host decodes the address before it looks
    for a file: `/pages/caf%C3%A9` becomes `pages/café.html`.
  - **Some paths are refused**: a segment that decodes to nothing, to `.` or `..`, or to something
    holding a slash, and a routed `/404`, which would fight the not-found page for its file.
  - **An app whose languages have addresses of their own gets each page once more per language**:
    `rules.en.html` and `rules.de.html` beside `rules.html`, and `index.de.html` for the root, each
    rendered for the address it is written at, so it is in that language and its links are too.
    `rules.html` stays the default language's way in. See [language.md](language.md#addresses-in-each-language).
- **It exports the prod tree by default.** It exports `build/dist/public/`, and loads `build/dist/`'s
  manifest before anything can autoload the working tree's, so every page names the bundled assets
  it ships with. `--debug` exports `public/` as it stands.
- **The stamped asset directories are written as directories**, because a static host has no rewrite
  to strip the stamp. Two marker files go with them:
  - `.nojekyll`, because Pages otherwise hides every file whose name starts with an underscore;
  - `.phpanta-export`, the marker described below.

  `*.php`, `.htaccess` and `.user.ini` stay behind.
- **The base path is applied to what was written, not at runtime.** `BasePath` puts `--base` in
  front of every root-absolute value in two kinds of place:
  - every attribute the app's vocabulary says holds a URL (each `AttributeName::isUrl()`), so a
    site's own URL-valued attribute moves and a `title` that happens to start with a slash does not;
  - every stylesheet `url()`, `@import` string and `image-set()` string.

  Then it reads every page back with a real HTML parser. An address still without the base, in any
  attribute, fails the export, and so does one naming a file the export did not write, and so does
  a link to an anchor — `#x` on the same page, `/page#x` on another — whose page has no element
  with that id (`Anchors`), looked up as written and then percent-decoded. Scripts are
  not rewritten, so a script that builds an address from the root has to build it from a link on the
  page instead.
- **It empties `--out` only if an export wrote it**, which is what `.phpanta-export` marks. That is a
  name only the export writes, whereas `.nojekyll` can be in any Pages directory. The marker is
  written before the first page, so an export that failed halfway is still one the next export may
  empty. Any other directory that is not empty is refused. So is a directory holding `.git`,
  whatever else it holds, so a mistyped `--out .` cannot delete a repository.
