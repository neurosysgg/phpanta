<?php

declare(strict_types=1);

namespace Phpanta\Test\Unit;

use Phpanta\App;
use Phpanta\Controller\Controller;
use Phpanta\DataFileName;
use Phpanta\Http\HttpStatusCode;
use Phpanta\Http\Request;
use Phpanta\Http\Response;
use Phpanta\Http\ViewResponse;
use Phpanta\Support\Collection;
use Phpanta\Support\Directory;
use Phpanta\Support\Route;
use Phpanta\Test\TestApp;
use Phpanta\Text\Language;
use Phpanta\Text\Languages;
use Phpanta\Text\Translatable;
use Phpanta\Text\Verbatim;
use Phpanta\Tool\Cli\ExitCode;
use Phpanta\Tool\Cli\Output;
use Phpanta\Tool\Cli\Runner;
use Phpanta\Tool\Command\Export;
use Phpanta\View\Html\Element;
use Phpanta\View\Html\HtmlAttribute;
use Phpanta\View\Html\HtmlTag;
use Phpanta\View\Html\Node;
use Phpanta\View\Html\Vocabulary;
use Phpanta\View\Shell;
use Phpanta\View\View;
use PHPUnit\Framework\TestCase;

/**
 * The static export as a command: what it writes, under which names, and what it refuses to touch.
 *
 * Run against an app of its own in a scratch deployment, because TestApp answers an address it does
 * not have with plain text, and an export refuses to write that as 404.html. No `#[CoversClass]`,
 * like every other test of the tooling.
 */
final class ExportTest extends TestCase
{
    /** The scratch deployment: a `public/` to export, and `out/` beside it to export into. */
    private string $scratch;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        $this->scratch = sys_get_temp_dir() . '/phpanta-export-' . bin2hex(random_bytes(6));
        mkdir($this->scratch . '/public', 0o777, true);
    }

    /**
     * @return void
     */
    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->scratch));
    }

    /**
     * A static host decodes the address it is asked for before it looks for a file, so a page is
     * written under its path decoded — and a link to it, which is written encoded, still resolves.
     *
     * @return void
     */
    public function testAPageIsWrittenUnderItsDecodedNameAndItsLinkResolves(): void
    {
        [$code, $error] = $this->export([self::home(), self::pages('café')]);

        self::assertSame(ExitCode::Success, $code, $error);
        self::assertFileExists("$this->scratch/out/pages/café.html");
        self::assertFileDoesNotExist("$this->scratch/out/pages/caf%C3%A9.html");
        self::assertStringContainsString(
            'href="/pages/caf%C3%A9"',
            (string) file_get_contents("$this->scratch/out/index.html"),
        );
        self::assertFileExists("$this->scratch/out/404.html");
        self::assertFileExists("$this->scratch/out/.phpanta-export");
    }

    /**
     * `a%2Fb` is one segment to the site and two directories to a file system, so the page would
     * land somewhere its address does not lead.
     *
     * @return void
     */
    public function testASegmentThatDecodesToASlashIsRefused(): void
    {
        [$code, $error] = $this->export([self::pages('a/b')]);

        self::assertSame(ExitCode::Failure, $code);
        self::assertStringContainsString('/pages/a%2Fb has a segment that decodes', $error);
    }

    /**
     * A routed `/404` and the app's not-found page both want 404.html, and whichever was written
     * second would silently win.
     *
     * @return void
     */
    public function testARoutedPageAt404IsRefused(): void
    {
        [$code, $error] = $this->export([self::route(ExportFixturePath::Missing)]);

        self::assertSame(ExitCode::Failure, $code);
        self::assertStringContainsString('/404 would be written to 404.html', $error);
    }

    /**
     * An export's own output carries the marker, so the next one empties it — a page that is gone
     * from the site is gone from the export too.
     *
     * @return void
     */
    public function testAnEarlierExportIsEmptiedAndWrittenAgain(): void
    {
        [$code, $error] = $this->export([self::route(ExportFixturePath::Home)]);

        self::assertSame(ExitCode::Success, $code, $error);

        file_put_contents("$this->scratch/out/stale.html", 'left over');

        [$code, $error] = $this->export([self::route(ExportFixturePath::Home)]);

        self::assertSame(ExitCode::Success, $code, $error);
        self::assertFileDoesNotExist("$this->scratch/out/stale.html");
        self::assertFileExists("$this->scratch/out/index.html");
    }

    /**
     * Any GitHub Pages directory may carry `.nojekyll`, including one somebody made by hand; only
     * the marker says the export wrote it.
     *
     * @return void
     */
    public function testADirectoryWithoutTheMarkerIsLeftAlone(): void
    {
        mkdir("$this->scratch/out");
        touch("$this->scratch/out/.nojekyll");
        file_put_contents("$this->scratch/out/mine.html", 'mine');

        [$code, $error] = $this->export([self::home()]);

        self::assertSame(ExitCode::Failure, $code);
        self::assertStringContainsString('has no .phpanta-export', $error);
        self::assertFileExists("$this->scratch/out/mine.html");
    }

    /**
     * A repository with the marker copied into it is still a repository.
     *
     * @return void
     */
    public function testARepositoryIsRefusedEvenWithTheMarker(): void
    {
        mkdir("$this->scratch/out/.git", 0o777, true);
        touch("$this->scratch/out/.phpanta-export");

        [$code, $error] = $this->export([self::home()]);

        self::assertSame(ExitCode::Failure, $code);
        self::assertStringContainsString('holds .git', $error);
        self::assertDirectoryExists("$this->scratch/out/.git");
    }

    /**
     * The export has no copy in a site's `tools/`, so its usage line has to name the script that was
     * actually run rather than the one a site's commands are run as.
     *
     * @return void
     */
    public function testAMalformedCommandLineNamesTheScriptThatWasRun(): void
    {
        [$code, $error] = $this->export([], ['--nope'], 'phpanta/tools/export.php');

        self::assertSame(ExitCode::Usage, $code);
        self::assertStringContainsString('usage: php phpanta/tools/export.php --out <dir>', $error);
    }

    /**
     * A controller behind a password ends the process under the CLI, and would end the export with
     * it — half written, and with status 0. The export names the path and fails instead. Run in a
     * process of its own, since the `exit` is the thing being tested.
     *
     * @return void
     */
    public function testAControllerThatEndsTheProcessFailsTheExportByName(): void
    {
        exec(
            sprintf(
                '%s %s %s %s 2>&1',
                escapeshellarg(PHP_BINARY),
                escapeshellarg(PHPANTA_ROOT . '/test/fixture/export-exits.php'),
                escapeshellarg($this->scratch),
                escapeshellarg("$this->scratch/out"),
            ),
            $lines,
            $status,
        );

        self::assertSame(ExitCode::Failure->value, $status, implode("\n", $lines));
        self::assertStringContainsString('the controller for / ended the process', implode("\n", $lines));
    }

    /**
     * `/`, answered by a controller that ends the process — for `fixture/export-exits.php`.
     *
     * @return Route
     */
    public static function exiting(): Route
    {
        return new Route(
            ExportFixturePath::Home,
            static fn(): Controller => new class () implements Controller {
                /**
                 * @param Request $request
                 * @return never
                 */
                public function handle(Request $request): never
                {
                    exit;
                }
            },
        );
    }

    /**
     * A page with $title and $content, for a route or the not-found answer to render.
     *
     * @param string    $title
     * @param Node|null $content
     * @return View
     */
    public static function view(string $title, ?Node $content = null): View
    {
        return new class ($title, $content ?? new Element(HtmlTag::P)->containing($title)) extends View {
            /**
             * @param string $title
             * @param Node   $body
             */
            public function __construct(private string $title, private Node $body) {}

            /**
             * @return Translatable
             */
            public function pageTitle(): Translatable
            {
                return new Verbatim($this->title);
            }

            /**
             * @return Node
             */
            public function content(): Node
            {
                return $this->body;
            }
        };
    }

    /**
     * Runs the export the way `phpanta/tools/export.php` does.
     *
     * @param list<Route>       $routes
     * @param list<string>|null $arguments The command line; by default the debug tree into `out/`.
     * @param string|null       $script
     * @return array{ExitCode, string} The status, and what it wrote to standard error.
     */
    private function export(array $routes, ?array $arguments = null, ?string $script = null): array
    {
        $error = fopen('php://memory', 'r+');

        $code = Runner::execute(
            new Export(self::appAt($this->scratch, $routes)),
            $arguments ?? ['--out', "$this->scratch/out", '--debug'],
            new Output(fopen('php://memory', 'r+'), $error),
            $script,
        );

        rewind($error);

        return [$code, (string) stream_get_contents($error)];
    }

    /**
     * An app deployed at $root whose routes are $routes, with a not-found page an export can write.
     *
     * @param string      $root
     * @param list<Route> $routes
     * @return App
     */
    public static function appAt(string $root, array $routes): App
    {
        $app = new class () extends App {
            /** Where the deployment is. */
            public string $root = '';

            /** @var list<Route> */
            public array $table = [];

            /** @return string */
            public function name(): string
            {
                return 'export-fixture';
            }

            /** @return Directory */
            public function above(): Directory
            {
                return new Directory($this->root);
            }

            /** @return Collection<Route> */
            public function routes(): Collection
            {
                return new Collection(Route::class)->with(...$this->table);
            }

            /**
             * @param Request $request
             * @return Response
             */
            public function notFound(Request $request): Response
            {
                return new ViewResponse(ExportTest::view('Not found'), HttpStatusCode::NotFound);
            }

            /** @return Languages */
            public function languages(): Languages
            {
                return new Languages(Language::English);
            }

            /** @return Shell */
            public function shell(): Shell
            {
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
                return 'export';
            }

            /** @return Collection<DataFileName> */
            protected function ownDataFiles(): Collection
            {
                return new Collection(DataFileName::class);
            }
        };

        $app->root  = $root;
        $app->table = $routes;

        return $app;
    }

    /**
     * `/`, linking to the page `/pages/café` — written, as every link is, encoded.
     *
     * @return Route
     */
    private static function home(): Route
    {
        $link = new Element(HtmlTag::A)
            ->attr(HtmlAttribute::Href, ExportFixturePath::Page->to('café'))
            ->containing('café');

        return new Route(
            ExportFixturePath::Home,
            static fn(): Controller => self::controller(self::view('Home', $link)),
        );
    }

    /**
     * `/pages/{slug}`, exporting one page per slug.
     *
     * @param string ...$slugs
     * @return Route
     */
    private static function pages(string ...$slugs): Route
    {
        return new Route(
            ExportFixturePath::Page,
            static fn(string $slug): Controller => self::controller(self::view($slug)),
            exports: static fn(): array => $slugs,
        );
    }

    /**
     * A page at $path.
     *
     * @param ExportFixturePath $path
     * @return Route
     */
    private static function route(ExportFixturePath $path): Route
    {
        return new Route($path, static fn(): Controller => self::controller(self::view('page')));
    }

    /**
     * A controller that answers every request with $view.
     *
     * @param View $view
     * @return Controller
     */
    private static function controller(View $view): Controller
    {
        return new class ($view) implements Controller {
            /**
             * @param View $view
             */
            public function __construct(private View $view) {}

            /**
             * @param Request $request
             * @return Response
             */
            public function handle(Request $request): Response
            {
                return new ViewResponse($this->view);
            }
        };
    }
}
