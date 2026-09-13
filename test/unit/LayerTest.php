<?php

declare(strict_types=1);

namespace Phpanta\Test\Unit;

use ArrayObject;
use Phpanta\App;
use Phpanta\Controller\Controller;
use Phpanta\Controller\Layer;
use Phpanta\Controller\Layered;
use Phpanta\Http\Answer;
use Phpanta\Http\Header;
use Phpanta\Http\HttpMethod;
use Phpanta\Http\HttpStatusCode;
use Phpanta\Http\Location;
use Phpanta\Http\PlainTextResponse;
use Phpanta\Http\Request;
use Phpanta\Http\RequestHeader;
use Phpanta\Http\Response;
use Phpanta\Http\ResponseHeader;
use Phpanta\Http\WithHeaders;
use Phpanta\Router;
use Phpanta\Service\Layer\AdminGate;
use Phpanta\Service\Layer\Maintenance;
use Phpanta\Service\Layer\SiteGate;
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
#[CoversClass(SiteGate::class)]
#[CoversClass(AdminGate::class)]
#[CoversClass(Maintenance::class)]
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
     * The framework's site gate stands first around every request, and an app that lists nothing
     * of its own has nothing else there.
     *
     * @return void
     */
    public function testTheSiteGateStandsFirstAroundEveryRequest(): void
    {
        $layers = App::current()->layerTable();

        self::assertCount(1, $layers);
        self::assertInstanceOf(SiteGate::class, $layers->first());
    }

    /**
     * Both credential gates answer the wrong pair with the app's challenge, and let the right one
     * through to whatever is next.
     *
     * @param string $gate
     * @return void
     */
    #[DataProvider('gateProvider')]
    public function testAGateLayerRefusesTheWrongPairAndPassesTheRightOne(string $gate): void
    {
        $file  = $this->credentials('preview', 'hunter2');
        $layer = $gate === 'site' ? new SiteGate($file) : new AdminGate($file);
        $core  = Layered::around(new Collection(Layer::class)->with($layer), new EchoController('behind'));

        $wrong   = TestRequest::get('/')->withCredentials('preview', 'wrong')->request();
        $refusal = $core->handle($wrong)->answer($wrong);

        self::assertSame(HttpStatusCode::Unauthorized, $refusal->status());
        self::assertSame('Basic realm="phpanta"', $refusal->header(ResponseHeader::WwwAuthenticate)?->value->render());

        $right = TestRequest::get('/')->withCredentials('preview', 'hunter2')->request();
        self::assertSame('behind GET', $core->handle($right)->answer($right)->body());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function gateProvider(): iterable
    {
        yield 'the site gate'  => ['site'];
        yield 'the admin gate' => ['admin'];
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
     * The API is let through, because a push is how maintenance usually ends.
     *
     * @return void
     */
    public function testMaintenanceLetsTheApiThrough(): void
    {
        $switch = new File("$this->scratch/maintenance");
        $switch->write('');

        $request = TestRequest::to(HttpMethod::Post, '/api/update/v1/patch')->request();

        self::assertSame('page POST', self::maintained($switch)->handle($request)->answer($request)->body());
    }

    // ───────────────────────── helpers ─────────────────────────

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
