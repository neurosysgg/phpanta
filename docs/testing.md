# Testing — the framework

Phpanta has a suite of its own, and a site that vendors it has one too. They answer different
questions, and for now the second still carries most of the weight.

## The framework's suite

```bash
vendor/bin/phpunit -c phpanta/phpunit.xml.dist   # from a site that vendors Phpanta
vendor/bin/phpunit                               # from a checkout of Phpanta on its own
```

`phpunit.xml.dist` runs `test/unit/` under **`Phpanta\Test\TestApp`**, never a site. That is the whole
point of the suite: a test that only passed with one site's languages, routes or data files booted
would be a test of that site. `TestApp` is the smallest thing that is an app —

- no routes of its own, so its table is the framework's API route and nothing else;
- no data files beyond the framework's credentials;
- both of the framework's languages, English first;
- the standard vocabulary;
- a shell that is a document around a view, and nothing more;
- a deployment that is a fixture directory, `test/fixture/app/`, holding an empty `public/` — so the
  paths the framework derives from an app have somewhere real to land, and never land in a repository.

`test/bootstrap.php` loads composer's autoloader — the framework's own when it is checked out alone,
the enclosing project's when it is vendored — then the framework, its tooling, and
`test/autoload.php`, which maps `Phpanta\Test\` by hand. A site's own bootstrap requires that file
too, so its tests can use the fixtures the framework's are built on — `UpdateFixture`,
`TextFixture` — rather than keeping copies of their own.

The same strictness as any suite built on it: `failOnWarning`, `failOnNotice`,
`failOnDeprecation`, and output during a test is a failure.

## What still lives in the site

The framework moved out of neuro.SYS, and the checks that watched it while it lived there moved
only as far as they had to. Each of these still runs in neuro.SYS's suite and reads **both** source
trees — the site's and `phpanta/` — so the framework is held to them whether or not it is checked
out on its own:

| Check | What it holds the framework to |
|---|---|
| `BoundaryTest` | nothing under `phpanta/src/` names a site class, by any kind of name |
| the verify script | the same for everything under `phpanta/`, comments and docs included; the framework's classes all load; no markup from a string, no outbound request, one `openssl_` caller |
| `GuidelineTest` | the five habits — see [guidelines.md](guidelines.md) |
| `NoDiscardTest` | every builder and query that must not be discarded, and why |
| `TranslationTest` | every translated enum is reachable from the site's index, and written in every language |
| `SecurityPolicyTest` | every header value is a typed object, and every one is covered |

The front end's tests stay in the site's `test/js/` too: they load the compiled tree a site builds,
and Phpanta's modules are part of it.

Moving these is deliberate future work rather than an oversight. Each needs a way to be pointed at
a tree it does not own, and until the framework has a second site to be pointed at, the one that
exists is the better test.

## Coverage

Two rules, the same as in any suite built on this one:

- **A test that declares any `#[CoversClass]` records coverage for only those classes.** Name every
  class it exercises, including one it only calls — or that class reads 0% while running constantly.
- **Uncovered lines are a decision, not a budget.** A change that adds a guard covers it in the same
  commit; a guard no test can reach is deleted rather than covered by reflection.

**The framework's suite alone covers 32.73% of `src/`'s lines** (603 of 1842), derived on 2026-09-13
at neuro.SYS's `58efaac`. That is low, and it is honest: the suite is the tests that name no site
class, and most of the framework is still exercised by tests that do. Merged with neuro.SYS's suite
and the HTTP requests its verify script makes, the same code is covered almost entirely — see
[neuro.SYS's figure](https://github.com/neurosysgg/neurosys-webspace/blob/master/docs/testing.md#php).
The number that measures the framework is the merged one until the site's framework-level tests
move here.
