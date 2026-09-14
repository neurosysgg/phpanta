<?php

declare(strict_types=1);

namespace Phpanta\Test\Unit;

use ArrayObject;
use Phpanta\App;
use Phpanta\Controller\Controller;
use Phpanta\Controller\Layer;
use Phpanta\Controller\Layered;
use Phpanta\Http\Allow;
use Phpanta\Http\Answer;
use Phpanta\Http\Header;
use Phpanta\Http\HttpMethod;
use Phpanta\Http\HttpStatusCode;
use Phpanta\Http\Location;
use Phpanta\Http\Origin;
use Phpanta\Http\PlainTextResponse;
use Phpanta\Http\Request;
use Phpanta\Http\RequestHeader;
use Phpanta\Http\Response;
use Phpanta\Http\ResponseHeader;
use Phpanta\Http\WithHeaders;
use Phpanta\Router;
use Phpanta\Service\Layer\AdminGate;
use Phpanta\Service\Layer\Cors;
use Phpanta\Service\Layer\Maintenance;
use Phpanta\Service\Layer\TrailingSlash;
use Phpanta\Support\Collection;
use Phpanta\Support\File;
use Phpanta\Support\Route;
use Phpanta\Test\TestRequest;
use Phpanta\Text\FrameworkText;
use Phpanta\Text\Language;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * What stands around a controller: layers in the order they are listed, able to answer instead,
 * or after; a route's own only past its method gate; and the three the framework ships.
 */
#[CoversClass(Layered::class)]
#[CoversClass(Router::class)]
#[CoversClass(Route::class)]
#[CoversClass(App::class)]
#[CoversClass(WithHeaders::class)]
#[CoversClass(Answer::class)]
#[CoversClass(AdminGate::class)]
#[CoversClass(Maintenance::class)]
#[CoversClass(TrailingSlash::class)]
#[CoversClass(Cors::class)]
#[CoversClass(Origin::class)]
#[CoversClass(Request::class)]
final class LayerTest extends TestCase
{
    /** A directory of this test's own, emptied afterwards. */
    private string $scratch;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        $this->scratch = sys_get_temp_dir() . '/phpanta-layer-' . bin2hex(random_bytes(6));
        mkdir($this->scratch);
    }

    /**
     * @return void
     */
    protected function tearDown(): void
    {
        foreach (scandir($this->scratch) ?: [] as $name) {
            if ($name !== '.' && $name !== '..') {
                unlink("$this->scratch/$name");
            }
        }

        rmdir($this->scratch);
    }

    // ───────────────────────── the order ─────────────────────────

    /**
     * The first listed is the outermost: first to see the request, last to see the response.
     *
     * @return void
     */
    public function testLayersRunInTheOrderTheyAreListedOutermostFirst(): void
    {
        $log  = new ArrayObject();
        $core = Layered::around(
            new Collection(Layer::class)->with(self::recording('a', $log), self::recording('b', $log)),
            self::core($log),
        );

        (void) $core->handle(TestRequest::get('/')->request());

        self::assertSame(['before a', 'before b', 'core', 'after b', 'after a'], $log->getArrayCopy());
    }

    /**
     * No layers is the controller itself, untouched.
     *
     * @return void
     */
    public function testNoLayersIsTheControllerItself(): void
    {
        $core = self::core(new ArrayObject());

        self::assertSame($core, Layered::around(new Collection(Layer::class), $core));
    }

    /**
     * A layer that answers instead is the answer, and nothing further in runs.
     *
     * @return void
     */
    public function testALayerThatAnswersInsteadIsTheAnswer(): void
    {
        $log     = new ArrayObject();
        $refusal = new class () implements Layer {
            /**
             * @param Request    $request
             * @param Controller $next
             * @return Response
             */
            public function handle(Request $request, Controller $next): Response
            {
                return new PlainTextResponse(HttpStatusCode::Forbidden, "no\n");
            }
        };

        $response = Layered::around(
            new Collection(Layer::class)->with($refusal, self::recording('inner', $log)),
            self::core($log),
        )->handle(TestRequest::get('/')->request());

        self::assertSame(HttpStatusCode::Forbidden, $response->answer(TestRequest::get('/')->request())->status());
        self::assertSame([], $log->getArrayCopy());
    }

    /**
     * A layer that works after the controller keeps what it answered and adds to its headers.
     *
     * @return void
     */
    public function testALayerAfterTheControllerAddsHeadersAndKeepsTheRest(): void
    {
        $after = new class () implements Layer {
            /**
             * @param Request    $request
             * @param Controller $next
             * @return Response
             */
            public function handle(Request $request, Controller $next): Response
            {
                return new WithHeaders(
                    $next->handle($request),
                    new Collection(Header::class)->with(new Header(ResponseHeader::Location, new Location('/after'))),
                );
            }
        };

        $request = TestRequest::get('/')->request();
        $answer  = Layered::around(new Collection(Layer::class)->with($after), new EchoController('said'))
            ->handle($request)
            ->answer($request);

        self::assertSame(HttpStatusCode::Ok, $answer->status());
        self::assertSame("said GET", $answer->body());
        self::assertSame(
            ['Content-Type: text/plain; charset=utf-8', 'Location: /after'],
            $answer->headers()->map(static fn(Header $header): string => $header->line())->toValues(),
        );
    }

    // ───────────────────────── a route's own ─────────────────────────

    /**
     * A route's layers see the requests that route answers, and none its method gate refuses.
     *
     * @return void
     */
    public function testARoutesLayersRunOnlyPastItsMethodGate(): void
    {
        $log    = new ArrayObject();
        $router = new Router(new Collection(Route::class)->with(
            new Route(ExportFixturePath::Home, EchoController::factory())->through(self::recording('route', $log)),
            new Route(ExportFixturePath::Guide, EchoController::factory()),
        ));

        $post    = TestRequest::to(HttpMethod::Post, '/')->request();
        $refused = $router->handle($post)->answer($post);
        self::assertSame(HttpStatusCode::MethodNotAllowed, $refused->status());
        self::assertSame([], $log->getArrayCopy(), 'a refused write reached the route\'s layer');

        (void) $router->handle(TestRequest::get('/guide')->request());
        self::assertSame([], $log->getArrayCopy(), 'another route\'s request reached the layer');

        (void) $router->handle(TestRequest::get('/')->request());
        self::assertSame(['before route', 'after route'], $log->getArrayCopy());
    }

    /**
     * `through()` copies: the route it was asked of keeps the layers it had.
     *
     * @return void
     */
    public function testThroughCopiesTheRoute(): void
    {
        $route  = new Route(ExportFixturePath::Home, EchoController::factory());
        $guarded = $route->through(new AdminGate(), new AdminGate());

        self::assertTrue($route->layers()->isEmpty());
        self::assertCount(2, $guarded->layers());
        self::assertSame(ExportFixturePath::Home, $guarded->path());
        self::assertCount(3, $guarded->through(new AdminGate())->layers(), 'through() keeps what was there');
    }

    // ───────────────────────── the app's ─────────────────────────

    /**
     * The framework stands nothing of its own around every request: an app that lists no layers
     * has nothing between the request and the router.
     *
     * @return void
     */
    public function testAnAppThatListsNoLayersHasNothingAroundTheRouter(): void
    {
        self::assertTrue(App::current()->layerTable()->isEmpty());
    }

    /**
     * The admin gate answers the wrong pair with the app's challenge, and lets the right one
     * through to whatever is next.
     *
     * @return void
     */
    public function testTheAdminGateRefusesTheWrongPairAndPassesTheRightOne(): void
    {
        $file = $this->credentials('preview', 'hunter2');
        $core = Layered::around(new Collection(Layer::class)->with(new AdminGate($file)), new EchoController('behind'));

        $wrong   = TestRequest::get('/')->withCredentials('preview', 'wrong')->request();
        $refusal = $core->handle($wrong)->answer($wrong);

        self::assertSame(HttpStatusCode::Unauthorized, $refusal->status());
        self::assertSame('Basic realm="phpanta"', $refusal->header(ResponseHeader::WwwAuthenticate)?->value->render());

        $right = TestRequest::get('/')->withCredentials('preview', 'hunter2')->request();
        self::assertSame('behind GET', $core->handle($right)->answer($right)->body());
    }

    // ───────────────────────── maintenance ─────────────────────────

    /**
     * No switch file, no maintenance.
     *
     * @return void
     */
    public function testMaintenanceStandsAsideWithoutItsSwitch(): void
    {
        $request = TestRequest::get('/')->request();
        $core    = self::maintained(new File("$this->scratch/maintenance"));

        self::assertSame('page GET', $core->handle($request)->answer($request)->body());
    }

    /**
     * With the switch, every page is a 503 in the request's language that no cache keeps.
     *
     * @return void
     */
    public function testMaintenanceAnswersEveryPageWithA503(): void
    {
        $switch = new File("$this->scratch/maintenance");
        $switch->write('');

        $request = TestRequest::get('/releases')->with(RequestHeader::AcceptLanguage, 'de')->request();
        $answer  = self::maintained($switch)->handle($request)->answer($request);

        self::assertSame(HttpStatusCode::ServiceUnavailable, $answer->status());
        self::assertSame(FrameworkText::Maintenance->in(Language::German) . "\n", $answer->body());
        self::assertSame('no-store, private', $answer->header(ResponseHeader::CacheControl)?->value->render());
    }

    /**
     * The admin is let through, at every depth, because a push is how maintenance usually ends.
     *
     * @return void
     */
    public function testMaintenanceLetsTheAdminThrough(): void
    {
        $switch = new File("$this->scratch/maintenance");
        $switch->write('');

        foreach (['/admin', '/admin/update', '/admin/update/v1', '/admin/update/v1/patch'] as $path) {
            $request = TestRequest::to(HttpMethod::Post, $path)->request();

            self::assertSame('page POST', self::maintained($switch)->handle($request)->answer($request)->body(), $path);
        }
    }

    // ───────────────────────── one address per page ─────────────────────────

    /**
     * A read with a trailing slash is sent to the address without it, the query kept; anything else
     * is answered where it was sent.
     *
     * @return void
     */
    public function testATrailingSlashIsSentToTheAddressWithoutIt(): void
    {
        $core = Layered::around(new Collection(Layer::class)->with(new TrailingSlash()), new EchoController('page'));

        $slashed = TestRequest::get('/releases/?a=1')->request();
        $answer  = $core->handle($slashed)->answer($slashed);

        self::assertSame(HttpStatusCode::PermanentRedirect, $answer->status());
        self::assertSame('/releases?a=1', $answer->header(ResponseHeader::Location)?->value->render());

        foreach (
            [
                'no slash'     => TestRequest::get('/releases')->request(),
                'a write'      => TestRequest::to(HttpMethod::Post, '/releases/')->request(),
                'another host' => TestRequest::get('/\\evil.example/')->request(),
            ] as $case => $request
        ) {
            self::assertSame(HttpStatusCode::Ok, $core->handle($request)->answer($request)->status(), $case);
        }
    }

    // ───────────────────────── other origins ─────────────────────────

    /**
     * A listed origin may read the answer, and is named back; anyone else is answered as they would
     * be, with nothing added but the `Vary`.
     *
     * @param string      $origin
     * @param string|null $allowed
     * @return void
     */
    #[DataProvider('originProvider')]
    public function testOnlyAListedOriginIsNamedBack(string $origin, ?string $allowed): void
    {
        $request = TestRequest::get('/')->with(RequestHeader::Origin, $origin)->request();
        $answer  = self::cors()->handle($request)->answer($request);

        self::assertSame('page GET', $answer->body());
        self::assertSame($allowed, $answer->header(ResponseHeader::AccessControlAllowOrigin)?->value->render());
        self::assertSame('Origin', $answer->header(ResponseHeader::Vary)?->value->render());
    }

    /**
     * @return iterable<string, array{string, string|null}>
     */
    public static function originProvider(): iterable
    {
        yield 'a listed origin'  => ['https://app.example.org', 'https://app.example.org'];
        yield 'another origin'   => ['https://other.example.org', null];
        yield 'no origin'        => ['', null];
    }

    /**
     * A preflight from a listed origin is answered by the layer — the methods it allows, no body — and
     * never reaches a route; from anyone else it goes on to be answered as any `OPTIONS` would.
     *
     * @return void
     */
    public function testAPreflightFromAListedOriginIsAnsweredByThePolicy(): void
    {
        $preflight = static fn(string $origin): Request => TestRequest::to(HttpMethod::Options, '/')
            ->with(RequestHeader::Origin, $origin)
            ->with(RequestHeader::AccessControlRequestMethod, 'GET')
            ->request();

        $listed = $preflight('https://app.example.org');
        $answer = self::cors()->handle($listed)->answer($listed);

        self::assertSame(HttpStatusCode::NoContent, $answer->status());
        self::assertSame('', $answer->body());
        self::assertSame(
            'https://app.example.org',
            $answer->header(ResponseHeader::AccessControlAllowOrigin)?->value->render(),
        );
        self::assertSame('GET, HEAD', $answer->header(ResponseHeader::AccessControlAllowMethods)?->value->render());

        $other = $preflight('https://other.example.org');
        self::assertSame('page OPTIONS', self::cors()->handle($other)->answer($other)->body());
    }

    // ───────────────────────── helpers ─────────────────────────

    /**
     * A page behind a CORS policy listing one origin.
     *
     * @return Controller
     */
    private static function cors(): Controller
    {
        return Layered::around(
            new Collection(Layer::class)->with(new Cors(Allow::readOnly(), Origin::of('https://app.example.org'))),
            new EchoController('page'),
        );
    }

    /**
     * A layer that writes to $log on the way in and on the way out.
     *
     * @param string             $name
     * @param ArrayObject<int, string> $log
     * @return Layer
     */
    private static function recording(string $name, ArrayObject $log): Layer
    {
        return new class ($name, $log) implements Layer {
            /**
             * @param string                   $name
             * @param ArrayObject<int, string> $log
             */
            public function __construct(private string $name, private ArrayObject $log) {}

            /**
             * @param Request    $request
             * @param Controller $next
             * @return Response
             */
            public function handle(Request $request, Controller $next): Response
            {
                $this->log[] = "before $this->name";
                $response    = $next->handle($request);
                $this->log[] = "after $this->name";

                return $response;
            }
        };
    }

    /**
     * The controller at the middle, which writes to $log when it runs.
     *
     * @param ArrayObject<int, string> $log
     * @return Controller
     */
    private static function core(ArrayObject $log): Controller
    {
        return new class ($log) implements Controller {
            /**
             * @param ArrayObject<int, string> $log
             */
            public function __construct(private ArrayObject $log) {}

            /**
             * @param Request $request
             * @return Response
             */
            public function handle(Request $request): Response
            {
                $this->log[] = 'core';

                return new PlainTextResponse(HttpStatusCode::Ok, "core\n");
            }
        };
    }

    /**
     * A page behind maintenance switched by $switch.
     *
     * @param File $switch
     * @return Controller
     */
    private static function maintained(File $switch): Controller
    {
        return Layered::around(
            new Collection(Layer::class)->with(new Maintenance($switch)),
            new EchoController('page'),
        );
    }

    /**
     * A credentials file for $user and $password, in the scratch directory.
     *
     * @param string $user
     * @param string $password
     * @return File
     */
    private function credentials(string $user, string $password): File
    {
        $file = new File("$this->scratch/credentials.php");
        $file->write('<?php return ' . var_export([
            'user'      => $user,
            'pass_hash' => password_hash($password, PASSWORD_BCRYPT, ['cost' => 4]),
        ], true) . ';');

        return $file;
    }
}
