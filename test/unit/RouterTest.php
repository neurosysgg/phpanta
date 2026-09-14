<?php

declare(strict_types=1);

namespace Phpanta\Test\Unit;

use Phpanta\Controller\UnroutedController;
use Phpanta\Http\Allow;
use Phpanta\Http\Answer;
use Phpanta\Http\Header;
use Phpanta\Http\HttpMethod;
use Phpanta\Http\HttpStatusCode;
use Phpanta\Http\PlainTextResponse;
use Phpanta\Http\Request;
use Phpanta\Http\TextBody;
use Phpanta\Router;
use Phpanta\Support\Collection;
use Phpanta\Support\MethodPolicy;
use Phpanta\Support\Route;
use Phpanta\Test\TestRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The router over a table of its own: which route answers, and the method gate in front of it.
 *
 * The table is built here, over {@link ExportFixturePath}, rather than read from the booted app,
 * whose only route is the API's. What the router does with a path no route claims is the app's
 * answer, and {@link AnswerTest} asserts it through the whole app.
 */
#[CoversClass(Router::class)]
#[CoversClass(Route::class)]
#[CoversClass(MethodPolicy::class)]
#[CoversClass(HttpMethod::class)]
#[CoversClass(Allow::class)]
#[CoversClass(UnroutedController::class)]
#[CoversClass(PlainTextResponse::class)]
#[CoversClass(Answer::class)]
#[CoversClass(TextBody::class)]
#[CoversClass(Header::class)]
#[CoversClass(Request::class)]
final class RouterTest extends TestCase
{
    // ───────────────────────── the method gate ─────────────────────────

    /**
     * The `Allow` header is derived from the gate, so the two cannot say different things.
     *
     * @return void
     */
    public function testTheAllowedMethodsAreExactlyTheReadOnlyOnes(): void
    {
        $readOnly = array_values(array_filter(
            HttpMethod::cases(),
            static fn(HttpMethod $m): bool => $m->isReadOnly(),
        ));

        self::assertSame([HttpMethod::Get, HttpMethod::Head], $readOnly);
        self::assertSame('GET, HEAD', Allow::readOnly()->render());
    }

    /**
     * A read reaches the route's controller, with what the match captured.
     *
     * @return void
     */
    public function testAReadIsDispatchedToTheRouteThatMatches(): void
    {
        self::assertSame('left|right GET', self::answer('GET', '/pairs/left/right')->body());
        self::assertSame('left|right HEAD', self::answer('HEAD', '/pairs/left/right')->body());
    }

    /**
     * The table is asked in order and the first route to match answers, so a static address
     * registered ahead of a placeholder sibling that would also match it is the one that is reached.
     *
     * @return void
     */
    public function testTheFirstRouteToMatchAnswers(): void
    {
        $router = new Router(new Collection(Route::class)->with(
            new Route(ExportFixturePath::Guide, static fn(): EchoController => new EchoController('static')),
            new Route(ExportFixturePath::Page, EchoController::factory()),
        ));

        $request = TestRequest::get('/guide')->request();

        self::assertSame('static GET', $router->dispatch($request)->answer($request)->body());
    }

    /**
     * A write to an address a read-only route claims is refused before the controller is built —
     * a write handled like a GET is how a `POST` to a download once redirected exactly as a `GET`
     * did — and a verb nobody knows is refused the same way.
     *
     * @param string $method
     * @param string $path
     * @return void
     */
    #[DataProvider('writeMethodProvider')]
    public function testAWriteMethodIsRefusedOnEveryReadOnlyRoute(string $method, string $path): void
    {
        $answer = self::answer($method, $path);

        self::assertSame(HttpStatusCode::MethodNotAllowed, $answer->status());
        self::assertSame(UnroutedController::refusal(TestRequest::get('/')->request()->language()), $answer->body());
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function writeMethodProvider(): iterable
    {
        yield 'POST to a page'          => ['POST', '/'];
        yield 'DELETE with placeholders' => ['DELETE', '/pairs/left/right'];
        yield 'PUT'                     => ['PUT', '/guide'];
        yield 'PATCH'                   => ['PATCH', '/pages/one'];
        yield 'a verb nobody knows'     => ['BREW', '/guide'];
    }

    /**
     * A 405 without an `Allow` header is a malformed 405; the one it names is the read-only set. Its
     * body is in the caller's language and a 405 is cacheable by default, so it says no cache may
     * keep it.
     *
     * @return void
     */
    public function testTheRefusalNamesTheAllowedMethods(): void
    {
        $answer = self::answer('POST', '/');

        self::assertSame(
            ['Content-Type: text/plain; charset=utf-8', 'Cache-Control: no-store, private', 'Allow: GET, HEAD'],
            self::lines($answer),
        );
    }

    /**
     * A write to an address a route claims and a write to one nothing claims are one answer, to the
     * byte. The API's unsigned refusal is the second of those — {@link AnswerTest} holds the two
     * together — so none of the three can be told from another.
     *
     * @return void
     */
    public function testAWriteIsRefusedIdenticallyWhetherOrNotARouteClaimsTheAddress(): void
    {
        $claimed   = self::answer('POST', '/guide');
        $unclaimed = self::answer('POST', '/no-such-page');

        self::assertSame(HttpStatusCode::MethodNotAllowed, $unclaimed->status());
        self::assertSame($unclaimed->status(), $claimed->status());
        self::assertSame(self::lines($unclaimed), self::lines($claimed));
        self::assertSame($unclaimed->body(), $claimed->body());
    }

    /**
     * A delegated route forms no opinion: its controller sees every method, an unknown one included,
     * so that it can refuse the way an address that is not there does.
     *
     * @return void
     */
    public function testADelegatedRouteHandsEveryMethodToItsController(): void
    {
        $router = new Router(new Collection(Route::class)->with(
            new Route(ExportFixturePath::Page, EchoController::factory(), MethodPolicy::Delegated),
        ));

        foreach (['GET' => 'one GET', 'POST' => 'one POST', 'BREW' => 'one none'] as $method => $said) {
            $request = TestRequest::to($method, '/pages/one')->request();

            self::assertSame($said, $router->dispatch($request)->answer($request)->body(), $method);
        }
    }

    /**
     * What the router answers $method for $target with, over a table of one route per shape.
     *
     * @param string $method
     * @param string $target
     * @return Answer
     */
    private static function answer(string $method, string $target): Answer
    {
        $request = TestRequest::to($method, $target)->request();
        $router  = new Router(new Collection(Route::class)->with(
            new Route(ExportFixturePath::Home, EchoController::factory()),
            new Route(ExportFixturePath::Guide, EchoController::factory()),
            new Route(ExportFixturePath::Page, EchoController::factory()),
            new Route(ExportFixturePath::Pair, EchoController::factory()),
        ));

        return $router->dispatch($request)->answer($request);
    }

    /**
     * @param Answer $answer
     * @return list<string>
     */
    private static function lines(Answer $answer): array
    {
        return $answer->headers()->map(static fn(Header $header): string => $header->line())->toValues();
    }
}
