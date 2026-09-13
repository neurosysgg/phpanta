<?php

declare(strict_types=1);

namespace Phpanta;

use DateTimeImmutable;
use NoDiscard;
use Phpanta\Controller\ApiController;
use Phpanta\Controller\Layer;
use Phpanta\Controller\Layered;
use Phpanta\Exception\AppException;
use Phpanta\Exception\UpdateException;
use Phpanta\Http\Answer;
use Phpanta\Http\HttpStatusCode;
use Phpanta\Http\PlainTextResponse;
use Phpanta\Http\Request;
use Phpanta\Http\Response;
use Phpanta\Http\Security\CspDirective;
use Phpanta\Http\Security\CspSource;
use Phpanta\Http\Security\PermissionsPolicy;
use Phpanta\Http\Security\PermissionsPolicyFeature;
use Phpanta\Http\Security\StrictTransportSecurity;
use Phpanta\Http\SecurityHeaders;
use Phpanta\Http\ServerVariable;
use Phpanta\Http\SessionSeal;
use Phpanta\Model\Health\Requirement;
use Phpanta\Service\Layer\SiteGate;
use Phpanta\Support\ApiPath;
use Phpanta\Support\Collection;
use Phpanta\Support\Directory;
use Phpanta\Support\ErrorLog;
use Phpanta\Support\File;
use Phpanta\Support\MethodPolicy;
use Phpanta\Support\RequirementInitialization;
use Phpanta\Support\Route;
use Phpanta\Text\Languages;
use Phpanta\View\FaultPage;
use Phpanta\View\Html\Vocabulary;
use Phpanta\View\Shell;
use Throwable;

/**
 * The App class. What a site tells the framework about itself, and the one place it is told.
 *
 * A site is a subclass — the framework's own suite runs under one, `TestApp` — and there is exactly
 * one per process, booted by the entry point and read back with {@link self::current()}. That is a
 * static in all but name, and deliberately so: the framework's deep code (the site gate, the API's
 * serial, the health report) needs the site's facts at the bottom of a call chain that never had a
 * reason to carry them, and threading an object through every constructor on the way down would
 * change forty signatures to move nine facts.
 *
 * **Three rules keep the static honest.**
 *
 * - **Constructing one does nothing.** No `DOCUMENT_ROOT`, no file, no request: the constructor is
 *   final and empty. Booting is therefore free, which is what lets `autoload.php` do it for every
 *   entry point at once, and what lets a `php -r` line in the verify script load a repository
 *   without knowing there is an app.
 * - **Booting is idempotent and exclusive.** The same class twice is the same instance — the dev
 *   router and `index.php` both load the autoloader — and a different class is refused, because two
 *   apps in one process would each be reading the other's data.
 * - **Nothing caches what an app answers.** Every question is asked when it is needed, so a test
 *   that wants a different answer passes a different object to the class that asks — the way
 *   {@link Service\UpdateApplier} takes its {@link Model\Update\Deployment} — rather than swapping
 *   the booted app out from under everything else.
 *
 * What a site owes the framework is the three abstract methods. What the framework derives from them
 * — where `data/` is, which directory is the webroot, where the update serial lives — is final here,
 * because each of those derivations was measured into its current shape and a site getting one of
 * them slightly different is how a mirror deletes the wrong tree.
 */
abstract class App
{
    /** The booted app, or null before {@link self::boot()}. */
    private static ?App $current = null;

    /** Constructs nothing but the object — see the class docblock for why that is the rule. */
    final public function __construct()
    {
    }

    /**
     * Boots this app for the rest of the process, and answers it.
     *
     * Idempotent for the same class, so an entry point that loads the autoloader twice boots once.
     *
     * @return static
     * @throws AppException if a different app is already booted.
     */
    public static function boot(): static
    {
        if (self::$current === null) {
            return self::$current = new static();
        }

        if (!self::$current instanceof static || self::$current::class !== static::class) {
            throw new AppException(sprintf(
                '%s is already booted, so %s cannot be: two apps in one process would each read the '
                . "other's data.",
                self::$current::class,
                static::class,
            ));
        }

        return self::$current;
    }

    /**
     * The booted app.
     *
     * Asked as `App::current()` it answers whatever is booted; asked on a site's own subclass it
     * also insists the booted app is that subclass, so a site's own code gets its own type back.
     *
     * @return static
     * @throws AppException if nothing is booted, or what is booted is not this class.
     */
    public static function current(): static
    {
        $app = self::$current ?? throw new AppException(
            'No app is booted. autoload.php boots the site for every entry point; a caller that loads '
            . 'classes some other way — composer, in the test suite — boots it itself.',
        );

        if (!$app instanceof static) {
            throw new AppException(sprintf('%s is booted, not %s.', $app::class, static::class));
        }

        return $app;
    }

    // ───────────────────────── what a site owes ─────────────────────────

    /**
     * The site's name: the Basic Auth realm on every gate, and the title a page is named under.
     *
     * @return string
     */
    abstract public function name(): string;

    /**
     * The directory the deployment sits in: `src/`, `data/`, `autoload.php` and the webroot.
     *
     * The repository root locally, wherever the host puts it live. **It must be the directory
     * holding the autoloader**, whatever the framework's own files happen to sit in — the update
     * serial and the push's mirror both hang off it, and a wrong answer here moves the serial (a
     * replay window, in silence) or points the mirror at the wrong tree.
     *
     * @return Directory
     */
    abstract public function above(): Directory;

    /**
     * Every address the site answers on, in the order the router asks them.
     *
     * The site's own pages only — the API is the framework's, and {@link self::routeTable()} adds it.
     *
     * @return Collection<Route>
     */
    abstract public function routes(): Collection;

    /**
     * What the site says about an address it does not have, to a method that reads.
     *
     * The page, rendered in the site's own shell — which is the site's to draw.
     * {@link Controller\UnroutedController} asks this for the read-only case and answers the write
     * one itself, so the 404 a typo gets and the 404 an unsigned API call gets are one page.
     *
     * @param Request $request
     * @return Response
     */
    abstract public function notFound(Request $request): Response;

    /**
     * The languages the site is written in, its default first.
     *
     * What {@link Http\Request::language()} chooses among, and what a language switch lists. A
     * language the framework can write and the site does not offer is answered as if it were
     * nothing at all.
     *
     * @return Languages
     */
    abstract public function languages(): Languages;

    /**
     * The document every page is rendered inside. The site's to draw — see {@link Shell}.
     *
     * @return Shell
     */
    abstract public function shell(): Shell;

    /**
     * Every tag and attribute hand-authored markup may be parsed into: {@link Vocabulary::standard()}
     * and the site's own custom elements and their attributes.
     *
     * @return Vocabulary
     */
    abstract public function vocabulary(): Vocabulary;

    /**
     * Which build this deployment is serving, as `update v1 version` reports it.
     *
     * The site's to say, because the build is the site's: what changes when it is rebuilt is the
     * site's own assets, and the framework has no build of its own to name.
     *
     * @return string
     */
    abstract public function buildId(): string;

    // ───────────────────────── what a site may add ─────────────────────────

    /**
     * The third-party origins the site loads from under $directive, beyond its own.
     *
     * Asked by {@link Http\SecurityHeaders::contentSecurityPolicy()} for every fetch directive a
     * site can reasonably need one for — scripts, styles, images, frames, connections, media and
     * fonts — and nothing by default, which is the strict policy a site that loads nothing from
     * anywhere else should have. Never asked for `default-src`, which stays `'self'` because every
     * other directive narrows it, or `object-src`, which stays `'none'` because nothing legitimate
     * uses the plugin surface.
     *
     * @param CspDirective $directive
     * @return Collection<CspSource>
     */
    public function contentHosts(CspDirective $directive): Collection
    {
        return new Collection(CspSource::class);
    }

    /**
     * The `Strict-Transport-Security` the site sends: a year, subdomains included, by default.
     *
     * What it protects is any credential a request carries — HTTP Basic is base64, legible to
     * anyone on the path of a plaintext request, and a redirect to `https://` cannot help the
     * request that already crossed. A site overrides this for the ramp,
     * {@link StrictTransportSecurity::ONE_DAY} while it checks that every name under its domain
     * serves HTTPS, or for an estate with a name that cannot, which is the one reason to drop
     * `includeSubDomains`. See that class before raising it on an estate you have not checked.
     *
     * @return StrictTransportSecurity
     */
    public function strictTransportSecurity(): StrictTransportSecurity
    {
        return new StrictTransportSecurity();
    }

    /**
     * The `Permissions-Policy` the site sends: every {@link PermissionsPolicyFeature} denied, by
     * default.
     *
     * A site that needs one of them overrides this with {@link PermissionsPolicy::deny()} over the
     * rest. The policy also binds every frame the site embeds, so a feature a player asks for in
     * its `allow` attribute is one this must not deny.
     *
     * @return PermissionsPolicy
     */
    public function permissionsPolicy(): PermissionsPolicy
    {
        return PermissionsPolicy::denyAll();
    }

    /**
     * What the site stands around every request, outermost first, inside the framework's site gate.
     * Nothing by default.
     *
     * Listed here and nowhere else — a maintenance switch, a header every answer carries. What
     * stands around one route is that route's to list, with {@link Route::through()}. See
     * {@link self::layerTable()}.
     *
     * @return Collection<Layer>
     */
    protected function layers(): Collection
    {
        return new Collection(Layer::class);
    }

    /**
     * What the site needs of its host beyond the framework's floor. Nothing by default.
     *
     * @return Collection<Requirement>
     */
    protected function ownRequirements(): Collection
    {
        return new Collection(Requirement::class);
    }

    /**
     * The files the site's own code reads out of `data/` — its catalogue, its pages, its logs.
     *
     * Not the framework's: those are {@link CredentialFile}'s, and {@link self::dataFiles()} puts
     * the two together.
     *
     * @return Collection<DataFileName>
     */
    abstract protected function ownDataFiles(): Collection;

    // ───────────────────────── what the framework derives ─────────────────────────

    /**
     * The `data/` directory, which lives outside the webroot.
     *
     * It is where the credentials live, so a directory that resolves somewhere unexpected is not a
     * small mistake — which is why there is one derivation of it rather than one per reader.
     *
     * @return Directory
     */
    final public function data(): Directory
    {
        return $this->above()->directory('data');
    }

    /**
     * The webroot, whatever the server calls it.
     *
     * **The one path here that is not derived, because it cannot be.** The directory is `public/`
     * in the repository and whatever the host named it on a live one, and nothing under `src/` can
     * know that. `DOCUMENT_ROOT` does, so this asks it — for the directory's *name* and nothing
     * else, hanging that name off {@link self::above()} like every other path here.
     *
     * **Taking only the basename is the whole of the care here, and it was measured into being.**
     * A shared host can report `DOCUMENT_ROOT` through one mount of an NFS export while `__DIR__`
     * for a file in that very directory reads through another — `/home/…/htdocs/webroot` against
     * `/mnt/…/htdocs/webroot`. They are the same directory reached two ways; as strings they are
     * not equal and never will be. Use `DOCUMENT_ROOT` whole and this path stops comparing equal to
     * {@link Service\UpdateApplier}'s walk of it, which is a mirror that deletes everything it just
     * wrote.
     *
     * Server-set, never client-controlled — it is not an HTTP header and no request can name it.
     *
     * **An absent one throws rather than falling back**, and the rejected fallback is worth naming
     * because it looks reasonable and is catastrophic: returning {@link self::above()} would make
     * the webroot the deployment directory itself, and the mirror in {@link Service\UpdateApplier}
     * would then walk `src/`, `data/`, `vendor/` and `node_modules/`, find none of them in the
     * payload, and delete the site. There is no safe guess for this value; the only safe answer is
     * to stop. CLI is where it is absent, which is PHPUnit and the tools — none of which has any
     * business resolving a webroot.
     *
     * @return Directory
     *
     * @throws UpdateException if `DOCUMENT_ROOT` is not set.
     */
    final public function webroot(): Directory
    {
        // Trimmed, because a value of only whitespace is not a path and must not be treated as
        // one. Untrimmed it slipped past the guard below rather than this one: dirname('   ') is
        // '.', whose realpath is the working directory, which under the test runner *is* the
        // deployment — so a webroot named three spaces was resolved and accepted.
        $root = trim(ServerVariable::DocumentRoot->string() ?? '');

        if ($root === '') {
            throw new UpdateException(
                'DOCUMENT_ROOT is not set, so the webroot cannot be resolved. This is reachable '
                . 'only off a real request; there is no default, because every candidate default '
                . 'is a directory something would then be willing to delete.',
            );
        }

        // Absolute, because the containment check below reasons about dirname($root): for a bare
        // relative name that is '.', whose realpath is the working directory — which under a test
        // runner is the deployment, so a relative value would borrow the blessing meant for the
        // real one. A real server always reports an absolute DOCUMENT_ROOT; anything else is
        // refused rather than resolved against wherever the process happened to be started.
        if (!str_starts_with($root, '/')) {
            throw new UpdateException(sprintf(
                "DOCUMENT_ROOT is '%s', which is not an absolute path. Refusing rather than "
                . 'resolving it against the working directory, which is not where a webroot is.',
                $root,
            ));
        }

        $name = basename(rtrim($root, '/'));

        // `.` and `..` satisfy the containment check below, because it reasons about the parent —
        // and `/…/deployment/.` *is* the deployment, the directory the docblock above names as the
        // catastrophic webroot. `..` names the directory above it. Neither is a webroot's name.
        if ($name === '.' || $name === '..') {
            throw new UpdateException(sprintf(
                "DOCUMENT_ROOT is '%s', which ends in a dot segment rather than naming a directory. "
                . 'Refusing rather than resolving it: it names the deployment or the directory above it.',
                $root,
            ));
        }

        // The basename is only safe to graft onto above() once the two are known to be the same
        // tree, and that is checked rather than assumed. Without this, a DOCUMENT_ROOT pointing
        // anywhere else whose last segment happened to be `public` would resolve to *this*
        // repository's public/ — which is not a hypothetical: it is what a test did, and the mirror
        // then deleted the tree it resolved to. realpath() on both sides is what collapses the two
        // spellings the live host reports for one directory.
        $parent = realpath(dirname($root));
        $above  = realpath($this->above()->path);

        if ($parent === false || $above === false || $parent !== $above) {
            throw new UpdateException(sprintf(
                "DOCUMENT_ROOT is '%s', which is not a directory inside this deployment (%s). "
                . 'Refusing rather than guessing: the guess would name a real directory somewhere '
                . 'else, and a mirror would empty it.',
                $root,
                $this->above()->path,
            ));
        }

        $webroot = $this->above()->directory(basename(rtrim($root, '/')));

        // The parent being right is not the same claim as the directory being there, and the
        // difference is not cosmetic: `/…/deployment/nonexistent` passes the check above, because
        // its *parent* is the deployment. Left unasked, that name becomes a webroot a push would
        // then create and write 11 files into, beside the real one, with nothing serving them.
        if (!$webroot->exists()) {
            throw new UpdateException(sprintf(
                "DOCUMENT_ROOT is '%s', which names no directory. Refusing rather than creating "
                . 'one: a webroot that has to be made up is not a webroot.',
                $root,
            ));
        }

        return $webroot;
    }

    /**
     * The highest update serial this deployment has accepted.
     *
     * **Kept above the webroot rather than in `data/`, and that placement is load-bearing.** The
     * two trees an update mirrors are wiped and rewritten, so it cannot live in either; `data/` is
     * rsynced from the working tree without `--delete`, so a copy there would be pushed up from
     * whichever machine deployed last and could hand an attacker a replay window by moving the
     * number backwards. The deployment directory itself is rsynced as a whole by nothing at all.
     *
     * @return File
     */
    final public function updateSerial(): File
    {
        return $this->above()->file('.update-serial');
    }

    /**
     * Resolves a file inside `data/`.
     *
     * It hands back a {@link File} rather than a string, because every one of its callers asks the
     * same two questions of the path — is it there, and what is in it — and `File` answers them in
     * one set of words rather than each caller's own. The argument is a {@link DataFileName} rather
     * than a path because every caller collapses a missing file to an empty result, so a mistyped name
     * is not an error anywhere — it is an empty catalogue, an empty footer, or a gate that stands
     * down. See that enum.
     *
     * @param DataFileName $file The file, named rather than spelled.
     * @return File
     */
    final public function dataFile(DataFileName $file): File
    {
        return $this->data()->file($file->value);
    }

    /**
     * Every file this app reads out of `data/`: the framework's credentials, then the site's own.
     *
     * One list for the two readers that describe the directory rather than use it — the health
     * report asks for every tracked file, the deployment capability reports on all of them — so
     * neither has to know which side of the line a file was declared on.
     *
     * @return Collection<DataFileName>
     */
    final public function dataFiles(): Collection
    {
        return new Collection(DataFileName::class)
            ->with(...CredentialFile::cases())
            ->with(...$this->ownDataFiles()->toValues());
    }

    /**
     * Everything this deployment needs of its host — what `health v1` checks: the framework's
     * floor, then whatever the site adds.
     *
     * @return Collection<Requirement>
     */
    final public function requirements(): Collection
    {
        return RequirementInitialization::requirements($this)->with(...$this->ownRequirements()->toValues());
    }

    /**
     * What this deployment's sessions are sealed with: the key in `data/session.key`.
     *
     * Asked only when something keeps a session, so a site that keeps none never needs the file; one
     * that does and has no key is told so, loudly, with how to mint one — see
     * {@link Http\SessionSeal::fromFile()}.
     *
     * @return SessionSeal
     */
    final public function sessionSeal(): SessionSeal
    {
        return SessionSeal::fromFile($this->dataFile(CredentialFile::SessionKey));
    }

    /**
     * `data/logs/`: what the site writes, beside what it reads.
     *
     * Gitignored and excluded from a full deploy, so it exists only where somebody made it — and
     * nothing here makes it, for the reason {@link File} gives. `health v1` warns where it is
     * missing or unwritable; see {@link Service\Health\LogDirectoryRequirement}.
     *
     * @return Directory
     */
    final public function logs(): Directory
    {
        return $this->data()->directory('logs');
    }

    /**
     * This month's PHP error log, which {@link self::run()} points `error_log` at. See
     * {@link ErrorLog}.
     *
     * @return File
     */
    final public function errorLog(): File
    {
        return ErrorLog::file($this->logs(), new DateTimeImmutable());
    }

    // ───────────────────────── the request ─────────────────────────

    /**
     * Every route the router asks: the site's own, then the framework's API.
     *
     * The API goes last, so no site route can be shadowed by it, and it is added here rather than
     * registered by each site, so no site can forget it — or register it with the wrong policy.
     *
     * @return Collection<Route>
     */
    final public function routeTable(): Collection
    {
        return $this->routes()->with($this->apiRoute());
    }

    /**
     * The framework's API route: `/api/{service}/{version}/{action}`, every method to its
     * controller.
     *
     * Public because it is also the one honest answer to "is this an API address?" — the route's
     * own match, which is what {@link Service\Layer\Maintenance} asks rather than a prefix.
     *
     * @return Route
     */
    final public function apiRoute(): Route
    {
        return new Route(
            ApiPath::Api,
            // The captures go through as raw strings. Resolving them to cases here would put a
            // from() in the factory, and a ValueError raised before the signature is checked is
            // both a 500 that announces the endpoint and an exception nothing here owns.
            fn($service, $version, $action) => new ApiController($service, $version, $action),
            // The only route the router forms no opinion about. Every method reaches the
            // controller, including one the site does not recognise, because any refusal the router
            // made here would differ from the one it makes for an address that does not exist — and
            // being indistinguishable from that is the whole design. See MethodPolicy.
            MethodPolicy::Delegated,
        );
    }

    /**
     * What stands around every request, outermost first: the framework's site gate, then the
     * site's own.
     *
     * The site gate is the framework's to put first, so no site can forget it or list something
     * ahead of it that should have been behind it. See {@link Controller\Layer}.
     *
     * @return Collection<Layer>
     */
    final public function layerTable(): Collection
    {
        return new Collection(Layer::class)->with(new SiteGate(), ...$this->layers()->toValues());
    }

    /**
     * What this app answers $request with, sending nothing.
     *
     * The pre-launch gate's refusal if it has one, and the route's answer otherwise, with the
     * security headers ahead of everything — the whole of a request but the process around it.
     * {@link self::run()} is this and a `send()`; a test is this and an assertion.
     *
     * @param Request $request
     * @return Answer
     */
    #[NoDiscard('handle() works out the answer and sends nothing; a call whose result goes nowhere answered no one')]
    final public function handle(Request $request): Answer
    {
        $response = Layered::around($this->layerTable(), new Router($this->routeTable()))->handle($request);

        return $response->answer($request)->withHeadersFirst(SecurityHeaders::all($this));
    }

    /**
     * Answers the request this process was started for.
     *
     * The order is the one `public/index.php` has always had, minus the handler it installs before
     * calling this: the error log, so every diagnostic from here on lands in `data/logs/`; the
     * security headers, before anything could fail, so that even the site's last-resort 500 carries
     * them; then the request, answered by {@link self::handle()} and sent. The answer carries the
     * same five headers again, first, and sending them replaces the ones already out — see
     * {@link Answer::send()}.
     *
     * @return void
     */
    final public function run(): void
    {
        ErrorLog::install($this->errorLog());

        SecurityHeaders::send();

        $request = Request::fromGlobals();

        // A fault anywhere in handle() — a controller, a view, the shell — is still an answer: it is
        // logged, and fault() decides what the visitor is told. What escapes this, a fault while
        // the answer is already going out, is the front controller's last-resort handler's.
        try {
            $answer = $this->handle($request);
        } catch (Throwable $fault) {
            error_log(ErrorLog::faultLine($fault, $this->name()));

            $answer = $this->fault($fault, $request);
        }

        $answer->send();
    }

    /**
     * Which environment this deployment is: {@link Environment::Production} unless the server says
     * `PHPANTA_ENVIRONMENT=development`, exactly — see that enum for why every other value is
     * production.
     *
     * A site may override this to pin production outright, and nothing else should: the answer
     * comes from the server so that no file in a deployment can switch a trace on.
     *
     * @return Environment
     */
    public function environment(): Environment
    {
        return Environment::tryFrom(ServerVariable::Environment->string() ?? '') ?? Environment::Production;
    }

    /**
     * What a request that faulted is answered with, sending nothing — {@link self::run()}'s twin
     * for its catch.
     *
     * A bare `500`, in plain text, saying nothing but the status, to every request the environment
     * does not show faults to; a {@link FaultPage} to one it does. The security headers lead
     * either way.
     *
     * @param Throwable        $fault
     * @param Request          $request
     * @param Environment|null $environment The environment to answer as; this app's by default. A
     *                                      test passes one rather than setting a server variable.
     * @return Answer
     */
    #[NoDiscard('fault() works out the 500 and sends nothing; a call whose result goes nowhere answered no one')]
    final public function fault(Throwable $fault, Request $request, ?Environment $environment = null): Answer
    {
        $response = ($environment ?? $this->environment())->showsFaultsTo($request)
            ? new FaultPage($fault)
            : new PlainTextResponse(HttpStatusCode::InternalServerError, "500\n");

        return $response->answer($request)->withHeadersFirst(SecurityHeaders::all($this));
    }
}
