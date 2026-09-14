# Testing — the framework

Phpanta has a suite of its own, and a site that vendors it has one too. The two suites answer
different questions: this one whether the framework works, the site's whether the site does — on
the framework, over real HTTP.

## The framework's suite

```bash
vendor/bin/phpunit -c phpanta/phpunit.xml.dist   # from a site that vendors Phpanta
vendor/bin/phpunit                               # from a checkout of Phpanta on its own; also `composer test`
```

`phpunit.xml.dist` runs `test/unit/` under **`Phpanta\Test\TestApp`**, never a site. That is the
whole point of the suite. A test that only passed with one site's languages, routes or data files
booted would be a test of that site. [`TestApp`](../test/TestApp.php) is the smallest thing that is
an app:

- no routes of its own, so its table holds the framework's four admin routes and nothing else;
- no data files beyond the framework's credentials;
- both of the framework's languages, English first;
- the standard vocabulary;
- a shell that is a document around a view, and nothing more;
- a plain-text `404` for its not-found page;
- a deployment that is a fixture directory, `test/fixture/app/`, holding an empty `public/`. The
  paths the framework derives from an app then have somewhere real to land, and never land in a
  repository.

`test/unit/` holds sixty-two test classes, grouped by area:

| Area | Tests |
|---|---|
| the app | `AppTest` (booting, what it derives, every `webroot()` refusal), `FaultTest` (who is told how a request broke) |
| the admin | `ApiTest` (every depth, its gate — one answer to a stranger at every depth and under every verb, whether the address exists or not — and its services), `AdminBrowserTest` (a browser let in: real P-256 ceremonies end to end through the controller — the entrance's one challenge, an unlock, a registration's code, the throttle, development's own origin, a write's form and its tap, a revoked passkey opening nothing at once), `AccessTest` (an enrolment code becoming a device, a dry run writing nothing, the devices listed and one revoked), `PasskeyTest` (what an assertion and a registration are checked for, a challenge answerable only for what it was minted for, the store, an enrolment code opening only where and while it should), `DiscoveryTest` (what each service, version and action says of itself, and the listings built from it), `NegotiationTest` (a page, data or a `406`, chosen by `Accept`), `ApiResultTest` (one answer as a terminal's text, a script's data and a browser's page), `ApiClientTest` (signing against the real gate), `ApiCallTest`, `ResultReaderTest` and `ListingReaderTest` (what the CLI reads back out of an answer and a listing), `ApiTargetTest` |
| the push | `PushUpdateTest`, `FrameworkCheckoutTest`, `TarWriterTest`, `UpdateTest`, `RollbackTest` (what a push records of the release it replaces, and putting it back — or refusing to, once the deployment has moved on), `ProbeTest` (what the deployment's filesystem lets a push do, measured and taken away again) |
| health and capability | `HealthTest`, `RequirementTest`, `CapabilityTest` |
| HTTP | `AnswerTest` (every answer, end to end, through `TestRequest`), `ResponseTest`, `RevalidationTest`, `FileResponseTest` (ranges and their headers), `JsonResponseTest` (the encoding's flags, a value that cannot encode), `StreamResponseTest` (chunks made at send time, never for a HEAD), `MimeTypeTest`, `RequestTest`, `InputTest` (the query and the form, by parameter and by type — and the API reading neither), `UploadTest` (a file kept, refused by whose fault it was, a 413, and kept into place or left where it was), `SecurityHeadersTest`, `SecurityPolicyTest`, `SyntheticPageTest`, `SitemapTest` (every exported page, absolute, and no sitemap without an origin) |
| forms | `FormTest` (fields, rules, files, a submission read and refused, the form re-rendered with what was entered and its errors) |
| data | `DatabaseTest` (opening, statements and their parameters, typed rows, transactions, migrations). It needs `pdo_sqlite`, and its database tests are skipped, not failed, without it |
| auth | `AuthTest` (the comparison, its timing, the gates, `PasswordHash`), `SessionTest` (the sealed cookie kept, opened, refused and expired; the form token; the login gate), `SessionAdminTest` (the admin's unlock, spent challenge and eight hours, and what reads as neither), `LoginTest` (a login, counted by address and name), `LoginRecipeTest` (the login page of [login.md](login.md), walked end to end with its cookie carried from answer to request), `ThrottleTest` (the sliding window, failing closed), `RateLimitTest` (the 429 and its `Retry-After`) |
| routing | `RouterTest`, `RouteTest`, `RoutingFeatureTest` (typed placeholders, method sets, `OPTIONS`, groups, what a request says back), `RouteExportTest`, `LayerTest` (the order layers run in, a route's past its method gate, the five that ship) |
| the markup tree | `MarkupTest` (building, escaping, the URL checks, parsing against a vocabulary) |
| the export | `ExportTest`, `BasePathTest` |
| text | `TextTest`, `LanguagesTest`, `LanguageAddressTest` (an address per language, in both modes, and a link that follows the page's language) |
| collections, files and diagnostics | `SupportTest` |
| the CLI layer | `CliTest` |
| the rules | `BoundaryTest`, `GuidelineTest`, `NoDiscardTest` — see [below](#the-rules-the-framework-holds-itself-to) |
| the framework's own site | none here: it has a suite of its own, see [below](#the-sites-own-suite) |

Thirty-two fixtures sit beside the tests — `UpdateFixture`, `TextFixture`, `RoutePatternFixture`,
`ExportFixturePath`, `ReadonlyFixture`, `CliOptionFixture`, `TagFixture`, `AttributeFixture`,
`ClassFixture`, `ParameterFixture`, `SessionKeyFixture`, `EchoController`; the forms' `FieldFixture`,
`OtherFieldFixture`, `ChoiceFixture`, `UploadFieldFixture` and `RenamedFieldFixture`; the login
recipe's `LoginFieldFixture`, `LogoutFieldFixture`, `LoginPathFixture`, `LoginTextFixture`,
`UsersFixture`, `RecipePageFixture` and its three controllers; and the database's `TableFixture`, `NoteColumnFixture`,
`AuthorColumnFixture`, `PragmaColumnFixture`, `NoteFixture` and `MigrationFixture` — with
`PhpInputStream` standing in for `php://input`, which
`RequestTest` still reads through when it tests a request that was not built with a body.

**[`TestRequest`](../test/TestRequest.php) is the in-process client.** It builds the server
variables a real server would hand PHP — keyed through `ServerVariable` and
`RequestHeader::serverKey()`, never retyped — reads a `Request` out of them the way a real request
is read, and `->answer()` is `App::handle()`: the gate, the router, the controller and the security
headers, with nothing sent.

```php
$answer = TestRequest::to(HttpMethod::Post, '/')->answer();

self::assertSame(HttpStatusCode::MethodNotAllowed, $answer->status());
self::assertSame('GET, HEAD', $answer->header(ResponseHeader::Allow)?->value->render());
```

`->request()` hands the request itself to a gate or a controller, `->withBody()` gives it a body
that `Request::body()` answers instead of `php://input`, `->withField()` and `->withUpload()` make
it a multipart form as PHP would have parsed one — a file the test sends is its own to remove — and
nothing touches `$_SERVER`, `$_POST` or `$_FILES`. A site's
suite uses it the same way against its own app.

`test/bootstrap.php` loads composer's autoloader: the framework's own when it is checked out alone,
the enclosing project's when it is vendored. It then loads the framework, its tooling, and
`test/autoload.php`, which maps `Phpanta\Test\` by hand. Last, it defines `PHPANTA_ROOT` and boots
`TestApp`. A site's own bootstrap requires `test/autoload.php` too, so its tests can use the fixtures
the framework's tests are built on (`UpdateFixture`, `TextFixture`) rather than keeping copies of
their own.

The suite is as strict as any suite built on it: `failOnWarning`, `failOnNotice` and
`failOnDeprecation` are on, and output during a test is a failure. **It passes as root too**: a test
that needs a file permission to stop something first asks whether it did, and skips with the reason
when the process is one no permission stops. `unshare -r vendor/bin/phpunit` runs the suite as root
without being root, which is how that is checked. Checked out on its own, the Pages
workflow (`.github/workflows/pages.yml`) runs the suite and `npm run check` before it builds the
framework's own site, then the site's suite, then publishes it.

## The site's own suite

The framework's own site, in `site/`, is tested by a suite of its own: `site/phpunit.xml.dist`, run
with `npm run site:test` once `npm run site:build` has written the manifest its shell reads. It
cannot run inside the framework's suite — that one boots `TestApp`, and a process holds one app —
so `site/test/bootstrap.php` boots the site instead. `SitePagesTest` renders every page at every
address the export writes it at, through the site's own `App::handle()`, and pins:

- each page's `<html lang>`, its two alternates, its language switch, a header and a footer marked
  `data-language-bound`, and every on-site link leading to a page in the same language;
- every subheading linked to its own anchor, with the same anchors in both languages;
- a language the site does not offer, `rules.fr.html`, answered as the 404 it is;
- every word of every catalog written in both languages, with the same placeholders and no brace a
  `Sentence` would refuse;
- nothing a reader reads left in one language on the page in the other, but for a name.

## The rules the framework holds itself to

Three tests read the framework's code rather than running it, each over this tree alone, through
[`SourceTree`](../test/SourceTree.php) — a root directory and a namespace — and
[`SourceNames`](../test/SourceNames.php), which resolves every name a file writes the way PHP would:

- **`BoundaryTest`**: every class `src/` names is the framework's or PHP's own, and `tools/` and
  `test/` may add only what composer installed; every `{@link}` under `src/`, `tools/` and `test/`
  lands inside the framework. A site's class named anywhere here fails it, checked out alone or
  vendored.
- **`GuidelineTest`**: the five habits, see [guidelines.md](guidelines.md), with this tree's excuses
  pinned. The bare-string rule's word-in-two-classes clause is a question about a whole program, so
  the excuses the framework carries only because a site writes the same word are pinned apart, in a
  test of their own.
- **`NoDiscardTest`**: every builder, query and gate whose result must not be dropped carries
  `#[\NoDiscard]`, and says why.

A site that vendors the framework runs the same three rules over its own tree, with `SourceTree`
pointed at it, and keeps what only it can check: a grep over everything under `phpanta/` for its own
namespace, comments and docs included, and the links in the framework's documents staying inside it.

**The framework has no client tests of its own.** Its modules are compiled into a site's tree and
tested there, through that site's `main.js`, alongside the site's own elements. Here, `npm run check`
type-checks them.

## Coverage

Two rules, the same as in any suite built on this one:

- **A test that declares any `#[CoversClass]` records coverage for only those classes.** Name every
  class it exercises, including one it only calls. Otherwise that class reads 0% while running
  constantly.
- **Uncovered lines are a decision, not a budget.** A change that adds a guard covers it in the same
  commit. A guard no test can reach is deleted rather than covered by reflection.

**The framework's suite alone covers nearly all of `src/`'s lines.** The figure was 99.51%
(4658 of 4681) when last derived on 2026-09-14, with `pdo_sqlite` loaded; without it the database
tests skip and `Data/` reads as untested. Re-derive it
with `XDEBUG_MODE=coverage vendor/bin/phpunit --coverage-text` rather than trusting this.

What it does not reach is what only a server reaches: `App::run()`, `Answer::send()` and
`SecurityHeaders::send()`, whose `header()` calls are a no-op under the CLI, and the branches behind
a failure no test can arrange. A vendoring site's end-to-end script covers the first kind over real
HTTP; merged through `tools/coverage-prepend.php` and `MergeCoverage`, the framework is covered but
for the second.
