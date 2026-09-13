# Tooling — the framework

The command-line layer every tool is built on, and the second autoloader that keeps the tooling
off the server. A site's own commands are its own; neuro.SYS's are in [its tooling](https://github.com/neurosysgg/neurosys-webspace/blob/master/docs/tooling.md).

## The CLI layer

**`Command::options()` is not decoration.** It is what lets `Input` refuse a flag the command never
declared: a flag dropped in silence reports success and does nothing, which for `merge-coverage`
would mean a mistyped `--clover` writing no report. `getopt()` is not the answer: it stops at the
first non-option argument, and `composer coverage` passes both of its paths first.

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
