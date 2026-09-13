# Architecture — the framework

How a request travels through Phpanta, the layers it passes, and the disciplines every layer keeps.
A site's own layers — its controllers, models and views — are described in its own documents.
Every test named below is in `test/unit/`; [testing.md](testing.md) says what each area holds.

## The app

A site is a subclass of `Phpanta\App`, and there is exactly one per process, booted by the site's
autoloader and read back with `App::current()`. It is how a site's facts reach the framework: the
site gate, the API's serial, the health report and the CSP all sit at the bottom of call chains that
never had a reason to carry those facts, and asking the booted app is what lets them stay that way.
The framework's own tests boot `Phpanta\Test\TestApp` — the smallest thing that is one — from
`test/bootstrap.php`.

Three rules keep the static honest:

- **Constructing one does nothing** — no `DOCUMENT_ROOT`, no file, no request. Booting is therefore
  free, which is what lets the autoloader do it for every entry point at once.
- **Booting is idempotent and exclusive.** The same class twice is the same instance; a second class
  is refused with an `AppException`, because two apps in one process would each be reading the
  other's data.
- **Nothing caches what an app answers.** A test that needs a different answer passes a different
  object to the class that asks — `UpdateApplier` takes its `Deployment` — rather than swapping the
  booted app out from under everything else.

What a site owes, and what it may add:

| | Method | Answers |
|---|---|---|
| owes | `name()` | the Basic Auth realm, and the title a page is named under |
| | `above()` | the deployment directory — the one holding `autoload.php` |
| | `routes()` | the site's routes; `routeTable()` appends the framework's API route after them |
| | `notFound()` | the page for an address the site does not have, to a method that reads |
| | `languages()` | the languages it is written in, its default first |
| | `shell()` | the document every page is rendered inside |
| | `vocabulary()` | every tag and attribute hand-authored markup may be parsed into |
| | `buildId()` | which build is deployed, as `update v1 version` reports it |
| | `ownDataFiles()` | the site's own files under `data/`, beside the framework's credentials |
| may add | `contentHosts()` | third-party origins per CSP fetch directive; none by default |
| | `strictTransportSecurity()` | the HSTS policy; a year, subdomains included, by default |
| | `permissionsPolicy()` | the `Permissions-Policy`; every feature it knows denied by default |
| | `ownRequirements()` | what it needs of its host beyond the framework's floor; none by default |

**What the framework derives from those is final** — `data()`, `webroot()`, `updateSerial()`,
`dataFile()`, `dataFiles()`, `logs()`, `errorLog()`, `requirements()`, `routeTable()` and `run()` —
because each derivation was measured into its shape, and a site getting one of them slightly
different is how a mirror deletes the wrong tree. `webroot()` is the one path that is not derived:
the directory is `public/` in a repository and whatever the host calls it on the server, so it is
asked of `DOCUMENT_ROOT` — for its basename only, and refused with an `UpdateException` rather than
guessed when that is blank, relative, a dot segment, outside the deployment or absent.

`run()` is the request, in a fixed order: the error log, the security headers, the request, the site
gate, the route. The site's `public/index.php` installs a last-resort exception handler first — one
that depends on nothing, because it has to work when nothing else did — and then calls it.

## The request, traced

Follow one request all the way through. The app is `TestApp`, and the request is the one route its
table holds: **`GET /api/update/v1/version`**, the signed read that asks a deployment what it is
running. A site's page route takes the same road as far as the controller; where it parts is
[at the end](#a-page-instead).

### ⓪ The last-resort handler

**It is first because it is the one that has to work when nothing else did.** Without it an uncaught
throwable is a PHP fatal, which on a host whose `display_errors` the site does not own is either a
blank page with a 200 already on the wire or a stack trace naming absolute paths — a choice left to a
php.ini rather than made here. It logs the fault, sends a 500 if `headers_sent()` says there is still
a response to shape, and writes a body of exactly `500`. It depends on nothing: no `Response`, no
`MimeType`, no view, because reaching for the markup tree would be reaching for the most likely
thing to have just broken, and a throw inside an exception handler is a fatal with the original
swallowed. The one type it does name is `SiteException`, to say in the log whether the fault came
from the site's own code — and that is free, see [Exceptions](#exceptions).

The handler is the site's, in its `public/index.php`; `TestApp` has no front controller, and its
fixture webroot is empty. Everything from here on is [`App::run()`](../src/App.php).

**`App::run()` catches first, so the handler is for what escapes it.** A fault anywhere in
`handle()` — a controller, a view, the shell — is logged and answered by `App::fault()`: a bare
`500` in plain text to everyone, or a [`FaultPage`](../src/View/FaultPage.php) — the fault, its
chain and its trace, drawn in a document of its own rather than the site's shell — when the
[`Environment`](../src/Environment.php) is development **and** the request came from loopback. Only
the server can say development (`PHPANTA_ENVIRONMENT=development`, exactly); anything else is
production, and `health v1` warns about a deployment that says otherwise. What reaches the handler
is a fault before the request is read, or while the answer is already going out.

### ⓪ The error log

`ErrorLog::install()` points `error_log` at `App::errorLog()` — `data/logs/php-YYYY-MM.log` under
`above()`, `test/fixture/app/` for `TestApp` — and sets `error_reporting` to `E_ALL`. It creates
nothing: `data/logs/` must already exist and be writable, and where it is not PHP falls back to the
host's own log **without a word**. `health v1` warns about it; see [health.md](health.md).

### ① Security headers, before anything can fail

[`SecurityHeaders::send()`](../src/Http/SecurityHeaders.php) runs *before* the request is
even parsed, so nothing goes out without them — not even the site's last-resort 500, which is the one
response that is not an answer. Every answer then carries the same five again, first:
`App::handle()` puts them ahead of whatever the route answered, so the 401 a gate refuses with, the
405 the router refuses a POST with and a 303 a redirect answers with carry them as a value a test
can read. Sending them replaces the ones already out rather than doubling them; see
[`Answer::send()`](../src/Http/Answer.php). The CSP's third-party hosts, the HSTS policy and the
`Permissions-Policy` are the app's to state — `TestApp` states none, so it sends the strict defaults.

It also *removes* one header — `X-Powered-By`, which PHP appends with its exact patch version before
any of the framework's code runs. See [security.md](security.md) for the policies themselves.

### ② `$_SERVER` becomes a `Request`

[`Request::fromGlobals()`](../src/Http/Request.php) is the only place a request is built
from the superglobals: it hands [`ServerParameters`](../src/Http/ServerParameters.php) — the one
reader of `$_SERVER` — and [`MultipartParameters`](../src/Http/MultipartParameters.php), the one
reader of `$_POST` and `$_FILES`, to `Request::from()`, which is also how a test builds any other request. What comes out is `readonly` and typed — here, method `HttpMethod::Get` and
path `/api/update/v1/version` — and three of its decisions are deliberate:

| Member | Decision |
|---|---|
| `method()` | `HttpMethod::tryFrom()` — **nullable**. An unrecognised verb is `null`, and null is not read-only. Never guessed as GET. |
| `path()` | Parsed with `Uri\Rfc3986\Uri::parse()`, which returns `null` on failure — so `??` is a real guard, where `parse_url()`'s `false` would not be. A target opening with `//` is never handed to the parser at all, which would read it as an authority. A target that will not parse comes back as **its own path**, everything up to the first `?` or `#`. That still matches a placeholder route, because `{param}` compiles to `([^/]+)`; see [security.md](security.md). |
| `authUser()` / `authPassword()` | Read from `PHP_AUTH_*`, falling back to decoding `Authorization` — some hosts do not hand PHP the former. |
| `query()` / `form()` | An [`Input`](../src/Http/Input.php), asked for by [`Parameter`](../src/Http/Parameter.php) case and by type: absent is null, a value that does not read is an `InputException` the router answers with a 400. Read by this code, never `parse_str()`; `form()` reads a url-encoded body, bounded, or a multipart one from what PHP parsed — see below. The API reads neither, and `InputTest` holds it. |
| `upload()` | The [`Upload`](../src/Http/Upload.php) a multipart form sent under a `Parameter`, or null: its name as the browser gave it, its size, and `keepAs()`. Too large for the host is a `TooLargeException` the router answers with a 413; the host failing to keep it is an `UploadException`, a 500. |

The path is **raw, not decoded**, and trailing slashes are trimmed: a route matches the target as it
was sent, and a value is decoded only after it has matched.

**The `$_SERVER` keys a request is built from are `ServerVariable` cases**, because every reader of
that superglobal ends in a default — `?? 'GET'`, `?? '/'`, `?? ''` — which is exactly what makes a
misspelled key indistinguishable from a request that did not carry the value. `PHP_AUTH_USER` is the
one that matters: a typo there leaves the user `''`, which no stored credential equals, so every
gate refuses everything with a 401 that reads as a wrong password.

Not every key belongs on it, and the rule is whose name it is. A request header arrives under
`HTTP_` plus the name upper-cased with dashes as underscores — PHP's transform, so
`Request::header()` applies it to a `RequestHeader` case rather than anybody retyping the result. A
case earns a place on `ServerVariable` when that derivation cannot reach the name
(`REDIRECT_HTTP_AUTHORIZATION` is Apache's invention, not HTTP's; `SERVER_SOFTWARE` and
`DOCUMENT_ROOT` are CGI's) or when the reader has no `Request` to ask — an `ApiHandler` takes none by
construction, since everything it may act on is signed.

### ③ The pre-launch gate

[`SiteGate`](../src/Service/Layer/SiteGate.php), the first of the app's [layers](#layers), asks
[`Auth::siteGate()`](../src/Service/Auth.php), which checks for `data/site_auth.php`. Refusing, it
returns the `401` as a response, which is then the answer in the router's place. If
the file is absent it returns null immediately — *that absence is how the gate is switched off*, and a
site gitignores the file precisely so the repository's copy cannot switch it on. It is also why a
misspelled `DataFileName` case there would not fail but stand the gate down. `TestApp`'s deployment
holds no `data/` at all, so the request walks through.

### ④ Routing

[`Router::dispatch()`](../src/Router.php) asks the table [`App::routeTable()`](../src/App.php)
builds: the site's `routes()`, in match order, then the framework's API route, last, so no site route
can be shadowed by it. `TestApp`'s `routes()` is empty, so its table is one entry. The router does two
things, in order:

1. **The match.** Each [`Route`](../src/Support/Route.php) is a
   [`Path`](../src/Support/Path.php) case, a factory closure and a
   [`MethodGate`](../src/Support/MethodGate.php). `{param}` compiles to `([^/]+)`; a typed one —
   `{id:int}`, `{tag:slug}`, see [`PlaceholderType`](../src/Support/PlaceholderType.php) — to its
   type's expression, so a segment of the wrong kind is simply no match. Static parts are quoted,
   the expression ends in `\z`, and the captures — decoded, and an `int` for `{id:int}` — are passed
   positionally to the factory. `ApiPath::Api` is `/api/{service}/{version}/{action}`, so this request captures
   `update`, `v1` and `version`, as raw strings: resolving them to cases in the factory would put a
   `from()` there, and a `ValueError` before the signature is checked.
2. **The method gate**, asked of the matched route rather than globally. A `ReadOnly` route — the
   default, and every page a site has — answers anything but `GET`/`HEAD` with a 405 whose `Allow`
   comes from `Allow::readOnly()`, derived by filtering the cases, so the header cannot advertise
   something the gate does not do. `/api` is `Delegated`: the router forms no opinion and its
   controller answers every method itself, because any opinion the router formed would tell an
   unsigned caller the address is real. A route that also writes — a form — names a
   [`MethodSet`](../src/Support/MethodSet.php) instead, and its 405 names that set: nothing about
   it is hidden, and `GET` brings `HEAD` with it. The API is the one route that must never name its
   own, which is why it is a policy and not a set.
3. **An `OPTIONS`** to a route that does not take one itself is a 204 with that route's `Allow`
   and `OPTIONS` after it — an answer about the resource, with no body. The API decides for itself,
   so an unsigned `OPTIONS` there is still the refusal an address that does not exist gets.

A [`RouteGroup`](../src/Support/RouteGroup.php) writes the layers several routes share once. It has
no prefix, on purpose: a case's value is its whole address, and every link is built from it.

An unmatched path falls through to
[`UnroutedController`](../src/Controller/UnroutedController.php), which answers a read verb with the
app's `notFound()` and a write one with the read-only 405 — and is the same object `ApiController`
delegates to, so that no address under `/api` and an address that does not exist can answer
differently. `TestApp`'s `notFound()` is a `text/plain` `404`.

**Every address is a `Path` case, and that is one vocabulary rather than two.** A view naming a path
the router does not have would render a link that looks perfectly fine and answers with the site's
own 404, so views never concatenate a path: the case's *value* is the pattern, placeholders and all,
so `Route::matches()` matches with it and `->to(…)` fills it in; the placeholder syntax is one
constant, `Route::PLACEHOLDER_PATTERN`, both read. `to()` is written once, in the `FillsPlaceholders`
trait, for the framework's `ApiPath` and every site's own enum alike. It refuses the wrong number of
values, and a value a typed placeholder does not take, with a `RouteException` — the check a
concatenation cannot make —
`'/posts/' . $slug . '/'` is a perfectly good string and a URL that matches nothing. Two details
worth knowing: each value is `rawurlencode`d, so `to()` and `matches()` are inverses; and the
callback that fills them **must** be a `function` with `use (&$values)`, because `fn()` captures by
value and every placeholder would take the first value — `/posts/hello/hello`, well formed, matching
a route, and the wrong page.

**Tests write paths out in full, deliberately.** A test that asked the enum for the path it is
checking would pass with the enum wrong.

### ⑤ The controller, and the response

A controller fetches its own data. Nothing is injected for it, and there is no shared context
object: the factory hands [`ApiController`](../src/Controller/ApiController.php) its three segments
and nothing else, and it constructs the `ApiGate` it asks. An optional constructor parameter is a
**test seam and nothing else** — `ApiController`'s `?ApiGate $gate = null` is how a test verifies
against a key of its own.

**It verifies before it resolves.** [`ApiGate::accepts()`](../src/Service/ApiGate.php) answers a
`VerifiedRequest` or `null`, and every `null` — no `NS1` credential, no `data/update.pub`, a signature
that fails, an envelope minted for another method or path, a stale serial, a body that is not the one
signed — goes to `UnroutedController`, so the caller gets exactly the 404 an address that does not
exist gets. **On `TestApp` as it ships, that is where this request ends**: the fixture holds no key,
so `/api` is off, and a `GET` gets `TestApp`'s `404`.

With a key in place and a credential it verifies, the posture inverts and failures are reported in
full: `ApiService::Update` at `ApiVersion::V1` names `UpdateAction::Version`, whose method is `GET`,
so the request's method is the action's; its handler is `UpdateVersion`, which is a read — so
**no serial is spent**, and the same credential could be sent again. It answers an
[`ApiResult`](../src/Http/Api/ApiResult.php): the last serial accepted (a dash where there is none),
`App::buildId()` — `test` for `TestApp` — and `PHP_VERSION`, as the sections of a report. See
[security.md](security.md#the-api) for everything the gate proves.

**A handler says what happened and the controller says how.** Straight after the gate, and before
any action runs, the controller asks the request's `Accept` which
[`Representation`](../src/Http/Representation.php) it wants: a page by default — a `ViewResponse`
around an `ApiResultView`, in the app's own shell — data for `application/json`, and a `406` for a
request that named neither. Either way the answer is kept by no cache and varies on `Accept`, and
`answer()` comes to the status, the type, any extra headers and the body, as an
[`Answer`](../src/Http/Answer.php). `App::handle()` puts the security headers ahead of them and
hands the answer back, and `App::run()` sends it — the one place anything is sent. See
[the wire](#http--the-wire).

### A page instead

A site's route answers with a page by returning a
[`ViewResponse`](../src/Http/ViewResponse.php) around a `View` — `/posts/{slug}`'s controller, say,
finding a `Post` and returning `new ViewResponse(new PostView($post))`, or the site's 404 view with
`HttpStatusCode::NotFound`. `ViewResponse::render()` makes the one branch that matters:

- **full page** → `App::current()->shell()->document($view, $language)` → a `Document`. The shell is
  the site's to draw; `TestApp`'s is `<html lang>` around a `<head>` holding the view's title and a
  `<body>` holding its content, and nothing more.
- **navigation fragment** → a `Fragment` of `<title>…</title>` plus the view's content, for a request
  carrying `X-Requested-With: XMLHttpRequest`.

Both render in the request's language and are sent with `Content-Type: text/html; charset=utf-8` and
a `Content-Language`. The fragment is why the charset is in the header: a page carries
`<meta charset>`, a fragment carries nothing, so the header is all the browser has. The body is
rendered **before** any header goes out, because the `ETag` is a hash of it: a success answers a
matching `If-None-Match` with a bare 304, a caller that supplied its own `Cache-Control` gets no
validator, and every page says `Vary: X-Requested-With, Accept-Language, Cookie` plus whatever its
view declares in `varyOn()`.

---

## Layers

What stands around a controller is a [`Layer`](../src/Controller/Layer.php). It is handed the
request and whatever comes next — the next layer in, or the controller — as one more `Controller`,
and it can look before, answer instead, or wrap what came back;
[`WithHeaders`](../src/Http/WithHeaders.php) is the usual shape of after, since it keeps the status,
the validator and the body exactly as they were. [`Layered::around()`](../src/Controller/Layered.php)
folds a list of them around a controller, the first listed outermost: first to see the request, last
to see the response.

They are listed in two places, and discovered in none:

- **Around every request**, `App::layerTable()`: the framework's `SiteGate`, always first, so no site
  can forget it or put something ahead of it; then the site's own `App::layers()`; and inside them,
  the router.
- **Around one route**, `Route::through()`: around that route's controller only, and only past its
  method gate — a `POST` a read-only route refuses never reaches them. A gate belongs here rather than
  in the controller, so the password is written where the address is and the route table is where a
  test asks whether a page is behind one.

Six ship with the framework, in `Service/Layer/`:

- `SiteGate`, the pre-launch gate;
- `AdminGate`, the admin gate for a route;
- `Maintenance`, a `503` that no cache keeps for every page while a switch file exists — absent means
  off, like the site gate's — and never for the API, because a push is how maintenance usually ends.
  It recognises the API by the route's own match, `App::apiRoute()`, never by a prefix;
- `TrailingSlash`, one address per page: a read of `/x/` is a 308 to `/x`, the query kept — never a
  write, and never an address that trims into another host;
- `Cors`, which other [`Origin`](../src/Http/Origin.php)s may read the answers: a listed one is named
  back in `Access-Control-Allow-Origin` and its preflight is answered by the layer; every answer
  through it says `Vary: Origin`; and there are no credentials, so nothing a gate protects is
  readable from elsewhere;
- `RateLimit`, a `429` with `Retry-After` and `no-store` for an address over its
  [`Throttle`](../src/Support/Throttle.php) — so many attempts per key in a sliding window, one
  file per key under a directory the caller supplies, and a `ThrottleException` rather than an
  allowance when that directory is missing or unwritable. It keys by the remote address, the only
  identity an anonymous request has, so behind a reverse proxy every visitor shares the proxy's.

## `Http/` — the wire

Everything about a request or a response is a typed value here, not a string.

| Type | Is |
|---|---|
| `Request` | readonly, read out of `ServerParameters` — `$_SERVER`'s, or a test's — or `synthetic()`, for a static export |
| `MultipartParameters` | `$_POST` and `$_FILES` as PHP parsed a multipart body, and their one reader; a test builds one by hand |
| `Upload` | one file a form sent: the name the browser gave it, its size, and `keepAs()`, which moves it beside its target and renames it into place |
| `FormEncoding` | the two bodies a form sends, each a `MimeType` spelled once, by its essence |
| `Response` | an interface with one method: `answer(Request): Answer` |
| `Answer` | what goes on the wire: a status, the headers in order, a `Body`; `send()` is the one emitter |
| `Body` | `TextBody`, a string; `FileBody`, a file read a chunk at a time; or `StreamBody`, chunks a closure makes at send time |
| `ViewResponse` | renders a `View`, with its validator, its 304 and its cache headers |
| `FileResponse` | a file under `data/`, whole or as a byte range |
| `RedirectResponse` | `Location` + status, no body |
| `PlainTextResponse` | body + status + extra headers |
| `JsonResponse` | a `JsonSerializable`, encoded when the answer is asked for, so a value that cannot encode throws before anything is sent; `no-cache` unless the caller sends its own |
| `StreamResponse` | a body generated as it is sent — a large CSV, server-sent events; no `Content-Length`, `no-store`, and a HEAD never runs the closure |
| `Session` | what a visitor carries between requests, sealed into `__Host-session`; immutable, attached to an answer to be kept |
| `SessionSeal` | AES-256-GCM under the deployment's `data/session.key`; what does not open is no session |
| `Sitemap` | every exported page, absolute on `App::origin()`, as sitemaps.org XML built through the DOM; no `hreflang`, since every language is one address |
| `Header` | a `HeaderName` and a `HeaderValue`; formats `Name: value` in one place |
| `MimeType` | a `TopLevelType`, a validated subtype, and a `Charset` |
| `HttpStatusCode` | every standard status code, backed by its number |
| `HttpMethod` | the eight methods, and which of them only read |

**Nothing is sent until `App::run()` sends the answer.** A response says what it is, and
`answer()` works out what that comes to for one request — a page is a 200 for one visitor and a 304
for the next — while the router, the gates and the controllers all return. So a test sees every
answer, a 401 and a 303 included, through `App::handle()`. What it cannot see is the server around
it — `header()` is a no-op under the CLI, and nothing compresses or drops a `HEAD`'s body — which is
what a site's end-to-end suite is still for; see [testing.md](testing.md).

**Header names live in two enums on purpose.** `SecurityHeader` is exhaustive and tested as such —
`SecurityHeaders::headers()` sends exactly its cases. `ResponseHeader` is everything else. Folding
them together would make the exhaustiveness assertion meaningless. `RequestHeader` is the inbound
direction; all three implement `HeaderName`, so `Header` formats any of them.

**Header *values* are typed too, because a header value has a grammar** — a quoted `ETag`, a
comma-separated `Allow`, `Basic realm="…"`, `max-age=…; includeSubDomains`, `no-store, private` —
and a `new Header(…)` call site is the one place a grammar cannot be checked. `HeaderValue` is one
method, `render()`, and its implementations are the objects that know each header's grammar:
`ContentSecurityPolicy`, `PermissionsPolicy`, `StrictTransportSecurity`, `ReferrerPolicy`,
`ContentTypeOptions`, `RobotsPolicy` and `MimeType`, plus `CacheControl`, `ETag`, `Vary`, `Allow`,
`BasicChallenge`, `Location`, `ContentLanguage`, `ContentLength`, `ContentRange`, `AcceptRanges`,
`SetCookie`, `Origin` and `RetryAfter`. `Header` accepts nothing else, so a value cannot be assembled as a string at the call
site. `Location` accepts an absolute `https://` URL or a path that `Element::staysOnThisOrigin()`
confirms is on this origin, and nothing else. `SecurityPolicyTest` pins the set in both directions —
every implementer must be rendered by its table, and the table must name every implementer.

**`Content-Type` is a `MimeType`**, and not a string with `; charset=utf-8` stapled onto it. A class
rather than an enum for the reason `StrictTransportSecurity` is one: the value carries a parameter,
and a case cannot hold one. The two a page and a refusal send are `MimeType::html()` and
`MimeType::plainText()`, so no call site types a subtype, and a malformed one throws where it is
written the way `CspHost`'s origin does. The charset is the half that earns the class — `nosniff`
stops a browser guessing the type, and nothing stops it guessing the encoding.

## `Support/` — the shapes

`Collection<T>` and `SearchableCollection<T>` with the `TypedItems` trait they share — see
[collections.md](collections.md). `File`, `Directory`, `FileLock` and `Diagnostics`. `Route`, the
`Path` interface, `ApiPath`, the `FillsPlaceholders` trait, `PlaceholderType`, `RouteGroup`, and the
`MethodGate` a route answers through — `MethodPolicy` or a `MethodSet`. `Throttle` and
`ThrottleVerdict`, the file-backed sliding window a login and the `RateLimit` layer count in. `ErrorLog`.
`RequirementInitialization`, the framework's floor — see [health.md](health.md). `Charset` and
`UrlScheme`. `PasswordHash` and `PublicKey`; `TarArchive`, `TarEntry` and `TarMemberType`.
`JsonDeserializable`. `BareArray`, `BareString` and `BareCall`, the three attributes that excuse an
exception to a guideline — see [guidelines.md](guidelines.md).

**The encoding is one fact.** `Charset` sits in `Support/` because both the header and the markup
tree read it and `View/` has no other reason to know anything about HTTP. It carries two forms —
`utf-8` for the header parameter, `canonical()` for the document head and for the one escaping call,
in `Text` — because those two readers write it differently, and one enum keeps both spellings in step.

## `Data/` — the database

Optional, and a site's choice: `Database`, one SQLite connection opened the one way the framework
opens one; `Sql`, a statement whose parameters are bound by type and compared with its placeholders
where it is written; `Row` and its typed readers; `Table` and `Column`, the interfaces a site's enums
implement; `Migration` and `Migrations`, applied once each and recorded. pdo_sqlite is not in the
framework's floor — a site that keeps a database declares it with `Database::requirement()`. See
[data.md](data.md).

## The type discipline

Almost everything here is one of four shapes. Recognising which one you need is most of the work of
adding something.

### 1. An enum, when the vocabulary is closed

`HttpStatusCode`, `HttpMethod`, `CspDirective`, `HtmlTag`, `ApiPath`, `CredentialFile`, every header
and attribute name. A typo becomes a parse error instead of a value the browser silently drops.

Enums here list **what is used, not what exists** — `HtmlTag` has the elements a page built on the
framework emits and no others. The exceptions are the ones where the registry really is closed
(`TopLevelType`, `HttpStatusCode`) or where exhaustiveness is the point (`SecurityHeader`).

Single-case enums are not a mistake: `Charset`, `RequestedWith` and `Doctype` exist to make a value
a *type*, not to offer a choice. Every enum is backed — see [guidelines.md](guidelines.md).

### 2. A value object with a `verify()`, when the value arrives as free text

`CspHost` (a bare origin), `MimeType` (a subtype), `Location` (an address), `PasswordHash` (a bcrypt
digest). Each validates in its constructor and throws.

The point is **where** it throws. These fire while the value is being built — a policy composed, a
data file loaded — naming the offending value, rather than surfacing later as a header a browser
silently drops or a link nobody clicks. A site's own value objects follow the same shape, and an
`InvalidValueException` subclass is what they throw.

A URL a site holds is checked in its constructor *and* again by `Element` at render time. That is not
redundancy: the renderer is the backstop and reports the fault on whatever page draws it; the
constructor reports it where the mistake actually is.

### 3. An interface, when the axis is "which provider"

`Response`, `Controller`, `Node`, `TagName`, `AttributeName`, `HeaderName`, `HeaderValue`,
`AttributeValue`, `CspSource`, `Path`, `DataFileName`, `Shell`, `Translatable`, `Requirement`,
`ApiAction`, `ApiHandler`. Each has one or two methods and exists so a call site can hold the
abstraction without knowing the implementation — and so a site can add an implementation of its
own, which is how its tags, its paths and its data files join the framework's.

### 4. An immutable collection, when a group crosses a public boundary

`Collection::with()` **copies**. That is what makes a collection safe inside a `readonly` value
object — `readonly` protects the reference, not what it points at. The name is chosen so a dropped
result reads as wrong, and PHP 8.5 enforces it: the builders and the query methods carry
`#[\NoDiscard]` and `phpunit.xml.dist` sets `failOnWarning`, so a discarded result is a failing test.
A collection replaces a hand-rolled type check on data crossing a public boundary; it does not
replace a variadic. The whole of it is in [collections.md](collections.md).

---

## Exceptions

Every condition the framework can be in has a name, and all of them live in `Phpanta\Exception`.
Twenty-two classes — one of them abstract — and one interface, read from `src/Exception/`:

| Class | Extends | Thrown when | Thrown by |
|---|---|---|---|
| `SiteException` | `Throwable` — the marker, an interface | — | — |
| `AppException` | `LogicException` | no app is booted, the booted one is not the class asked for, or a second is booted; a sitemap is asked of an app with no origin | `App`, `Sitemap` |
| `ApiException` | `RuntimeException` | a signed request cannot be read or trusted | `ApiCredential`, `ApiEnvelope` |
| ` └ UpdateException` | `ApiException` | a payload cannot be read or applied; a webroot cannot be resolved; a previous release cannot be recorded or put back | `App::webroot()`, `PublicKey`, `TarArchive`, `UpdateApplier`, `UpdateManifest`, `ReleaseRecord`, `PreviousRelease`, `RecordEntry`, `ApplyManifest` |
| `CollectionException` | `TypeError` | a collection is asked to hold or produce the wrong type | `TypedItems` |
| `DatabaseException` | `RuntimeException` | a database cannot be opened, or a file opened as one is not one | `Database` |
| `FormException` | `LogicException` | a form is declared with what it cannot be, or asked for another form's field | `Form`, `Submission`, `MaxLength`, `MaxBytes`, `OneOf` |
| `GuidelineException` | `InvalidArgumentException` | an excuse for a guideline has no reason, or no subject | `BareArray`, `BareString`, `BareCall` |
| `InputException` | `UnexpectedValueException` | what a query string or a form sent cannot be read as asked — answered with a 400 | `Input`, `Request`, `MultipartParameters` |
| ` └ TooLargeException` | `InputException` | what was sent is readable but larger than the host takes — a 413, or a file field's error | `Request`, `MultipartParameters` |
| `InvalidValueException` | `LogicException` | a value object is handed something that is not its kind of value | `PasswordHash`, `Throttle`, and a site's own value objects |
| `JsonEncodingException` | `RuntimeException` | a value cannot be written as JSON — a NAN, a string that is not UTF-8 | `JsonResponse` |
| `MarkupException` | `LogicException`, abstract | — | — |
| ` ├ ElementException` | `MarkupException` | an element is asked to be what no element can be | `Element` |
| ` └ ParserException` | `MarkupException` | hand-authored markup is outside the app's vocabulary, or does not parse cleanly | `MarkupParser` |
| `MigrationException` | `LogicException` | a list of migrations disagrees with the history a database records | `Migrations` |
| `MimeTypeException` | `LogicException` | a media type is not one | `MimeType` |
| `RequirementException` | `LogicException` | a requirement is declared with something it cannot check | 5 classes under `Model/Health/` |
| `RouteException` | `LogicException` | a `Path` is given the wrong number of values, or one its placeholder's type does not take; a method set is empty | `FillsPlaceholders`, `PlaceholderType`, `MethodSet` |
| `SecurityPolicyException` | `LogicException` | a policy or header value is not valid on the wire | 12 classes under `Http/` |
| `SessionException` | `RuntimeException` | a session is kept without a key to seal it, or grows past what a cookie holds | `Session`, `SessionSeal` |
| `SqlException` | `LogicException` | a statement, a row read or a transaction is written wrong | `Sql`, `Row`, `Database` |
| `ThrottleException` | `RuntimeException` | the throttle's record cannot be read or written, so it fails closed | `Throttle` |
| `TranslationException` | `LogicException` | text cannot be put into a language | `Languages`, `Phrase`, `Translated`, `TranslatedText`, `Translation` |
| `UploadException` | `RuntimeException` | the host could not keep a file a request sent — no temporary directory, a failed write | `MultipartParameters` |

**A site adds its own the same way**, in its own namespace: a class that extends the one it
specialises — a `MarkupException` for a component that cannot draw what it was handed, an
`InvalidValueException` for a value its data files declare wrongly — or, for a condition with no
framework counterpart, the SPL class it replaces plus `SiteException`. Either way the family's
`catch` still covers it, and the last-resort handler still reports it as the site's own.

**`SiteException` is an interface because the inheritance chain is already spent.** Nineteen classes
declare it and the three under `MarkupException` and `ApiException` inherit it; of the nineteen,
eleven are a `LogicException`, five a `RuntimeException`, one a `TypeError`, one an
`InvalidArgumentException` and one an `UnexpectedValueException` — each saying something true — so
the question *did this come from us* has nowhere else to live. It matters more than it looks:
`CollectionException extends TypeError` extends **`Error`**, a sibling of `Exception` rather than a
subclass, so `catch (Exception)` — the widest net anybody reaches for by habit — misses one of the
twenty-one concrete classes, silently, in the class most likely to be thrown by a mistake made five
minutes ago. Only `Throwable` catches all twenty-one, and `Throwable` also catches everything PHP
raises. This interface is the difference, and
the last-resort handler in a site's `public/index.php` is what it is for.

**An exception becomes ours by extending the SPL class it already was, not by replacing it.**
`CollectionException extends TypeError`, `GuidelineException extends InvalidArgumentException`,
`ApiException extends RuntimeException`: every `instanceof`, every `catch` and every
`expectException` that matched the SPL class still matches, and the only thing ours adds is that the
throw says which layer raised it. Throwing an SPL class is how you avoid making a promise; extending
one is how you keep it.

**`LogicException` is the classification, not a detail.** Eight of the eleven say "something in the
code or its data files is written wrong, go and fix it" — nothing recovers from that and nothing
should try, so no call site owes an `@throws` for a failure that only happens when the site is
already broken. `ApiException` is the one that is a condition: a malformed credential arrived over
the network from a caller the code does not control, and `ApiGate` catches every one of them.

**`MarkupException` is abstract**, because nothing throws it. It is what its subclasses have in
common, and saying so in the language is what stops a new kind arriving as a bare `MarkupException`
— which would read as "one of those" and be none of them. A `catch` or an `@throws` naming it means
any of them, a site's included.

**`ApiException` is the same arrangement and is deliberately *not* abstract**, which is the
difference worth reading. Both exist so one `catch` at a boundary covers a family without listing
it — `ApiGate` names `ApiException` and gets `UpdateException` with it — but this one is thrown:
`ApiCredential` and `ApiEnvelope` raise it about a request that is nobody's service in particular.

The rule that keeps every `throw` naming one of these is in
[guidelines.md](guidelines.md#the-exception-rule).

---

## The markup tree

**Nothing builds HTML from a string.** A view returns a `Node`, a page is a tree of them, and the only
code that writes a `<` is `Element` and `Doctype`. A site's verify script can fail the build if a
heredoc or a `'<tag'` literal appears anywhere else under `src/`.

| Node | Is |
|---|---|
| `Element` | a `TagName`, a keyed collection of `Attribute`s, child nodes |
| `Text` | a run of text, escaped on the way out |
| `TranslatedText` | a `Translatable`, put into the nearest `lang` when it renders — see [language.md](language.md) |
| `Sentence` | a `Translatable` with named nodes in it — a link, a piece of code — placed where each language's word order puts them; see [language.md](language.md#sentences-words-with-nodes-in-them) |
| `Fragment` | several nodes with no element around them |
| `Document` | a `Doctype` and the `<html>` under it |
| `MarkupParser` | the reader — hand-authored markup, back into the nodes above |

Four mistakes cannot happen, three of which would otherwise be silent: a misspelled tag renders as an
inert inline box, a misspelled attribute is a null the client reads as nothing, an unescaped value is
an injection, and a mismatched closing tag is a document the browser reinterprets. The last one a
tree removes outright — there is no closing tag to get wrong, because there is no text form to write.

### Building one

```php
new Element(HtmlTag::A)
    ->attr(HtmlAttribute::Rel, LinkRel::tokens(LinkRel::NoOpener))
    ->attr(HtmlAttribute::Href, 'https://example.test/')
    ->containing('example.test →');
```

`attr()` is the whole attribute API, and what you pass decides what renders:

| Value | Renders |
|---|---|
| `'post'`, `5` | `name="post"`, `height="5"` |
| any backed enum | its value — `target="_blank"` |
| an `AttributeValue` | its `render()` — `content="width=device-width, initial-scale=1.0"` |
| a `Translatable` | its words in the language it renders in — never its key |
| `''` | `alt=""` — a real empty value |
| `true` | `controls` — a bare boolean attribute |
| `false`, `null` | nothing at all |

`''` and `null` are deliberately different. An `alt=""` says an image is decoration; no `alt` at all
says nobody thought about it, and a custom element can read an empty attribute and a missing one as
two different instructions.

**An attribute is an `Attribute`**, held in a `SearchableCollection` keyed by its name. The name is
kept beside the value even though the map is keyed by it, because `render()` has to ask the name
whether it is a URL and a key is a string; the key is what keeps last-write-wins and declaration
order.

`containing()` takes nodes; a bare string becomes escaped `Text`. That is the safe reading of the
ambiguous case — markup passed as a string shows up as visible `&lt;b&gt;`, which is wrong on the
page but *visibly* wrong.

### Typed attribute values

**The attribute's *value* is typed too, wherever it is a fixed vocabulary rather than data.**
`attr()` accepts any `BackedEnum` and unwraps it, so `rel`, `target` and `type` are `LinkRel`,
`LinkTarget` and `ScriptType` cases rather than strings — the same move `RequestedWith` and
`ContentTypeOptions` make beside the headers they fill. It earns its place on the same grounds the
names did: misspell `modulepreload` and every preload hint stops preloading in silence, misspell
`noopener` and a security boundary on every outbound link is quietly not there, and drop `module`
from the script tag and `import` becomes a syntax error. `rel` is a token list, so `LinkRel::tokens(…)`
builds it variadically the way `Allow::of()` builds the `Allow` header. `preload` is `MediaPreload`
and `<meta name>` is `MetaName` on the same grounds — the second is the whole vocabulary of an
attribute used nowhere else, and it fails the way the rest of this list does, which is not at all: a
`<meta>` whose name nothing recognises is laid out as nothing and moved past, so a misspelled
`viewport` renders every phone at 980px with the media queries answering for a screen nobody is
holding. `lang` is `Language` on the same grounds and fails the same way — a language tag nothing
recognises is not an error, it is a screen reader picking the wrong voice and a hyphenation
dictionary picking the wrong words. These are server-only, so they have no TypeScript mirror and
none is wanted.

A `Translatable` is asked before a `BackedEnum`, and the order is the point: a catalog case is both,
and read as an enum it would render its key. It stays unresolved until `render()`, the one place
that knows which language it is in.

**A value with a *grammar* is a class, not a case**, and `attr()` takes one through an
`AttributeValue` interface — the same shape `HeaderValue` has on the HTTP side, for the same reason:
an `->attr(…)` call site is the one place a grammar cannot be checked. `ViewportContent` is the
framework's implementation: `width=device-width, initial-scale=1.0` is a descriptor list of
name-value pairs. Its width is a `ViewportWidth` case and its scale is a `float`, so neither half can
be misspelled. Note the two rules the class exists to keep: the scale renders `1.0` rather than PHP's
`(string)` of it, which is `1` — the same instinct that keeps `Charset` carrying two spellings of one
encoding — and it is formatted with `%F` rather than `%f`, because `%f` under a German locale writes
a decimal comma and a comma is this grammar's own separator.

**Unwrapping happens in `attr()`, and both guarantees stay in `render()`.** That is not a
contradiction of the rule in the next section: unwrapping is *normalisation* — shorthand for the
string a call site would otherwise have typed — where escaping and the scheme check are guarantees,
which have to hold for an element built any way at all. `Attribute` holds a `?string`, so what a
hostile `AttributeValue` returned is escaped exactly like anything else. `MarkupTest` builds one to
prove it.

### The two guarantees, and where they live

Both are enforced in **`render()`, not in the builders**, because `render()` is the only code that
turns a node into markup — so the guarantee holds for any element however it was assembled,
including one built by handing the constructor its attributes directly, which `attr()` is otherwise
the only thing standing in front of.

- **Escaping.** `Element` escapes an attribute value by rendering it as a `Text`, so
  `htmlspecialchars` is called in exactly one place, `Text::render()`, with
  `ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML401` written out as `Text::FLAGS` rather than inherited
  from the runtime. `MarkupTest` pins that call site the same way it pins `containingHtml()`'s.
- **Scheme.** An attribute the browser dereferences is asked what scheme it names, because escaping
  is the wrong tool for a URL — `javascript:alert(1)` contains not one character `htmlspecialchars`
  touches. `AttributeName::isUrl()` says which attributes those are, case by case and not enum by
  enum, since `href` and `class` live in the same one.

  The allowlist is site-relative, a `#fragment` of the page, `https:` and `mailto:`. A fragment is
  resolved like a leading slash, and lands on the page it is on. The two schemes are
  `UrlScheme` cases, since a scheme is a
  fact about a URL and not about markup, which is why the enum sits in `Support/` beside `Charset`
  and why a view builds a `mailto:` link through it. The *list* stays its own constant,
  `Element::URL_SCHEMES`, rather than collapsing to `UrlScheme::cases()`: the enum is the vocabulary
  a URL may be written in, the constant is what is switched on — the distinction `CspScheme::Data`
  makes too.

  **A leading slash is not the same claim as "somewhere on this site"**, so it is asked rather than
  assumed. `Element::staysOnThisOrigin()` resolves the value with the WHATWG parser PHP 8.5 ships —
  against a reserved `.invalid` base, the way a browser would — and asks whether it landed where it
  started. Never test for "starts with a slash", and never list the prefixes an authority can open
  with: the parser strips tab, CR and LF *before* parsing, so `/\r\n/host` is `//host` is
  `https://host`. `Navigation.ts` makes the same check on the client, and `Location` asks the same
  method of a redirect. `MarkupTest` pins every spelling, the two whitespace ones included, along with
  the marked attribute set in both directions.

### Pretty-printing is not cosmetic

An element whose children are all elements puts each on its own line; one with any `Text` among them
stays on one line. Whitespace between inline content is content — without that rule
`<h1>hello<span>.</span></h1>` would gain a space inside the title.

### Hand-authored markup: `MarkupParser` and `containingHtml()`

Some documents are written by hand rather than assembled by a view — a legal text, say, kept under
`data/` beside the code that reads it. `MarkupParser` reads one into the tree, and
`Element::containingHtml()` is the door:

```php
new Element(HtmlTag::Section)
    ->attr(HtmlAttribute::Lang, $language)
    ->containingHtml($html);
```

It is the safe twin of `containing()`, which is the pair worth reading together: `containing('<b>x</b>')`
puts visible `&lt;b&gt;` on the page because a string is content, and `containingHtml('<b>x</b>')`
parses the same argument into a real `<b>` — after checking that `b` is an element in the app's
`Vocabulary` and that everything on it is an attribute in it. Because it builds through `attr()` and
`containing()` rather than around them, escaping and the scheme check apply to a parsed document
exactly as they do to one a view assembled; the parser itself never looks at a URL and does not need
to.

**The vocabulary is the app's.** `App::vocabulary()` answers `Vocabulary::standard()` — the
framework's `HtmlTag`, `HtmlAttribute` and `LinkAttribute` — with the site's own tag and attribute
enums added through `withTags()` and `withAttributes()`. `TestApp` answers the standard one.

**The refusals are the point, so they are exhaustive rather than illustrative** — the same stance
`TarArchive` takes about a member name off the network. An unknown element, an unknown attribute
(which is what refuses an `onerror=`), a comment, a CDATA section, an element from another namespace,
a document's own elements — `<title>`, `<meta>`, `<link>` and the rest — whether the parser hoists
them into `<head>` or leaves them in the content, a `<script>` — whose content is raw text that `Text` would
escape into meaning something else — and **any HTML5 parse error at all**, each a `ParserException`.
The last is the one that matters most: `Dom\HTMLDocument` reports a stray `</div>` as a warning and
then recovers silently, which for a hand-edited legal document would mean the rest of it
disappearing with nothing anywhere saying so. A document that parses with zero errors is what makes
refusing on any of them affordable.

Four details are worth knowing before touching it:

- **It needs `ext/dom`** — bundled with PHP is not the same as built into a host's PHP — and the
  failure is a fatal on whichever page parses markup and nowhere else, so nothing short of loading
  that page, or `health v1`, reports it.
- **The doctype it prepends is `Doctype::Html5`, not a literal**, which is what puts the parser in
  no-quirks mode; without it *every* fragment reports `unexpected-token-in-initial-mode` and the
  error trap is noise. Reusing the class that owns that string also means `MarkupParser` holds no `<`
  literal at all, so `Element` and `Doctype` stay the only two files that write one.
- **A name is resolved with `tryFrom()`** on each of the vocabulary's enums, where the honest
  question is which case has this `tagName()`. The two are one question only because every case of
  every vocabulary enum spells its name as its backing value, which `MarkupTest` pins. An enum missing
  from the vocabulary does not break the parser, it makes every one of that enum's names
  unparseable, which reads as the *markup* being wrong.
- **The parsed nodes become children of the wrapping element rather than a `Fragment`**, and that is
  what keeps a document coming back out as it went in. A parse keeps the source's own whitespace as
  `Text`, and a `Text` among the children is what puts `renderChildren()` on its single-line branch —
  where nothing is re-indented and, more to the point, no newline is invented between inline content.
  The rendered text is identical to the source; the *bytes* are not, because a character reference
  comes back as the character it names.

**What it costs**, measured on a legal document of about 140 top-level nodes, with no Xdebug loaded:
0.071 ms to parse, 0.242 ms to walk into the tree, 0.262 ms to render it back out — about **0.57 ms**
a document. That is the largest single price the tree takes for a guarantee, and it is affordable on
a page few visitors load; it would not be on one anyone loads twice. Under Xdebug the walk is three
times that and the parse is unchanged, because the parse happens in C — worth knowing before a figure
taken under one is quoted at the other.

`MarkupTest` pins the call sites, so a second one has to be argued for in a test named for the fact.
**Never parse anything a request can influence** — not because it would be an injection, which is
what the refusals are for, but because the vocabulary is the site's own, so a visitor would
otherwise get to choose which of its elements to build.

### Forms

A form is an enum of `Field` cases and the `Path` it posts to. A `Field` is a `Parameter` — its
value is the name on the wire — with a `label()`, a `type()` (an `InputType`) and its `rules()`, a
`Collection` of `Rule`s. Everything is in `src/Form/`.

```php
$form       = new Form(ContactField::class, AppPath::Contact);
$submission = $request->method() === HttpMethod::Post ? $form->read($request) : $form->blank();

$form->render($submission, $session->token(), ContactText::Send);   // an Element
```

- **`read()` reads `Request::form()`** and asks each field's rules of its value, in order; the first
  to refuse is the field's error, and a field shows one thing to fix at a time. A field not sent is
  `''`, which is how an unticked checkbox arrives. A body that cannot be read, or a field sent twice,
  is the `InputException` the router answers with a 400, and the form lets it through. A `Submission`
  is immutable; a blank one holds no error and is not `isValid()`, since nothing was sent to act on.
- **An empty value passes every rule but `Required`.** `MaxLength` counts characters, not bytes;
  `Email` is `FILTER_VALIDATE_EMAIL`, which says nothing of whether an address exists and refuses a
  few real ones — a dotless domain, a quoted or non-ASCII local part; `WholeNumber` is
  `Input::WHOLE_NUMBER`, the grammar `Input::int()` reads; `OneOf` names a backed enum's cases,
  compared as text. What they say is `FrameworkText`, in every language the framework writes.
- **`render()` writes the whole form through the tree**: `<form method="post" action>`, the hidden
  `_csrf` field, then per field a `<label for>` and its control — an `<input>` of its type, or a
  `<select>` with an empty first option for a `OneOf` field — with `required` and `maxlength` read
  off the rules, `autocomplete` where the enum implements `Autocompleting`, and the error after the
  control, which names it in `aria-describedby`. `FieldId` builds each `for`/`id` pair, so a label
  cannot name a control that is not there. What a visitor typed comes back escaped like any value.
- **Three things it never leaves to a page.** It writes the form token itself, under the name
  `CsrfGuard` reads — see [security.md](security.md#sessions-the-form-token-and-the-login). It
  always posts, since a `get` puts every field into the address bar. And it never gives a password
  field its value back, because the value would be written into a page that caches and saved copies
  keep. A field named `_csrf` is refused when the form is built, and `MarkupParser` refuses a
  hand-authored `<form>`, which would post without the token.
- **`action` is an address.** `HtmlAttribute::isUrl()` says so, so `render()` scheme-checks it like
  an `href` and an export rebases it.
- **A form with a `File` field sends files.** `render()` writes `enctype="multipart/form-data"` and
  never gives a file control a value. `read()` puts the file in its field's entry, read back with
  `Submission::upload()`; the field's value is the name the file was sent under, so `Required` asks
  whether one came. `MaxBytes` is an `UploadRule`, asked of the file itself, and a file larger than
  the host takes is the field's error rather than a 413. PHP parses a multipart body itself and
  renames a dot, a space or a bracket in a name, so a form that sends files refuses such a field —
  and a plain name sent twice is the one duplicate it cannot catch. Keeping a file is the page's:
  `Upload::keepAs()`, once `isValid()` says so. See [security.md](security.md#uploads).
- **A refusal no rule can make is `Submission::withError()`** — a name and a password that do not
  match, put beside the password like any other error. [login.md](login.md) assembles a whole login
  page out of a form, the session, the two guards and `Login`.

---
