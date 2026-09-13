# Testing — the framework

Phpanta has a suite of its own, and a site that vendors it has one too. The two suites answer
different questions, and for now the second still carries some of the framework's weight.

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

`test/unit/` holds twenty-six test classes, grouped by area:

| Area | Tests |
|---|---|
| the API | `ApiTest` (the endpoint, its gate and its three services), `ApiClientTest` (signing against the real gate), `ApiCallTest`, `ApiTargetTest` |
| the push | `PushUpdateTest`, `FrameworkCheckoutTest`, `TarWriterTest`, `UpdateTest` |
| health and capability | `HealthTest`, `RequirementTest`, `CapabilityTest` |
| HTTP | `AnswerTest` (every answer, end to end, through `TestRequest`), `RequestTest`, `RevalidationTest`, `SecurityHeadersTest`, `SecurityPolicyTest`, `SyntheticPageTest` |
| routing | `RouteTest`, `RouteExportTest` |
| the export | `ExportTest`, `BasePathTest` |
| text | `TextTest`, `LanguagesTest` |
| collections, files and diagnostics | `SupportTest` |
| the CLI layer | `CliTest` |
| the framework's own site | `SitePagesTest`: every subheading in `site/data/` carries an anchor and links to it |

Six fixtures sit beside the tests: `UpdateFixture`, `TextFixture`, `RoutePatternFixture`,
`ExportFixturePath`, `ReadonlyFixture` and `CliOptionFixture`, with `PhpInputStream` standing in for
`php://input`.

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

## What still lives in a vendoring site

Phpanta grew inside a site, and the checks that watched it there moved only as far as they had to.
These still run in that site's suite and read **both** source trees, the site's and `phpanta/`. The
framework is held to them whether or not it is checked out on its own:

- **a boundary check**: nothing under `phpanta/src/` names a site class, by any kind of name;
- **a grep over everything under `phpanta/`** for the site's namespace, comments and docs included;
  that every framework class loads; and no markup from a string, no outbound request, one `openssl_`
  caller;
- **the five habits**, see [guidelines.md](guidelines.md);
- **every builder and query that must not be discarded** carries `#[\NoDiscard]`, and the reason
  why;
- **every translated enum** is reachable from the site's index and written in every language.

That every header value is a typed object, and every one covered, is `SecurityPolicyTest`'s here: it
reads `src/` alone.

**The framework has no client tests of its own.** Its modules are compiled into a site's tree and
tested there, through that site's `main.js`, alongside the site's own elements. Here, `npm run check`
type-checks them.

Moving the checks above into the framework is deliberate future work rather than an oversight. Each
needs a way to be pointed at a tree it does not own, and until then the site that vendors the
framework is the better test of it.

## Coverage

Two rules, the same as in any suite built on this one:

- **A test that declares any `#[CoversClass]` records coverage for only those classes.** Name every
  class it exercises, including one it only calls. Otherwise that class reads 0% while running
  constantly.
- **Uncovered lines are a decision, not a budget.** A change that adds a guard covers it in the same
  commit. A guard no test can reach is deleted rather than covered by reflection.

**The framework's suite alone covers about four fifths of `src/`'s lines.** The figure was 82.55%
(1708 of 2069) when last derived on 2026-09-13. Re-derive it
with `XDEBUG_MODE=coverage vendor/bin/phpunit --coverage-text` rather than trusting this.

The rest is honest too. Every response, the router, the gate and `App::handle()` are reached here
now, through `TestRequest`; what this suite still reaches less of — the markup tree above all — a
vendoring site's tests exercise, because they are written against that site's views, controllers
and route table. Merge that site's suite and the HTTP requests its end-to-end
script makes, through `tools/coverage-prepend.php` and `MergeCoverage`, and the same code is covered
almost entirely. Until those tests are rewritten against `TestApp`, the merged number is the one that
measures the framework.
