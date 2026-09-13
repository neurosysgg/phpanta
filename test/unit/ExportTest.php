<?php

declare(strict_types=1);

namespace Phpanta\Test\Unit;

use Phpanta\App;
use Phpanta\Controller\Controller;
use Phpanta\DataFileName;
use Phpanta\Http\BasicChallenge;
use Phpanta\Http\HttpStatusCode;
use Phpanta\Http\Request;
use Phpanta\Http\Response;
use Phpanta\Http\ViewResponse;
use Phpanta\Service\Auth;
use Phpanta\Support\Collection;
use Phpanta\Support\Directory;
use Phpanta\Support\Route;
use Phpanta\Test\TestApp;
use Phpanta\Text\Language;
use Phpanta\Text\LanguageAddresses;
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
     * An app whose languages have addresses of their own is written once more at each: the plain
     * file is the default language's, byte for byte, every other file names its language, and a link
     * written once leads to the file in the language of the page it is on — which the export's own
     * check resolves, or it would fail.
     *
     * @return void
     */
    public function testEachLanguageIsWrittenAtItsOwnAddress(): void
    {
        $link  = new Element(HtmlTag::A)
            ->attr(HtmlAttribute::Href, ExportFixturePath::Guide->inEachLanguage())
            ->containing('guide');
        $home  = new Route(
            ExportFixturePath::Home,
            static fn(): Controller => self::controller(self::view('Home', $link)),
        );
        $guide = new Route(ExportFixturePath::Guide, static fn(): Controller => self::controller(self::view('Guide')));

        [$code, $error] = $this->export(
            [$home, $guide],
            languages: new Languages(Language::English, Language::German),
            addresses: LanguageAddresses::Suffixed,
        );

        self::assertSame(ExitCode::Success, $code, $error);

        foreach (['index', 'index.en', 'index.de', 'guide', 'guide.en', 'guide.de', '404'] as $name) {
            self::assertFileExists("$this->scratch/out/$name.html");
        }

        self::assertFileEquals("$this->scratch/out/index.en.html", "$this->scratch/out/index.html");

        $german = (string) file_get_contents("$this->scratch/out/index.de.html");

        self::assertStringContainsString('<html lang="de">', $german);
        self::assertStringContainsString('href="/guide.de.html"', $german);
        self::assertStringContainsString(
            'href="/guide.en.html"',
            (string) file_get_contents("$this->scratch/out/index.html"),
        );
    }

    /**
     * A link to an anchor lands on one: on the same page, on another page, and through a fragment
     * written encoded that names an id written decoded, which is the order a browser looks in. A
     * bare `#` names no anchor at all.
     *
     * @return void
     */
    public function testALinkToAnAnchorThatIsThereResolves(): void
    {
        [$code, $error] = $this->export([
            self::holding(
                ExportFixturePath::Home,
                self::anchor('top'),
                self::link('#top'),
                self::link('#'),
                self::link('/guide#caf%C3%A9'),
            ),
            self::holding(ExportFixturePath::Guide, self::anchor('café')),
        ]);

        self::assertSame(ExitCode::Success, $code, $error);
    }

    /**
     * The page is there and the id is not, so a browser would show its top without a word.
     *
     * @return void
     */
    public function testALinkToAnAnchorItsOwnPageLacksFailsTheExport(): void
    {
        [$code, $error] = $this->export([
            self::holding(ExportFixturePath::Home, self::anchor('top'), self::link('#nope')),
        ]);

        self::assertSame(ExitCode::Failure, $code);
        self::assertStringContainsString('index.html links to #nope, and that page has no id "nope"', $error);
    }

    /**
     * The same, across pages: the file resolves, which is all the address check asks.
     *
     * @return void
     */
    public function testALinkToAnAnchorAnotherPageLacksFailsTheExport(): void
    {
        [$code, $error] = $this->export([
            self::holding(ExportFixturePath::Home, self::link('/guide#nope')),
            self::holding(ExportFixturePath::Guide, self::anchor('there')),
        ]);

        self::assertSame(ExitCode::Failure, $code);
        self::assertStringContainsString('index.html links to /guide#nope, and that page has no id "nope"', $error);
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
     * A route behind a password answers the export's anonymous request with its 401, and a 401 is
     * not a page — so the export names the path and fails, rather than writing the refusal to a
     * file a static host would serve to everyone as the page.
     *
     * @return void
     */
    public function testARouteBehindAPasswordFailsTheExportByName(): void
    {
        [$code, $error] = $this->export([self::gated()]);

        self::assertSame(ExitCode::Failure, $code);
        self::assertStringContainsString('/ is exported, and answers with a 401 (PlainTextResponse)', $error);
    }

    /**
     * `/`, behind a password nobody has.
     *
     * @return Route
     */
    private static function gated(): Route
    {
        return new Route(
            ExportFixturePath::Home,
            static fn(): Controller => new class () implements Controller {
                /**
                 * @param Request $request
                 * @return Response
                 */
                public function handle(Request $request): Response
                {
                    return Auth::challenge(new BasicChallenge('gated'));
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
     * @param Languages|null    $languages The app's, English alone where none are given.
     * @param LanguageAddresses $addresses
     * @return array{ExitCode, string} The status, and what it wrote to standard error.
     */
    private function export(
        array $routes,
        ?array $arguments = null,
        ?string $script = null,
        ?Languages $languages = null,
        LanguageAddresses $addresses = LanguageAddresses::Shared,
    ): array {
        $error = fopen('php://memory', 'r+');

        $code = Runner::execute(
            new Export(self::appAt($this->scratch, $routes, $languages, $addresses)),
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
     * @param string            $root
     * @param list<Route>       $routes
     * @param Languages|null    $languages English alone, where none are given.
     * @param LanguageAddresses $addresses
     * @return App
     */
    public static function appAt(
        string $root,
        array $routes,
        ?Languages $languages = null,
        LanguageAddresses $addresses = LanguageAddresses::Shared,
    ): App {
        $app = new class () extends App {
            /** Where the deployment is. */
            public string $root = '';

            /** @var list<Route> */
            public array $table = [];

            /** The languages it offers, or null for English alone. */
            public ?Languages $offered = null;

            /** Whether each language has an address of its own. */
            public LanguageAddresses $addresses = LanguageAddresses::Shared;

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
                return $this->offered ?? new Languages(Language::English);
            }

            /** @return LanguageAddresses */
            public function languageAddresses(): LanguageAddresses
            {
                return $this->addresses;
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

        $app->root      = $root;
        $app->table     = $routes;
        $app->offered   = $languages;
        $app->addresses = $addresses;

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
     * A page at $path whose content is $nodes.
     *
     * @param ExportFixturePath $path
     * @param Node              ...$nodes
     * @return Route
     */
    private static function holding(ExportFixturePath $path, Node ...$nodes): Route
    {
        $content = new Element(HtmlTag::Section)->containing(...$nodes);

        return new Route($path, static fn(): Controller => self::controller(self::view('page', $content)));
    }

    /**
     * An element a fragment can name.
     *
     * @param string $id
     * @return Element
     */
    private static function anchor(string $id): Element
    {
        return new Element(HtmlTag::P)->attr(HtmlAttribute::Id, $id)->containing($id);
    }

    /**
     * A link to $href.
     *
     * @param string $href
     * @return Element
     */
    private static function link(string $href): Element
    {
        return new Element(HtmlTag::A)->attr(HtmlAttribute::Href, $href)->containing($href);
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
