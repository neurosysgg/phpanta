# Health and capability

Two read-only services of the admin, at `/admin/capability` and `/admin/health`. Each call is signed
like every other one, and a caller without a signature learns nothing more of them than of the rest:
the admin's one answer, at every depth. They answer two different questions and never mix them:

- **`capability` says what this host has**: every extension it loaded, every php.ini directive, its
  runtime, its deployment's files, its error log. No line is a claim and nothing is judged.
- **`health` says whether this host meets what the app needs.** Every line is a requirement with a
  floor, plus its verdict. A line is a claim exactly when it is under `health`.

The one overlap is deliberate. `capability v1 extensions` lists what is **registered**, and
`health v1 extensions` proves that the extensions the framework needs actually **work**, by using
them. Registered and working are two questions.

A site does well to keep what `capability` says about each runtime it meets (the live host, a local
server, the CLI) side by side, in its own documents. Those are facts about hosts, not about the
framework.

## Addresses

Both services are called with the `ApiCall` command. A site wires it as a command of its own, with
its origin and its key. The examples below call that command `tools/api.php`:

```bash
php tools/api.php capability v1 <action>
php tools/api.php health v1 <action>
php tools/api.php health v1                # what the server offers there: each action, and what it says of itself
```

| `capability v1` | answers |
|---|---|
| `runtime` | PHP version and `PHP_VERSION_ID`, SAPI, Zend version, OS family; the server software, protocol, `uname`, clock and `date.timezone` |
| `extensions` | every loaded extension, then every Zend extension, each with its version |
| `settings` | every php.ini directive the engine knows, with the local value a request runs under |
| `deployment` | the webroot, and every data file the app names (a `DataFileName`) as present or absent, tracked or untracked |
| `errors` | the reporting mask, `display_errors`, `log_errors`, `error_log`, `error_get_last()`, and the log's last 20 lines |

| `health v1` | checks |
|---|---|
| `runtime` · `extensions` · `settings` · `deployment` | the declared requirements in that area |
| `report` | every area, then a tally that names every verdict even at zero, so `0 fail` is the line that says the host is fine |

A `health` line gives the name, the verdict, what was found, and the floor. The verdict comes first
because it is the column worth reading down:

```
settings
  post_max_size        pass  128M  (at least 8M or 0 for no limit)
  opcache.enable       warn  0  (on, optional)
```

**Each area has an address of its own and is never a parameter.** The signature does not cover the
query string, so `?area=settings` would be the one input reaching a verified handler unsigned. See
[security.md](security.md#what-a-signature-covers-and-why-replay-is-closed).

## The status is the verdict

| When | Status | `ApiCall` exits |
|---|---|---|
| a required requirement is unmet (`FAIL`) | `503` | 1 |
| only optional ones are unmet (`warn`) | `200` | 0 |
| nothing is unmet | `200` | 0 |

**The body is the whole report either way.** A `503` with nothing in it would say that something is
wrong while withholding what, from the one caller who has proved they may know. `HealthCheck`
*returns* the `503` rather than throwing it. `ApiController` turns an `ApiException` into a `422`,
and that would report an unhealthy host as a malformed request.

`ApiCall` asks for the answer as data, prints the report's text from it, and exits on the status, so
a failed check can stop a script after a push.
It explains only a `401` as a refusal ("check the key, the clock"), because only a `401` is one, and
an answer that is not the admin's at all as a server older than `/admin`. It reports any other
status as `answered 503.`, and the body above that line already says what failed.

**A front proxy can substitute its own page for a 5xx.** Whether a host's proxy does that is a
question to ask of the host, not an assumption to make. Push a probe declaring one impossible
requirement, read what comes back, and push it away again. Ask again whenever the host changes.

## Declaring a requirement

The framework's floor is declared in one place, `src/Support/RequirementInitialization.php`, the same
way a route table is declared. A site adds its own in its app's `ownRequirements()`. **Both are code,
never a file in `data/`.** A requirement exists because some code needs it, so the two have to deploy
together. A push ships the code and never `data/`, so a floor declared in a data file could take
effect a week before or after the code that set it.

The built-in kinds cover the common cases without a class of your own. The level defaults to
`Level::Required`:

```php
new VersionRequirement('8.5'),
new ExtensionRequirement('zlib'),
new ExtensionRequirement('com_dotnet', Level::Optional, static fn(): bool => class_exists(\COM::class)),
new SettingRequirement('memory_limit', new ByteFloor(32 * 1024 * 1024, -1)),
new SettingRequirement('max_execution_time', new SecondsFloor(30, 0)),
new SettingRequirement('display_errors', Toggle::Off),
```

- **`VersionRequirement`** compares `PHP_VERSION` with `version_compare()`. The floor is written
  `major.minor` or `major.minor.patch`.
- **`ExtensionRequirement`** without a proof asks `extension_loaded()`. With a proof, it runs the
  proof, which should use what the installation uses: the class it constructs, the function it
  calls. The proof runs only when the requirement is checked. The found column says which question
  failed, `not registered` or `registered, but its proof fails`, and that distinction is the whole
  diagnostic.
- **`SettingRequirement`** reads the directive with `ini_get()`. **A directive the engine does not
  know fails, reading `no such directive`**, whether the name is misspelled or the directive belongs
  to an extension this host lacks. A cast would have turned it into an empty value.
- **`ByteFloor`** reads the value with `ini_parse_quantity()`, the engine's own reader. You declare
  which value means "no limit", because php.ini disagrees with itself: `memory_limit` uses `-1` and
  `post_max_size` uses `0`. **A value the engine cannot read is not met**, even though PHP itself
  falls back to reading it as `0`, which for `post_max_size` means unlimited.
- **`SecondsFloor`** is the same for durations, where `'0'` is the trap: it means unlimited, it is
  below every floor, and it is falsy.
- **`Toggle::On` / `Toggle::Off`** read a switch the way PHP reads a boolean directive. Anything else
  meets neither. `display_errors = stderr` is on, just printing somewhere else, so it does not pass
  as off.

A declaration that cannot be checked throws a `RequirementException` where it is written: an empty
name, a negative floor, a version that is not a version.

### Writing one

When no built-in kind fits, implement `Requirement`:

```php
final readonly class SpoolWritable implements Requirement
{
    public function name(): string { return 'spool'; }
    public function area(): Area { return Area::Deployment; }
    public function level(): Level { return Level::Required; }
    public function expected(): string { return 'a writable directory'; }

    public function check(): Finding
    {
        return is_writable('/var/spool/app')
            ? new Finding('/var/spool/app', true)
            : new Finding('not writable', false);
    }
}
```

- **Return a `Finding`, never a verdict.** A finding is what was found and whether it meets the
  floor. `Verdict::of()` turns that and the level into pass, warn or fail, so no requirement can
  downgrade its own failure to a warning.
- **Never throw.** Catch what you expect and turn it into the finding. `WebrootRequirement` catches
  the refusal from `App::webroot()` and reports the refusal's sentence. The core catches nothing on
  your behalf, because the guidelines refuse `catch (Throwable)`, so a single throw becomes a 500 for
  the whole report.
- **Pick one of the four areas.** Each area is an address, so there is no fifth. `deployment` means
  "this installation", and it is where anything an application checks about its own surroundings
  belongs.
- **`Model/Health/` asks no app.** It imports nothing but itself and the framework's support
  classes, so a requirement there can be checked anywhere. A requirement that asks the booted app
  lives in `Service/Health/`: `WebrootRequirement`, `EnvironmentRequirement`, `DataFileRequirement`
  and `LogDirectoryRequirement` are the framework's. A site's own requirements live in its own
  namespace and reach the report through `ownRequirements()`.

## The framework's floor

What `health v1` checks is `App::requirements()`. That is this floor, declared in
`Support\RequirementInitialization`, followed by whatever a site adds in its `ownRequirements()`. An
app that adds nothing, `TestApp` among them, reports exactly this table.

| Area | Requirement | Floor | Level |
|---|---|---|---|
| runtime | `php` | 8.5 or later, `composer.json`'s `^8.5` | required |
| extensions | `uri`, `dom`, `intl`, `openssl`, `zlib` | working, each proved by `PhpExtension::isPresent()` | required |
| settings | `post_max_size` | `ApiGate::MAX_BODY` (8M), or 0 | required |
| | `memory_limit` | 2 × `MAX_BODY` + 2 × `UpdateApplier::MAX_EXPANDED` (48M), or -1 | required |
| | `max_execution_time` | 30s, or 0 | required |
| | `display_errors` | off | required |
| | `log_errors` | on | required |
| | `opcache.enable` | on | optional |
| | `register_argc_argv` | off, which a site's `public/.user.ini` can set | optional |
| deployment | `DOCUMENT_ROOT` | a directory inside this deployment | required |
| deployment | `PHPANTA_ENVIRONMENT` | production | optional |
| | every tracked data file | present | required |
| | `logs/` | writable, so PHP can log into it | optional |

- **A site that takes uploads lists `Upload::requirements($maxBytes)`** in its `ownRequirements()`:
  `file_uploads` on, and `upload_max_filesize` and `post_max_size` at least the largest file it
  takes. They are not the floor because most sites take no file, and a floor that demanded
  `file_uploads` would fail a host that switched it off on purpose — the same reason
  `Database::requirement()` is a site's to list.
- **Pin these values to their sources.** The extension list and the PHP floor are stated in
  `composer.json` too, and the two size floors are derived from `MAX_BODY`. Composer never runs on
  the server to notice a drift, so a site's suite should hold the two statements to each other.
  `RequirementTest` reads its byte floors off `MAX_BODY` for the same reason.
- **The memory floor comes from the push, derived from the two caps rather than written out.** At
  its peak, a push holds four things at once, each bounded before it is held:
  - the body as read, at most `MAX_BODY`;
  - the tar that `gzdecode()` makes of it, at most `MAX_EXPANDED`, which is twice the body's cap;
  - each file's bytes cut out of that tar, as much again;
  - the interpreter and the codebase, about a body's worth.

  Both caps are enforced, so the floor is a bound on what any push can make a request hold, not only
  what an honest one needs.
- **Only the tracked data files are required.** An untracked file being absent is state, not a
  fault: no gate configured, no log yet. `capability v1 deployment` reports it without a verdict.
