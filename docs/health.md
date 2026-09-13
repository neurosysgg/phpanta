# Health and capability

Two read-only services under `/api`. Each call is signed like every other one, and each is just as
invisible without a signature. They answer two different questions and never mix them:

- **`capability` says what this host has**: every extension it loaded, every php.ini directive, its
  runtime, its deployment's files, its error log. No line is a claim and nothing is judged.
- **`health` says whether this host meets what the site needs.** Every line is a requirement with a
  floor, plus its verdict. A line is a claim exactly when it is under `health`.

The one overlap is deliberate. `capability v1 extensions` lists what is **registered**, and
`health v1 extensions` proves that the extensions the site needs actually **work**, by using them.
Registered and working are two questions.

What `capability` said about Strato, the local Apache and the CLI, side by side, is in
[runtime.md](https://github.com/neurosysgg/neurosys-webspace/blob/master/docs/runtime.md).

## Addresses

```bash
php tools/api.php capability v1 <action>
php tools/api.php health v1 <action>
```

| `capability v1` | answers |
|---|---|
| `runtime` | PHP version and `PHP_VERSION_ID`, SAPI, Zend version, OS family; the server software, protocol, `uname`, clock and `date.timezone` |
| `extensions` | every loaded extension, then every Zend extension, each with its version |
| `settings` | every php.ini directive the engine knows, with the local value a request runs under |
| `deployment` | the webroot, and every `DataFile` as present or absent, tracked or untracked |
| `errors` | the reporting mask, `display_errors`, `log_errors`, `error_log`, `error_get_last()`, and the log's last 20 lines |

| `health v1` | checks |
|---|---|
| `runtime` · `extensions` · `settings` · `deployment` | the declared requirements in that area |
| `report` | every area, then a tally: `19 pass, 1 warn, 0 fail` |

A `health` line gives the name, the verdict, what was found, and the floor. The verdict comes first
because it is the column worth reading down:

```
settings
  post_max_size        pass  128M  (at least 8M or 0 for no limit)
  opcache.enable       warn  0  (on, optional)
```

**Each area has an address of its own and is never a parameter.** The signature does not cover the
query string, so `?area=settings` would be the one input reaching a verified handler unsigned. See
[security.md](https://github.com/neurosysgg/neurosys-webspace/blob/master/docs/security.md#the-api).

## The status is the verdict

| When | Status | `tools/api.php` exits |
|---|---|---|
| a required requirement is unmet (`FAIL`) | `503` | 1 |
| only optional ones are unmet (`warn`) | `200` | 0 |
| nothing is unmet | `200` | 0 |

**The body is the whole report either way.** A `503` with nothing in it would say that something is
wrong while withholding what, from the one caller who has proved they may know. `HealthCheck`
*returns* the `503` rather than throwing it, because `ApiController` turns an `ApiException` into
a `422`, and that would report an unhealthy host as a malformed request.

`tools/api.php` prints the body and exits on the status, so a failed check can stop a script after a
push. Only a `404` is explained as a refusal ("check the key, the clock, the server's age"), because
only a `404` is one. Any other status is reported as `answered 503.`, and the body above it already
says what failed.

**Strato passes a `503`'s body through unchanged**: `HTTP/2 503`, `text/plain`, the report byte
for byte. Nothing in `public/.htaccess` replaces an error body either. A front proxy *can*
substitute its own page for a 5xx, which is why this was asked of the live host rather than assumed,
with a probe push declaring one impossible requirement. Ask again the same way if the host changes;
see [deployment.md](https://github.com/neurosysgg/neurosys-webspace/blob/master/docs/deployment.md#probing-the-live-host). ([history](https://github.com/neurosysgg/neurosys-webspace/blob/master/docs/history/api.md))

## Declaring a requirement

Every requirement is declared in one place, `phpanta/src/Support/RequirementInitialization.php`,
the same way `RouteInitialization` declares routes. **It is code in `src/`, never a file in
`data/`.** A requirement exists because some code needs it, so the two have to deploy together.
Every push ships `src/` and none ships `data/`, so a floor declared in a data file could take effect
a week before or after the code that set it.

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
  `Config::webroot()`'s refusal and reports the refusal's sentence. The core catches nothing on your
  behalf, because `GuidelineTest` refuses `catch (Throwable)`, so a single throw becomes a 500 for
  the whole report.
- **Pick one of the four areas.** Each area is an address, so there is no fifth. `deployment` means
  "this installation", and it is where anything an application checks about its own surroundings
  belongs.
- **Anything that knows this site stays out of `Model/Health/`.** That namespace imports nothing of
  this site's, so it can be lifted out whole. The site's own requirements live in
  `Service/Health/`.

## The framework's floor

What `health v1` checks is `App::requirements()`: this floor, declared in
`Support\RequirementInitialization`, and then whatever a site adds in its `ownRequirements()`.
neuro.SYS adds nothing, so its report is this table.

| Area | Requirement | Floor | Level |
|---|---|---|---|
| runtime | `php` | 8.5 or later, `composer.json`'s `^8.5` | required |
| extensions | `uri`, `dom`, `intl`, `openssl`, `zlib` | working, each proved by `PhpExtension::isPresent()` | required |
| settings | `post_max_size` | `ApiGate::MAX_BODY` (8M), or 0 | required |
| | `memory_limit` | 4 × `MAX_BODY` (32M), or -1 | required |
| | `max_execution_time` | 30s, or 0 | required |
| | `display_errors` | off | required |
| | `log_errors` | on | required |
| | `opcache.enable` | on | optional |
| | `register_argc_argv` | off, set by `public/.user.ini` | optional |
| deployment | `DOCUMENT_ROOT` | a directory inside this deployment | required |
| | every tracked data file | present | required |
| | `logs/` | writable, so PHP can log into it | optional |

- **neuro.SYS's `HealthTest` pins these values to their sources.** It checks the extension list and the PHP
  floor against `composer.json`, and the two size floors against `MAX_BODY`.
- **The memory floor comes from the push.** At its peak a push holds three copies of about
  `MAX_BODY` at once: the body, the decoded tar, and each file's bytes. The fourth copy is headroom
  for the interpreter itself.
- **Only the tracked data files are required.** An untracked file being absent is state, not a
  fault: no demos staged, no gate, no log yet. `capability v1 deployment` reports it without a
  verdict.
