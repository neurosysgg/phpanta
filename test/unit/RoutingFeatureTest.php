<?php

declare(strict_types=1);

namespace Phpanta\Test\Unit;

use Phpanta\Exception\RouteException;
use Phpanta\Exception\SecurityPolicyException;
use Phpanta\Http\Allow;
use Phpanta\Http\Answer;
use Phpanta\Http\EmptyResponse;
use Phpanta\Http\Header;
use Phpanta\Http\HttpMethod;
use Phpanta\Http\HttpStatusCode;
use Phpanta\Http\Origin;
use Phpanta\Http\Request;
use Phpanta\Http\RequestHeader;
use Phpanta\Http\ResponseHeader;
use Phpanta\Router;
use Phpanta\Service\Layer\AdminGate;
use Phpanta\Support\Collection;
use Phpanta\Support\FillsPlaceholders;
use Phpanta\Support\MethodPolicy;
use Phpanta\Support\MethodSet;
use Phpanta\Support\PlaceholderType;
use Phpanta\Support\Route;
use Phpanta\Support\RouteGroup;
use Phpanta\Test\TestRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversTrait;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * What a route can say about itself beyond its address: the type of each placeholder, the methods it
 * answers on, the group it stands in — and what a request says back: its trailing slash, its origin.
 */
#[CoversClass(Route::class)]
#[CoversClass(PlaceholderType::class)]
#[CoversClass(MethodSet::class)]
#[CoversClass(MethodPolicy::class)]
#[CoversClass(RouteGroup::class)]
#[CoversClass(Router::class)]
#[CoversClass(EmptyResponse::class)]
#[CoversClass(Allow::class)]
#[CoversClass(Request::class)]
#[CoversClass(Origin::class)]
#[CoversTrait(FillsPlaceholders::class)]
final class RoutingFeatureTest extends TestCase
{
    // ───────────────────────── typed placeholders ─────────────────────────

    /**
     * A typed placeholder matches only its kind, and hands over what its type decodes — an int for
     * `{id:int}`.
     *
     * @param RoutePatternFixture   $pattern
     * @param string                $path
     * @param list<string|int>|false $expected
     * @return void
     */
    #[DataProvider('typedProvider')]
    public function testATypedPlaceholderMatchesOnlyItsKind(
        RoutePatternFixture $pattern,
        string $path,
        array|false $expected,
    ): void {
        self::assertSame($expected, self::route($pattern)->matches($path));
    }

    /**
     * @return iterable<string, array{RoutePatternFixture, string, list<string|int>|false}>
     */
    public static function typedProvider(): iterable
    {
        yield 'an int'                  => [RoutePatternFixture::Numbered, '/items/42', [42]];
        yield 'zero'                    => [RoutePatternFixture::Numbered, '/items/0', [0]];
        $largest = str_repeat('9', 18);

        yield 'eighteen digits'         => [RoutePatternFixture::Numbered, "/items/$largest", [(int) $largest]];
        yield 'nineteen digits'         => [RoutePatternFixture::Numbered, '/items/' . str_repeat('9', 19), false];
        yield 'a leading zero'          => [RoutePatternFixture::Numbered, '/items/007', false];
        yield 'a sign'                  => [RoutePatternFixture::Numbered, '/items/-1', false];
        yield 'not a number'            => [RoutePatternFixture::Numbered, '/items/abc', false];
        yield 'a slug and an int'       => [RoutePatternFixture::Tagged, '/tags/drum-and-bass/2', ['drum-and-bass', 2]];
        yield 'a capital'               => [RoutePatternFixture::Tagged, '/tags/Drum/2', false];
        yield 'a doubled hyphen'        => [RoutePatternFixture::Tagged, '/tags/a--b/2', false];
        yield 'an encoded space'        => [RoutePatternFixture::Tagged, '/tags/a%20b/2', false];
    }

    /**
     * `to()` asks the same question on the way out, so a link the route would not match is refused
     * where it is written.
     *
     * @return void
     */
    public function testToFillsATypedPlaceholderWithWhatItTakes(): void
    {
        self::assertSame('/items/7', RoutePatternFixture::Numbered->to(7));
        self::assertSame('/items/7', RoutePatternFixture::Numbered->to('7'));
        self::assertSame('/tags/dnb/3', RoutePatternFixture::Tagged->to('dnb', 3));
    }

    /**
     * @param RoutePatternFixture $pattern
     * @param list<string|int>    $values
     * @return void
     */
    #[DataProvider('refusedProvider')]
    public function testToRefusesAValueItsPlaceholderDoesNotTake(RoutePatternFixture $pattern, array $values): void
    {
        $this->expectException(RouteException::class);

        (void) $pattern->to(...$values);
    }

    /**
     * @return iterable<string, array{RoutePatternFixture, list<string|int>}>
     */
    public static function refusedProvider(): iterable
    {
        yield 'letters for an int'   => [RoutePatternFixture::Numbered, ['abc']];
        yield 'a negative int'       => [RoutePatternFixture::Numbered, [-1]];
        yield 'a capital in a slug'  => [RoutePatternFixture::Tagged, ['Nope', 1]];
    }

    /**
     * A match is the inverse of `to()` for a typed value too: the int that went in comes back an int.
     *
     * @return void
     */
    public function testAMatchIsTheInverseOfToForTypedValues(): void
    {
        $route = self::route(RoutePatternFixture::Numbered);

        foreach ([0, 42, (int) str_repeat('9', 18)] as $id) {
            self::assertSame([$id], $route->matches(RoutePatternFixture::Numbered->to($id)));
        }
    }

    /**
     * A type that does not exist is refused where the route is built, not matched as a segment.
     *
     * @return void
     */
    public function testAnUnknownPlaceholderTypeIsRefusedWhereTheRouteIsBuilt(): void
    {
        $this->expectException(RouteException::class);
        $this->expectExceptionMessage("'float' is not a placeholder type");

        (void) self::route(RoutePatternFixture::Floating);
    }

    /**
     * And where a link to it is written.
     *
     * @return void
     */
    public function testAnUnknownPlaceholderTypeIsRefusedWhereALinkIsWritten(): void
    {
        $this->expectException(RouteException::class);

        (void) RoutePatternFixture::Floating->to('1.5');
    }

    // ───────────────────────── method sets ─────────────────────────

    /**
     * A set answers on its methods, `GET` brings `HEAD`, and its `Allow` reads in the enum's order.
     *
     * @return void
     */
    public function testAMethodSetAnswersOnItsMethodsAndGetBringsHead(): void
    {
        $set = MethodSet::of(HttpMethod::Post, HttpMethod::Get);

        foreach ([HttpMethod::Get, HttpMethod::Head, HttpMethod::Post] as $method) {
            self::assertTrue($set->accepts($method), $method->value);
        }

        self::assertFalse($set->accepts(HttpMethod::Put));
        self::assertFalse($set->accepts(null));
        self::assertSame('GET, HEAD, POST', $set->allow()->render());
        self::assertSame('POST', MethodSet::of(HttpMethod::Post)->allow()->render());
    }

    /**
     * @return void
     */
    public function testAnEmptyMethodSetIsRefused(): void
    {
        $this->expectException(RouteException::class);

        (void) MethodSet::of();
    }

    /**
     * A route that also writes takes its write, and names its own set when it refuses one.
     *
     * @return void
     */
    public function testAMethodSetRouteNamesItsOwnSetInItsRefusal(): void
    {
        self::assertSame('home POST', self::answer(HttpMethod::Post, '/form')->body());

        $refused = self::answer(HttpMethod::Delete, '/form');

        self::assertSame(HttpStatusCode::MethodNotAllowed, $refused->status());
        self::assertSame('GET, HEAD, POST', $refused->header(ResponseHeader::Allow)?->value->render());
        $page = self::answer(HttpMethod::Delete, '/');

        self::assertSame('GET, HEAD', $page->header(ResponseHeader::Allow)?->value->render());
    }

    /**
     * An `OPTIONS` to a route that does not take one itself is a 204 naming the route's methods and
     * `OPTIONS`, with no body and nothing describing one.
     *
     * @return void
     */
    public function testAnOptionsIsAnsweredWithTheRoutesMethods(): void
    {
        $page = self::answer(HttpMethod::Options, '/');
        $form = self::answer(HttpMethod::Options, '/form');

        self::assertSame(HttpStatusCode::NoContent, $page->status());
        self::assertSame('GET, HEAD, OPTIONS', $page->header(ResponseHeader::Allow)?->value->render());
        self::assertSame('GET, HEAD, POST, OPTIONS', $form->header(ResponseHeader::Allow)?->value->render());
        self::assertSame('', $page->body());
        self::assertNull($page->header(ResponseHeader::ContentType));
    }

    /**
     * The API decides for itself, so an unsigned `OPTIONS` there is still exactly the refusal an
     * address that does not exist gets.
     *
     * @return void
     */
    public function testAnOptionsToTheApiIsTheRefusalAnAbsentAddressGets(): void
    {
        $api    = TestRequest::to(HttpMethod::Options, '/api/update/v1/version')->answer();
        $absent = TestRequest::to(HttpMethod::Options, '/no-such-page')->answer();

        self::assertSame(HttpStatusCode::MethodNotAllowed, $api->status());
        self::assertSame(self::lines($absent), self::lines($api));
        self::assertSame($absent->body(), $api->body());
    }

    // ───────────────────────── groups ─────────────────────────

    /**
     * A group's layers go on every route in it, after any the route had.
     *
     * @return void
     */
    public function testARouteGroupPutsItsLayersOnEveryRoute(): void
    {
        $own    = new AdminGate();
        $shared = new AdminGate();
        $routes = RouteGroup::through($shared)->routes(
            new Route(ExportFixturePath::Home, EchoController::factory()),
            new Route(ExportFixturePath::Guide, EchoController::factory())->through($own),
        )->toValues();

        self::assertCount(2, $routes);
        self::assertSame([$shared], $routes[0]->layers()->toValues());
        self::assertSame([$own, $shared], $routes[1]->layers()->toValues());
    }

    // ───────────────────────── what a request says back ─────────────────────────

    /**
     * The trailing slash is remembered, and the canonical target is the path without it and the
     * query as it was sent.
     *
     * @param string $target
     * @param bool   $slash
     * @param string $canonical
     * @return void
     */
    #[DataProvider('slashProvider')]
    public function testATrailingSlashIsRememberedAndTheCanonicalTargetKeepsTheQuery(
        string $target,
        bool $slash,
        string $canonical,
    ): void {
        $request = TestRequest::get($target)->request();

        self::assertSame($slash, $request->hasTrailingSlash());
        self::assertSame($canonical, $request->canonicalTarget());
    }

    /**
     * @return iterable<string, array{string, bool, string}>
     */
    public static function slashProvider(): iterable
    {
        yield 'a slash'                 => ['/releases/', true, '/releases'];
        yield 'a slash, a query, a fragment' => ['/releases/?a=1&b#x', true, '/releases?a=1&b'];
        yield 'none'                    => ['/releases', false, '/releases'];
        yield 'a query without one'     => ['/releases?a=1', false, '/releases?a=1'];
        yield 'the root'                => ['/', false, '/'];
        yield 'two'                     => ['/x//', true, '/x'];
    }

    /**
     * A request's origin is an origin written exactly, or none at all.
     *
     * @param string      $header
     * @param string|null $expected
     * @return void
     */
    #[DataProvider('originProvider')]
    public function testARequestsOriginIsExactlyAnOriginOrNone(string $header, ?string $expected): void
    {
        $request = TestRequest::get('/')->with(RequestHeader::Origin, $header)->request();

        self::assertSame($expected, $request->origin()?->render());
    }

    /**
     * @return iterable<string, array{string, string|null}>
     */
    public static function originProvider(): iterable
    {
        yield 'an origin'           => ['https://app.example.org', 'https://app.example.org'];
        yield 'with a port'         => ['http://localhost:8080', 'http://localhost:8080'];
        yield 'none'                => ['', null];
        yield "a browser's null"    => ['null', null];
        yield 'a capital'           => ['https://App.example.org', null];
        yield 'a trailing slash'    => ['https://app.example.org/', null];
        yield 'the default port'    => ['https://app.example.org:443', null];
        yield 'another scheme'      => ['ftp://app.example.org', null];
        yield 'a path'              => ['https://app.example.org/x', null];
    }

    /**
     * A site's listed origin is held to the same exactness, where it is written.
     *
     * @return void
     */
    public function testAListedOriginNotWrittenExactlyIsRefused(): void
    {
        $this->expectException(SecurityPolicyException::class);

        (void) Origin::of('https://app.example.org/');
    }

    /**
     * A preflight is an `OPTIONS` that asks for a method — neither alone.
     *
     * @return void
     */
    public function testAPreflightIsAnOptionsThatAsksForAMethod(): void
    {
        $asks = static fn(HttpMethod $method, string $asked): bool => TestRequest::to($method, '/')
            ->with(RequestHeader::AccessControlRequestMethod, $asked)
            ->request()
            ->isPreflight();

        self::assertTrue($asks(HttpMethod::Options, 'POST'));
        self::assertFalse($asks(HttpMethod::Options, ''));
        self::assertFalse($asks(HttpMethod::Get, 'POST'));
    }

    // ───────────────────────── helpers ─────────────────────────

    /**
     * @param RoutePatternFixture $pattern
     * @return Route
     */
    private static function route(RoutePatternFixture $pattern): Route
    {
        return new Route($pattern, EchoController::factory());
    }

    /**
     * What a router holding a page at `/` and a form at `/form` answers $method for $target with.
     *
     * @param HttpMethod $method
     * @param string     $target
     * @return Answer
     */
    private static function answer(HttpMethod $method, string $target): Answer
    {
        $request = TestRequest::to($method, $target)->request();
        $router  = new Router(new Collection(Route::class)->with(
            new Route(ExportFixturePath::Home, static fn(): EchoController => new EchoController('home')),
            new Route(
                RoutePatternFixture::Form,
                static fn(): EchoController => new EchoController('home'),
                MethodSet::of(HttpMethod::Get, HttpMethod::Post),
            ),
        ));

        return $router->handle($request)->answer($request);
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
