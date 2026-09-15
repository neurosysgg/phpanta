<?php

declare(strict_types=1);

namespace Phpanta\Test\Unit;

use Phpanta\App;
use Phpanta\CredentialFile;
use Phpanta\DataFileName;
use Phpanta\Exception\AppException;
use Phpanta\Exception\UpdateException;
use Phpanta\Http\HttpStatusCode;
use Phpanta\Http\PlainTextResponse;
use Phpanta\Http\Request;
use Phpanta\Http\Response;
use Phpanta\Http\Security\CspDirective;
use Phpanta\Http\ServerVariable;
use Phpanta\Model\Health\Requirement;
use Phpanta\Support\AdminPath;
use Phpanta\Support\Collection;
use Phpanta\Support\Directory;
use Phpanta\Support\RequirementInitialization;
use Phpanta\Support\Route;
use Phpanta\Test\TestApp;
use Phpanta\Text\Language;
use Phpanta\Text\Languages;
use Phpanta\View\Html\Vocabulary;
use Phpanta\View\Shell;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The app: the three rules that keep one per process honest, and the paths the framework derives
 * from it.
 *
 * Asserted against {@link TestApp}, whose deployment is a fixture directory holding an empty
 * `public/`, so every derived path has somewhere real to land and none of them lands in a
 * repository. What a site's own app answers — its name, its data files, its hosts — is that site's
 * suite's to assert; what is asserted here is what the framework does with any app's answers.
 *
 * **The webroot is the one derivation this file cares most about**, because it is the one that
 * once emptied a repository. {@link App::webroot()} takes no request — it is asked off one, by the
 * push — so it reads `DOCUMENT_ROOT` out of the process's own server variables, and the tests of it
 * set that one variable for the length of one call and put it back.
 */
#[CoversClass(App::class)]
#[CoversClass(AppException::class)]
#[CoversClass(UpdateException::class)]
#[CoversClass(CredentialFile::class)]
final class AppTest extends TestCase
{
    // ───────────────────────────── the booted app ─────────────────────────────

    /**
     * Booting twice is booting once, which a site's dev router needs: it loads the autoloader, and
     * then the entry point loads it again.
     *
     * @return void
     */
    public function testBootingTheSameAppTwiceIsTheSameApp(): void
    {
        self::assertSame(TestApp::current(), TestApp::boot());
        self::assertSame(TestApp::current(), App::current());
    }

    /**
     * A second app beside the first would be reading the other one's data, so it is refused.
     *
     * @return void
     */
    public function testASecondAppIsRefused(): void
    {
        $this->expectException(AppException::class);
        $this->expectExceptionMessage('is already booted');

        (void) self::other()::boot();
    }

    /**
     * Asked as a class that is not the booted one, `current()` refuses rather than answering an app
     * of a type the caller did not ask for.
     *
     * @return void
     */
    public function testTheCurrentAppIsOnlyAnsweredAsItsOwnClass(): void
    {
        $this->expectException(AppException::class);
        $this->expectExceptionMessage('is booted, not');

        (void) self::other()::current();
    }

    // ───────────────────────────── paths ─────────────────────────────

    /**
     * `data/` hangs off the deployment, and a data file is named inside it — the one derivation of
     * the directory the credentials live in, rather than one per reader.
     *
     * @return void
     */
    public function testADataFileIsNamedInsideTheDataDirectoryAboveTheWebroot(): void
    {
        $app = App::current();

        self::assertSame($app->above()->path . '/data', $app->data()->path);
        self::assertSame($app->data()->path . '/admin.php', $app->dataFile(CredentialFile::Admin)->path);
        self::assertStringStartsNotWith($app->above()->path . '/public/', $app->data()->path);
    }

    /**
     * The log directory is inside `data/`, and the error log is this month's file in it.
     *
     * @return void
     */
    public function testTheErrorLogIsThisMonthsFileInTheLogDirectory(): void
    {
        $app = App::current();

        self::assertSame($app->data()->path . '/logs', $app->logs()->path);
        self::assertSame($app->logs()->file('php-' . date('Y-m') . '.log')->path, $app->errorLog()->path);
    }

    /**
     * The replay serial sits above the webroot, in neither tree a push mirrors and in no rsync.
     *
     * @return void
     */
    public function testTheUpdateSerialSitsAboveTheWebroot(): void
    {
        self::assertSame(App::current()->above()->path . '/.update-serial', App::current()->updateSerial()->path);
    }

    /**
     * The framework's credentials come first, then whatever the app declares — and an app that
     * declares nothing has the three credentials and nothing else.
     *
     * @return void
     */
    public function testTheDataFilesAreTheCredentialsThenTheAppsOwn(): void
    {
        self::assertSame(CredentialFile::cases(), App::current()->dataFiles()->toValues());
    }

    /**
     * Whether a credential is in a repository is part of what it is: each exists only per
     * deployment, where a public repository cannot publish it.
     *
     * @return void
     */
    public function testNoCredentialFileIsTracked(): void
    {
        foreach (CredentialFile::cases() as $file) {
            self::assertFalse($file->isTracked(), $file->value);
        }
    }

    // ───────────────────────────── what an app may add ─────────────────────────────

    /**
     * What `health v1` checks is the framework's floor with the app's own on top — and an app that
     * adds none gets exactly the floor.
     *
     * @return void
     */
    public function testTheRequirementsAreTheFrameworksFloorWhenTheAppAddsNone(): void
    {
        // By name: a requirement can hold the closure that checks it, and closures do not compare.
        $names = static fn(Requirement $requirement): string => $requirement->name();

        self::assertSame(
            RequirementInitialization::requirements(App::current())->map($names)->toValues(),
            App::current()->requirements()->map($names)->toValues(),
        );
    }

    /**
     * An app that loads nothing from anywhere else is asked for hosts and has none, which is the
     * strict policy it should get.
     *
     * @return void
     */
    public function testAnAppNamesNoThirdPartyHostUnlessItSaysSo(): void
    {
        foreach (CspDirective::cases() as $directive) {
            self::assertTrue(self::other()->contentHosts($directive)->isEmpty(), $directive->value);
        }
    }

    /**
     * The route table is the app's own routes and then the admin's, which no app registers and so
     * none can forget. An app with no routes of its own has exactly the admin's five, and none of
     * them is a page of a static export.
     *
     * @return void
     */
    public function testTheRouteTableEndsInTheAdminRoutes(): void
    {
        $routes = App::current()->routeTable()->toValues();

        self::assertCount(5, $routes);

        foreach ($routes as $index => $route) {
            self::assertSame(AdminPath::cases()[$index], $route->path());
            self::assertTrue($route->accepts(null), 'an admin route stopped delegating its methods');
            self::assertTrue($route->exportedPaths()->isEmpty(), 'an admin route became a page to export');
        }
    }

    // ───────────────────────────── the webroot ─────────────────────────────

    /**
     * The webroot is the one path here that cannot be derived, so it is asked for — and refused
     * rather than guessed.
     *
     * **This is the method that once emptied a repository**, so what each case asserts is worth
     * saying plainly. The directory is called `public/` in a repository and something else on a live
     * host, and nothing in the framework can know that; `DOCUMENT_ROOT` does. Taking it whole does
     * not work either — a shared host can report one directory under two different absolute paths,
     * and a mirror compares paths. So only the *basename* is taken, and the basename is only
     * meaningful once the two are known to be the same tree.
     *
     * @return void
     */
    public function testTheWebrootIsResolvedFromDocumentRoot(): void
    {
        self::assertSame(self::deployment() . '/public', self::webrootFor(self::deployment() . '/public'));
    }

    /**
     * A trailing slash is the shape a server is as likely to report as not.
     *
     * @return void
     */
    public function testTheWebrootIgnoresATrailingSlash(): void
    {
        self::assertSame(self::deployment() . '/public', self::webrootFor(self::deployment() . '/public/'));
    }

    /**
     * Absent, it stops. There is no default and there must not be one.
     *
     * @param string|null $root Null for a `DOCUMENT_ROOT` that is not there at all.
     * @return void
     */
    #[DataProvider('absentDocumentRootProvider')]
    public function testAnAbsentDocumentRootIsRefusedRatherThanDefaulted(?string $root): void
    {
        $this->expectException(UpdateException::class);
        $this->expectExceptionMessage('DOCUMENT_ROOT is not set');

        self::webrootFor($root);
    }

    /**
     * @return iterable<string, array{string|null}>
     */
    public static function absentDocumentRootProvider(): iterable
    {
        yield 'unset'      => [null];
        yield 'empty'      => [''];
        yield 'whitespace' => ['   '];
    }

    /**
     * A `DOCUMENT_ROOT` outside the deployment is refused, and this is the guard that matters most.
     *
     * A basename grafted onto a different tree names a real directory somewhere else. That is not
     * hypothetical: a test pointing `DOCUMENT_ROOT` at a sandbox whose last segment was `public`
     * got the *repository's* `public/` back, and the update mirror emptied it. `realpath()` on
     * both sides is what collapses the two spellings a host can report for one directory, and
     * comparing them is what turns a plausible guess into a refusal.
     *
     * @param string $name
     * @return void
     */
    #[DataProvider('foreignDocumentRootProvider')]
    public function testADocumentRootOutsideTheDeploymentIsRefused(string $name): void
    {
        $sandbox = Directory::temporary('phpanta-webroot-');
        $root    = $sandbox->directory($name);

        self::assertTrue($root->create());

        try {
            $this->expectException(UpdateException::class);
            $this->expectExceptionMessage('not a directory inside this deployment');

            self::webrootFor($root->path);
        } finally {
            $root->remove();
            $sandbox->remove();
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function foreignDocumentRootProvider(): iterable
    {
        // The first is the exact shape that did the damage: a directory called `public`, somewhere
        // else entirely. The second is a name the deployment has no directory for at all.
        yield 'named public elsewhere' => ['public'];
        yield 'named anything else'    => ['htdocs'];
    }

    /**
     * A path that does not exist cannot be shown to be inside the deployment, so it is not.
     *
     * @return void
     */
    public function testADocumentRootThatDoesNotExistIsRefused(): void
    {
        $this->expectException(UpdateException::class);
        $this->expectExceptionMessage('not a directory inside this deployment');

        self::webrootFor(self::deployment() . '/no-such-directory/public');
    }

    /**
     * A name whose *parent* is the deployment but which is not there is refused too.
     *
     * The containment check above is satisfied by this — its parent really is the deployment — so
     * without a second question it resolves to a `Directory` that does not exist, and the first
     * push would create it and write the whole webroot into it beside the real one, served by
     * nothing. The two checks ask different things and both are needed.
     *
     * @return void
     */
    public function testADocumentRootNamingNoDirectoryIsRefused(): void
    {
        $this->expectException(UpdateException::class);
        $this->expectExceptionMessage('names no directory');

        self::webrootFor(self::deployment() . '/not-a-real-webroot');
    }

    /**
     * A relative `DOCUMENT_ROOT` is refused rather than resolved against the working directory.
     *
     * The containment check reasons about `dirname($root)`, which for a bare name is `.` — whose
     * realpath is the cwd, and under a test runner the cwd can be the deployment. So without the
     * absolute check a relative value could pass containment and graft its basename onto the
     * deployment, the one confusion an absolute path cannot cause. A real server never reports one.
     *
     * @return void
     */
    public function testARelativeDocumentRootIsRefused(): void
    {
        $this->expectException(UpdateException::class);
        $this->expectExceptionMessage('not an absolute path');

        self::webrootFor('public');
    }

    /**
     * A `DOCUMENT_ROOT` ending in `.` or `..` is refused rather than grafted.
     *
     * Both satisfy containment, which reasons about the parent — and `…/deployment/.` *is* the
     * deployment, the one webroot a push's mirror must never be pointed at.
     *
     * @param string $suffix
     * @return void
     */
    #[DataProvider('dotSegmentDocumentRootProvider')]
    public function testADocumentRootEndingInADotSegmentIsRefused(string $suffix): void
    {
        $this->expectException(UpdateException::class);
        $this->expectExceptionMessage('ends in a dot segment');

        self::webrootFor(self::deployment() . $suffix);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function dotSegmentDocumentRootProvider(): iterable
    {
        yield 'the deployment itself'     => ['/.'];
        yield 'with a trailing slash'     => ['/./'];
        yield 'the directory above it'    => ['/..'];
        yield 'the webroot, then back up' => ['/public/..'];
    }

    // ───────────────────────────── fixtures ─────────────────────────────

    /**
     * The booted app's deployment, as the tests spell a `DOCUMENT_ROOT` under it.
     *
     * @return string
     */
    private static function deployment(): string
    {
        return App::current()->above()->path;
    }

    /**
     * The webroot the booted app resolves with `DOCUMENT_ROOT` set to $root, or unset for null.
     *
     * The one place this suite writes a server variable, because {@link App::webroot()} reads the
     * process's own rather than a request's: it is asked by the push, which has a request, and by the
     * health report, which may not. The previous value goes back whatever happens.
     *
     * @param string|null $root
     * @return string
     */
    private static function webrootFor(?string $root): string
    {
        $key      = ServerVariable::DocumentRoot->value;
        $previous = $_SERVER[$key] ?? null;

        if ($root === null) {
            unset($_SERVER[$key]);
        } else {
            $_SERVER[$key] = $root;
        }

        try {
            return App::current()->webroot()->path;
        } finally {
            if ($previous === null) {
                unset($_SERVER[$key]);
            } else {
                $_SERVER[$key] = $previous;
            }
        }
    }

    /**
     * An app that does not say otherwise keeps every language at the page's one address, so a site
     * served only by PHP never meets `/x.de.html` — negotiation by cookie and browser is the default,
     * and addresses of their own are what an app exported to a static host opts into.
     *
     * @return void
     */
    public function testEveryLanguageSharesOneAddressUnlessTheAppSaysOtherwise(): void
    {
        self::assertSame(\Phpanta\Text\LanguageAddresses::Shared, self::other()->languageAddresses());
    }

    /**
     * An app that is not the booted one, for the refusals above. Constructing one is allowed — it is
     * booting it that is not.
     *
     * @return App
     */
    private static function other(): App
    {
        return new class () extends App {
            /** @return string */
            public function name(): string
            {
                return 'other';
            }

            /** @return Directory */
            public function above(): Directory
            {
                return new Directory(sys_get_temp_dir());
            }

            /** @return Collection<Route> */
            public function routes(): Collection
            {
                return new Collection(Route::class);
            }

            /** @return Collection<DataFileName> */
            protected function ownDataFiles(): Collection
            {
                return new Collection(DataFileName::class);
            }

            /**
             * @param Request $request
             * @return Response
             */
            public function notFound(Request $request): Response
            {
                return new PlainTextResponse(HttpStatusCode::NotFound, 'not here');
            }

            /** @return Languages */
            public function languages(): Languages
            {
                return new Languages(Language::English);
            }

            /** @return Shell */
            public function shell(): Shell
            {
                // Constructing an app does nothing, so borrowing the test app's shell boots nothing.
                return new TestApp();
            }

            /** @return Vocabulary */
            public function vocabulary(): Vocabulary
            {
                return Vocabulary::standard();
            }

            /** @return string */
            public function buildId(): string
            {
                return 'other';
            }
        };
    }
}
