# Phpanta

A small full-stack web framework for plain PHP 8.5 and browser-native TypeScript, extracted from
[neuro.SYS](https://github.com/neurosysgg/neurosys-webspace) and still used by it. No runtime
dependencies, no template language, no composer on the server: a site vendors Phpanta as a git
submodule, requires one autoloader, and deploys plain files.

It is opinionated in one direction throughout. Each rule replaces a habit that fails silently with
one that fails loudly:

- **Nothing builds HTML from a string.** A view returns a tree of nodes; only `Element` and
  `Doctype` write a `<`, and escaping and the URL-scheme check live in `render()`, so they hold
  however an element was built.
- **Names and values are typed.** A header is a `HeaderName` case and a `HeaderValue`; an attribute
  is an `AttributeName` case; an address is a `Path` case; a value with a grammar is a class.
- **A group crossing a public boundary is an immutable, lazy `Collection`.**
- **Visible text is a `Translatable`**, and a view never names a language: the tree puts every word
  into the nearest `lang` when it renders.
- **Every write to the deployment is signed.** `/admin/{service}/{version}/{action}` is the one
  address family that writes to it; a site's own forms post under a form token. A call is signed with an ECDSA key the server cannot use — the signing commands' own, or,
  in a browser, a passkey that key enrolled, tapped for each write — and a caller the admin cannot
  verify learns that it is there and nothing about what is in it.

## What it is made of

```
phpanta/
├── autoload.php     ← Phpanta\ → src/. The only part that ships, with src/
├── src/             ← the runtime
│   ├── App.php      ← what a site tells the framework about itself
│   ├── Http/        ← Request, Input, Upload, Session, Answer, the Response types, every header typed;
│   │                  Api/, Security/
│   ├── View/        ← View, Shell; Html/ — the markup tree, MarkupParser, the vocabularies
│   ├── Form/        ← a form as an enum of fields, its rules, a submission read and re-rendered
│   ├── Data/        ← SQLite through PDO: statements, typed rows, transactions, migrations
│   ├── Text/        ← Translatable, Translation, Language, Languages
│   ├── Support/     ← Collection, File, Directory, Path + Route, Throttle, TarArchive, PublicKey, …
│   ├── Model/       ← Health/ (requirements), Update/ (a push, the release it replaced), Api/
│   ├── Service/     ← Auth, Login, ApiGate, UpdateApplier, ReleaseRecord; Layer/ (what stands
│   │                  around a controller), Api/ and Health/ handlers
│   └── Exception/   ← SiteException and every condition under it
├── assets/ts/       ← Navigation (SPA), NestedElement, the mirrors of the framework's enums
├── tools/           ← build-{css,assets,prod}.mjs, dev-router.php, coverage-prepend.php, lib/
├── test/            ← the framework's own suite, under TestApp
├── site/            ← this framework's own site, built on it, in English and German, exported to GitHub Pages
└── docs/            ← the documents; start at architecture.md
```

## Hello, world

[`examples/hello/`](examples/hello/) is a whole site in seven files, with no build step, no
composer and nothing to configure. In a checkout of Phpanta:

```bash
php -S localhost:8082 -t examples/hello/public     # or: npm run hello:dev
```

`/` says *Hello, world!* — *Hallo, Welt!* to a browser that asks for German — and `/hello/Ada`
greets Ada and counts her letters by each language's own plural rules. Three files do the work.
An address is a case, and a link is that case filled in:

<!-- examples/hello/src/Hello/HelloPath.php -->
```php
<?php

declare(strict_types=1);

namespace Hello;

use Phpanta\Support\FillsPlaceholders;
use Phpanta\Support\Path;

/**
 * Every address the example answers on: the route matches the value, and a link fills it in.
 */
enum HelloPath: string implements Path
{
    use FillsPlaceholders;

    case World   = '/';
    case Someone = '/hello/{name}';
}
```

Every word is a case, in both languages at once — ICU picks *one letter* or *3 letters*, *einen
Buchstaben* or *3 Buchstaben*:

<!-- examples/hello/src/Hello/HelloText.php -->
```php
<?php

declare(strict_types=1);

namespace Hello;

use Phpanta\Text\Translatable;
use Phpanta\Text\Translated;
use Phpanta\Text\Translation;

/**
 * Every word the example says, in both of its languages.
 */
enum HelloText: string implements Translatable
{
    use Translated;

    #[Translation(en: 'Hello, world!', de: 'Hallo, Welt!')]
    case World = 'world';

    #[Translation(en: 'Now greet {someone}.', de: 'Jetzt grüß {someone}.')]
    case GreetSomeone = 'greet-someone';

    #[Translation(en: 'Hello, {name}!', de: 'Hallo, {name}!')]
    case Someone = 'someone';

    #[Translation(
        en: 'Your name has {letters, plural, one {one letter} other {# letters}}.',
        de: 'Dein Name hat {letters, plural, one {einen Buchstaben} other {# Buchstaben}}.',
    )]
    case Letters = 'letters';

    #[Translation(en: 'Back to the world', de: 'Zurück zur Welt')]
    case Back = 'back';

    #[Translation(en: 'Nobody lives here.', de: 'Hier wohnt niemand.')]
    case Nobody = 'nobody';
}
```

And a page is a tree, never a string, which is why `/hello/<script>` greets a `<script>` rather
than running one:

<!-- examples/hello/src/Hello/Greeting.php -->
```php
<?php

declare(strict_types=1);

namespace Hello;

use Phpanta\Text\Translatable;
use Phpanta\View\Html\Element;
use Phpanta\View\Html\HtmlAttribute;
use Phpanta\View\Html\HtmlTag;
use Phpanta\View\Html\Node;
use Phpanta\View\Html\Sentence;
use Phpanta\View\View;

/**
 * The page: a tree of nodes, never a string, so a name with markup in it is only ever text.
 */
final class Greeting extends View
{
    /** Whom the world page suggests greeting next. */
    private const string SOMEONE = 'Ada';

    /**
     * @param string|null $name
     */
    public function __construct(private ?string $name = null) {}

    /**
     * @return Translatable
     */
    public function pageTitle(): Translatable
    {
        return self::title($this->name);
    }

    /**
     * @return Node
     */
    public function content(): Node
    {
        if ($this->name === null) {
            return new Element(HtmlTag::Main)->containing(
                new Element(HtmlTag::H1)->containing(HelloText::World),
                new Element(HtmlTag::P)->containing(new Sentence(
                    HelloText::GreetSomeone,
                    someone: new Element(HtmlTag::A)
                        ->attr(HtmlAttribute::Href, HelloPath::Someone->to(self::SOMEONE))
                        ->containing(self::SOMEONE),
                )),
            );
        }

        return new Element(HtmlTag::Main)->containing(
            new Element(HtmlTag::H1)->containing(HelloText::Someone->with(name: $this->name)),
            new Element(HtmlTag::P)->containing(HelloText::Letters->with(letters: mb_strlen($this->name))),
            new Element(HtmlTag::A)->attr(HtmlAttribute::Href, HelloPath::World->to())->containing(HelloText::Back),
        );
    }
}
```

The rest is [the app](examples/hello/src/Hello/Hello.php) — everything the framework asks of a
site, answered in one class — [a controller](examples/hello/src/Hello/Greet.php),
[the autoloader](examples/hello/autoload.php) and [`public/index.php`](examples/hello/public/index.php).
What it never wrote a line for comes anyway: the security headers, a 405 for a write, a 404 in the
visitor's language, and the admin at `/admin`. [Its suite](examples/hello/test/HelloTest.php) asks
all of it in-process — `npm run hello:test`.

**These blocks are the files, not copies of them.** `ReadmeTest` fails the moment one changes
without the other, so this page cannot go on showing a site that no longer runs.

## Using it in a site

A site is laid out by convention rather than configuration:

```
site/
├── autoload.php          ← require_once phpanta/autoload.php, then the site's own prefix, then boot
├── composer.json         ← psr-4: the site's namespace → src/<Ns>/, and Phpanta\ → phpanta/src/
├── public/index.php      ← require the autoloader, install a last-resort handler, <App>::current()->run()
├── src/<Ns>/             ← the site: its App subclass, controllers, views, models, words
├── data/                 ← what the site reads at runtime, outside the webroot
├── assets/ts/phpanta     ← a symlink to ../../phpanta/assets/ts; tsconfig sets preserveSymlinks
├── assets/{ts,css}/      ← the site's own front end
└── phpanta/              ← this directory, as a git submodule
```

[Hello, world](#hello-world) is the smallest one that runs, laid out this way with `../..` for
`phpanta/`. [`site/`](site/), the framework's own site, is a larger one with a front end: its
[`autoload.php`](site/autoload.php), [`public/index.php`](site/public/index.php) and
[`Site.php`](site/src/PhpantaSite/Site.php) are where a site with assets starts from, and
`npm run site:dev` serves it.

Vendoring it is a submodule and four lines of wiring:

```bash
git submodule add ../phpanta.git phpanta          # relative, so every remote finds its own copy
ln -s ../../phpanta/assets/ts assets/ts/phpanta
```

```php
// autoload.php
require_once __DIR__ . '/phpanta/autoload.php';
// … the site's own spl_autoload_register for its namespace …
Acme\Blog::boot();
```

### Working in a vendored copy

The point of a submodule over a package is editing the framework where the site uses it. A change is
two commits — one in `phpanta/`, then `git add phpanta` in the site to record it — and three settings
make the pair behave like one repository:

```bash
git config submodule.recurse true              # checkout and pull move the framework with the site
git config push.recurseSubmodules on-demand    # a push sends the framework commit the site records
git config status.submoduleSummary true        # status says what moved in phpanta/
```

`push-update` refuses a framework that is not checked out, has changes that are not committed, or is
not the commit the site's `HEAD` records — each would put code on a server that no checkout of the
site reproduces. `--any-framework` is the deliberate way past.

Two things the settings cannot do: checking out or bisecting across the commit where a directory
became the submodule needs `--no-recurse-submodules`, and after a remote's URL changes,
`git submodule sync` carries it into the submodule.

### The app

A site is a subclass of `Phpanta\App`, booted once per process by its autoloader and read back with
`App::current()`. Constructing one does nothing, booting it twice is booting it once, and a second
app is refused. What a site owes the framework:

| Method | Answers |
|---|---|
| `name()` | the site's name: the Basic Auth realm, the title suffix |
| `above()` | the deployment directory — the one holding `autoload.php` |
| `routes()` | the site's routes; the framework appends its four admin routes |
| `notFound()` | the site's 404 page |
| `languages()` | the languages it is written in, its default first |
| `shell()` | the document every page is rendered inside |
| `vocabulary()` | the tags and attributes hand-authored markup may be parsed into |
| `buildId()` | which build is deployed, for `update v1 version` |
| `ownDataFiles()` | the site's own files under `data/` |

and what it may add, each with a default:

| Method | Answers | By default |
|---|---|---|
| `contentHosts()` | third-party origins in the CSP, per fetch directive | none |
| `strictTransportSecurity()`, `permissionsPolicy()` | the HSTS policy and the `Permissions-Policy` | at their strictest |
| `crossOriginOpenerPolicy()`, `crossOriginResourcePolicy()` | the two cross-origin policies | `same-origin` |
| `origin()` | where the site is served from — a sitemap and the admin's passkeys need it | none |
| `languageAddresses()` | whether each language has an address of its own, for a host that cannot choose | shared |
| `layers()` | what stands around every request | none |
| `ownRequirements()` | what it needs of its host beyond the framework's floor | none |
| `environment()` | development or production — override only to pin production | the server's `PHPANTA_ENVIRONMENT` |

The framework derives the rest — `data/`, the webroot, the update serial, the session key, the error
log, the route table — and those derivations are final.

### Building and testing

The build tools find the project by walking up from where they are run to the nearest
`composer.json`, and read the site's namespace from its `psr-4`:

```bash
node phpanta/tools/build-css.mjs         # assets/css/main.css → public/assets/css/style.css
node phpanta/tools/build-assets.mjs      # the stamped AssetManifest the shell reads
node phpanta/tools/build-prod.mjs        # build/dist/ — bundled, minified, no maps
php -S localhost:8080 -t public phpanta/tools/dev-router.php
```

```bash
vendor/bin/phpunit -c phpanta/phpunit.xml.dist   # the framework's own suite, under TestApp
```

### A static export

A site whose pages need no server can be exported to plain files, for a host that only serves
them:

```bash
php phpanta/tools/export.php --out build/pages --base /phpanta/
```

Every route that only reads and is one address is rendered by its own controller — a route with
placeholders says which values to export — and written as `x.html`, with the app's not-found page as
`404.html` and the stamped asset directories as directories. `--base` moves every address under the
path the host serves the site at, and the export fails on any link it did not write — a page, or
an anchor on one. An app whose languages have addresses of their own gets each page once more in
each — `x.en.html`, `x.de.html` — and on a host that cannot choose a language, the client's
`LanguageChoice` picks the visitor's.
[This framework's own site](https://neurosysgg.github.io/phpanta/) is that command's output, in
English and German, every page a markup tree its view builds: its source is [`site/`](site/), and
`.github/workflows/pages.yml` tests, builds and publishes it on every push.

## Working on Phpanta on its own

```bash
composer install && vendor/bin/phpunit    # the framework's suite
npm run hello:test                        # the example's suite, under its own app
npm install && npm run check              # the framework's TypeScript, type-checked
npm run site:build && npm run site:dev    # its site, served at localhost:8081
npm run site:test                         # the site's own suite: every page, every address, both languages
npm run site:prod && npm run site:export  # the export GitHub Pages serves, into build/pages/
```

## Documents

| Document | Read before touching |
|---|---|
| [docs/architecture.md](docs/architecture.md) | the request, the app, the layers, exceptions, the markup tree |
| [docs/collections.md](docs/collections.md) | `Collection` / `SearchableCollection`, or anything that holds a group |
| [docs/guidelines.md](docs/guidelines.md) | a bare array, a bare string, an `array_*` call, an `@`, a `throw` |
| [docs/language.md](docs/language.md) | any visible word, `Translation`, `Languages`, `Request::language()` |
| [docs/frontend.md](docs/frontend.md) | the build, the element model, SPA navigation |
| [docs/security.md](docs/security.md) | headers, the method gate, the markup tree's guards, the API |
| [docs/login.md](docs/login.md) | a login page — the recipe that puts `Form`, `Session`, the two guards and `Login` together |
| [docs/health.md](docs/health.md) | the `health` and `capability` services, or a requirement to declare |
| [docs/data.md](docs/data.md) | `Phpanta\Data` — a database, a statement, a row, a migration |
| [docs/testing.md](docs/testing.md) | the framework's suite and `TestApp` |
| [docs/tooling.md](docs/tooling.md) | the CLI layer, the signed commands, the build tools |
| [docs/history/](docs/history/README.md) | nothing — it is how things got this way |

## License

MIT — see [LICENSE](LICENSE).
