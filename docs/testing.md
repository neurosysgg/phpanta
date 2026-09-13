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

- no routes of its own, so its table holds the framework's API route and nothing else;
- no data files beyond the framework's credentials;
- both of the framework's languages, English first;
- the standard vocabulary;
- a shell that is a document around a view, and nothing more;
- a plain-text `404` for its not-found page;
- a deployment that is a fixture directory, `test/fixture/app/`, holding an empty `public/`. The
  paths the framework derives from an app then have somewhere real to land, and never land in a
  repository.

`test/unit/` holds forty-one test classes, grouped by area:

| Area | Tests |
|---|---|
| the app | `AppTest` (booting, what it derives, every `webroot()` refusal), `FaultTest` (who is told how a request broke) |
| the API | `ApiTest` (the endpoint, its gate and its three services), `ApiClientTest` (signing against the real gate), `ApiCallTest`, `ApiTargetTest` |
| the push | `PushUpdateTest`, `FrameworkCheckoutTest`, `TarWriterTest`, `UpdateTest` |
| health and capability | `HealthTest`, `RequirementTest`, `CapabilityTest` |
| HTTP | `AnswerTest` (every answer, end to end, through `TestRequest`), `ResponseTest`, `RevalidationTest`, `FileResponseTest` (ranges and their headers), `JsonResponseTest` (the encoding's flags, a value that cannot encode), `StreamResponseTest` (chunks made at send time, never for a HEAD), `MimeTypeTest`, `RequestTest`, `SecurityHeadersTest`, `SecurityPolicyTest`, `SyntheticPageTest` |
| auth | `AuthTest` (the comparison, its timing, the gates, `PasswordHash`) |
| routing | `RouterTest`, `RouteTest`, `RoutingFeatureTest` (typed placeholders, method sets, `OPTIONS`, groups, what a request says back), `RouteExportTest`, `LayerTest` (the order layers run in, a route's past its method gate, the five that ship) |
| the markup tree | `MarkupTest` (building, escaping, the URL checks, parsing against a vocabulary) |
| the export | `ExportTest`, `BasePathTest` |
| text | `TextTest`, `LanguagesTest` |
| collections, files and diagnostics | `SupportTest` |
| the CLI layer | `CliTest` |
| the rules | `BoundaryTest`, `GuidelineTest`, `NoDiscardTest` — see [below](#the-rules-the-framework-holds-itself-to) |
| the framework's own site | `SitePagesTest`: every subheading in `site/data/` carries an anchor and links to it |

Ten fixtures sit beside the tests — `UpdateFixture`, `TextFixture`, `RoutePatternFixture`,
`ExportFixturePath`, `ReadonlyFixture`, `CliOptionFixture`, `TagFixture`, `AttributeFixture`,
`ClassFixture` and `EchoController` — with `PhpInputStream` standing in for `php://input`, which
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
that `Request::body()` answers instead of `php://input`, and nothing touches `$_SERVER`. A site's
suite uses it the same way against its own app.

`test/bootstrap.php` loads composer's autoloader: the framework's own when it is checked out alone,
the enclosing project's when it is vendored. It then loads the framework, its tooling, and
`test/autoload.php`, which maps `Phpanta\Test\` by hand. Last, it defines `PHPANTA_ROOT` and boots
`TestApp`. A site's own bootstrap requires `test/autoload.php` too, so its tests can use the fixtures
the framework's tests are built on (`UpdateFixture`, `TextFixture`) rather than keeping copies of
their own.

The suite is as strict as any suite built on it: `failOnWarning`, `failOnNotice` and
`failOnDeprecation` are on, and output during a test is a failure. Checked out on its own, the Pages
workflow (`.github/workflows/pages.yml`) runs the suite and `npm run check` before it builds and
publishes the framework's own site.

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

**The framework's suite alone covers nearly all of `src/`'s lines.** The figure was 98.79%
(2301 of 2329) when last derived on 2026-09-13. Re-derive it
with `XDEBUG_MODE=coverage vendor/bin/phpunit --coverage-text` rather than trusting this.

What it does not reach is what only a server reaches: `App::run()`, `Answer::send()` and
`SecurityHeaders::send()`, whose `header()` calls are a no-op under the CLI, and the branches behind
a failure no test can arrange. A vendoring site's end-to-end script covers the first kind over real
HTTP; merged through `tools/coverage-prepend.php` and `MergeCoverage`, the framework is covered but
for the second.
