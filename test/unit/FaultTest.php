<?php

declare(strict_types=1);

namespace Phpanta\Test\Unit;

use LogicException;
use Phpanta\App;
use Phpanta\Environment;
use Phpanta\Exception\AppException;
use Phpanta\Http\Answer;
use Phpanta\Http\Header;
use Phpanta\Http\HttpStatusCode;
use Phpanta\Http\PlainTextResponse;
use Phpanta\Http\Request;
use Phpanta\Http\ResponseHeader;
use Phpanta\Http\SecurityHeaders;
use Phpanta\Http\ServerVariable;
use Phpanta\Model\Health\Area;
use Phpanta\Model\Health\Finding;
use Phpanta\Model\Health\Level;
use Phpanta\Service\Health\EnvironmentRequirement;
use Phpanta\Support\ErrorLog;
use Phpanta\Test\TestRequest;
use Phpanta\View\FaultPage;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * What a request that faulted is told: nothing but the status, unless this is a development
 * deployment and the request came from this machine — and never the one without the other.
 */
#[CoversClass(App::class)]
#[CoversClass(Environment::class)]
#[CoversClass(FaultPage::class)]
#[CoversClass(EnvironmentRequirement::class)]
#[CoversClass(ErrorLog::class)]
#[CoversClass(Request::class)]
#[CoversClass(ServerVariable::class)]
#[CoversClass(Answer::class)]
#[CoversClass(PlainTextResponse::class)]
final class FaultTest extends TestCase
{
    /** What a fault's message might carry that no visitor may read. */
    private const string SECRET = 'the key is under /home/someone/.config';

    /** @var array<array-key, mixed> */
    private array $server;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        $this->server = $_SERVER;
    }

    /**
     * @return void
     */
    protected function tearDown(): void
    {
        $_SERVER = $this->server;
    }

    // ───────────────────────── who is told what ─────────────────────────

    /**
     * Production tells every visitor the status and nothing else — the loopback one included, and
     * whatever the fault carried.
     *
     * @param string $address
     * @return void
     */
    #[DataProvider('everyoneProvider')]
    public function testProductionTellsAVisitorNothingButTheStatus(string $address): void
    {
        self::assertBare(App::current()->fault(self::fault(), self::from($address), Environment::Production));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function everyoneProvider(): iterable
    {
        yield 'this machine' => ['127.0.0.1'];
        yield 'a visitor'    => ['203.0.113.9'];
    }

    /**
     * Development shows this machine the fault, the chain beneath it and the frames that led
     * there — escaped like every other word, and not kept.
     *
     * @return void
     */
    public function testDevelopmentShowsTheFaultToThisMachine(): void
    {
        $answer = App::current()->fault(self::fault(), self::from('127.0.0.1'), Environment::Development);
        $body   = $answer->body();

        self::assertSame(HttpStatusCode::InternalServerError, $answer->status());
        self::assertSame('text/html; charset=utf-8', $answer->header(ResponseHeader::ContentType)?->value->render());
        self::assertSame('no-store, private', $answer->header(ResponseHeader::CacheControl)?->value->render());
        self::assertLedBySecurityHeaders($answer);

        self::assertStringContainsString('<title>Something broke</title>', $body);
        self::assertStringContainsString(RuntimeException::class, $body);
        self::assertStringContainsString(self::SECRET . ' &lt;b&gt;', $body);
        self::assertStringNotContainsString('<b>', $body);
        self::assertStringContainsString(__FILE__, $body);
        self::assertStringContainsString(LogicException::class, $body);
        self::assertStringContainsString('what came first', $body);
        self::assertStringContainsString(self::class . '-&gt;testDevelopmentShowsTheFaultToThisMachine()', $body);
    }

    /**
     * The page is in the language the request is answered in.
     *
     * @return void
     */
    public function testTheFaultPageSpeaksTheRequestsLanguage(): void
    {
        $request = TestRequest::get('/')
            ->withServer(ServerVariable::RemoteAddress, '::1')
            ->with(\Phpanta\Http\RequestHeader::AcceptLanguage, 'de')
            ->request();
        $answer  = App::current()->fault(self::fault(), $request, Environment::Development);

        self::assertStringContainsString('<html lang="de">', $answer->body());
        self::assertStringContainsString('Etwas ist kaputtgegangen', $answer->body());
    }

    /**
     * Development alone is not enough: to any other address it is production.
     *
     * @param string $address
     * @return void
     */
    #[DataProvider('elsewhereProvider')]
    public function testDevelopmentShowsNothingToAnyoneElse(string $address): void
    {
        self::assertBare(App::current()->fault(self::fault(), self::from($address), Environment::Development));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function elsewhereProvider(): iterable
    {
        yield 'a public address'           => ['203.0.113.9'];
        yield 'a private one'              => ['10.0.0.1'];
        yield 'the local network'          => ['192.168.1.20'];
        yield 'IPv6'                       => ['2001:db8::1'];
        yield 'IPv4 through a v6 socket'   => ['::ffff:10.0.0.1'];
        yield 'nothing at all'             => [''];
        yield 'a name, not an address'     => ['localhost'];
        yield 'loopback as a prefix'       => ['127.0.0.1.example'];
    }

    /**
     * Loopback is read as an address, not matched as a string.
     *
     * @param string $address
     * @param bool   $expected
     * @return void
     */
    #[DataProvider('loopbackProvider')]
    public function testLoopbackIsReadAsAnAddress(string $address, bool $expected): void
    {
        self::assertSame($expected, self::from($address)->isFromLoopback());
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function loopbackProvider(): iterable
    {
        yield '127.0.0.1'                 => ['127.0.0.1', true];
        yield 'anywhere in 127/8'         => ['127.9.8.7', true];
        yield '::1'                       => ['::1', true];
        yield '::1 written out'           => ['0:0:0:0:0:0:0:1', true];
        yield 'v4 loopback on a v6 socket' => ['::ffff:127.0.0.1', true];
        yield 'one past 127/8'            => ['128.0.0.1', false];
        yield '::2'                       => ['::2', false];
        yield 'the unspecified address'   => ['::', false];
        yield 'garbage'                   => ['not an address', false];
    }

    /**
     * A request built with no address at all — a synthetic one, a CLI — is not from loopback.
     *
     * @return void
     */
    public function testARequestThatNeverArrivedIsNotFromLoopback(): void
    {
        self::assertFalse(TestRequest::get('/')->request()->isFromLoopback());
    }

    // ───────────────────────── which environment this is ─────────────────────────

    /**
     * Only the exact word makes development; everything else — nothing, a capital, an
     * abbreviation, a space — is production.
     *
     * @param string|null $value
     * @param Environment $expected
     * @return void
     */
    #[DataProvider('environmentProvider')]
    public function testOnlyTheExactWordMakesDevelopment(?string $value, Environment $expected): void
    {
        self::stated($value);

        self::assertSame($expected, App::current()->environment());
    }

    /**
     * @return iterable<string, array{string|null, Environment}>
     */
    public static function environmentProvider(): iterable
    {
        yield 'unset'          => [null, Environment::Production];
        yield 'empty'          => ['', Environment::Production];
        yield 'production'     => ['production', Environment::Production];
        yield 'development'    => ['development', Environment::Development];
        yield 'capitalised'    => ['Development', Environment::Production];
        yield 'abbreviated'    => ['dev', Environment::Production];
        yield 'padded'         => [' development', Environment::Production];
    }

    /**
     * The app answers a fault as the environment the server states when a caller names none.
     *
     * @return void
     */
    public function testAFaultIsAnsweredAsTheStatedEnvironmentByDefault(): void
    {
        self::stated('development');
        self::assertStringContainsString(self::SECRET, App::current()->fault(self::fault(), self::from('::1'))->body());

        self::stated(null);
        self::assertBare(App::current()->fault(self::fault(), self::from('::1')));
    }

    /**
     * The health report warns — optional, so not a failure — about a deployment that says it is
     * development, and passes one that says nothing.
     *
     * @return void
     */
    public function testTheHealthReportWarnsAboutADevelopmentDeployment(): void
    {
        $requirement = new EnvironmentRequirement();

        self::assertSame(Level::Optional, $requirement->level());
        self::assertSame(Area::Deployment, $requirement->area());
        self::assertSame('PHPANTA_ENVIRONMENT', $requirement->name());
        self::assertSame('production', $requirement->expected());

        self::stated('development');
        self::assertEquals(new Finding('development', false), $requirement->check());

        self::stated(null);
        self::assertEquals(new Finding('production', true), $requirement->check());
    }

    /**
     * The log gets what the visitor does not: the app, the class, whose mistake it was, where, and
     * the message.
     *
     * @return void
     */
    public function testTheLogLineSaysWhatTheVisitorIsNotTold(): void
    {
        $fault = self::fault();

        self::assertSame(
            sprintf(
                'phpanta: uncaught %s (from underneath it) at %s:%d — %s',
                RuntimeException::class,
                __FILE__,
                $fault->getLine(),
                self::SECRET . ' <b>',
            ),
            ErrorLog::faultLine($fault, 'phpanta'),
        );
        self::assertStringContainsString(
            '(from this repository)',
            ErrorLog::faultLine(new AppException('ours'), 'phpanta'),
        );
    }

    // ───────────────────────── helpers ─────────────────────────

    /**
     * A bare 500: the status, plain text, the security headers, and nothing the fault said.
     *
     * @param Answer $answer
     * @return void
     */
    private static function assertBare(Answer $answer): void
    {
        self::assertSame(HttpStatusCode::InternalServerError, $answer->status());
        self::assertSame("500\n", $answer->body());
        self::assertSame('text/plain; charset=utf-8', $answer->header(ResponseHeader::ContentType)?->value->render());
        self::assertLedBySecurityHeaders($answer);

        foreach ([self::SECRET, RuntimeException::class, __FILE__] as $secret) {
            self::assertStringNotContainsString($secret, $answer->body());
        }
    }

    /**
     * @param Answer $answer
     * @return void
     */
    private static function assertLedBySecurityHeaders(Answer $answer): void
    {
        $expected = SecurityHeaders::all()->map(static fn(Header $header): string => $header->line())->toValues();
        $lines    = $answer->headers()->map(static fn(Header $header): string => $header->line())->toValues();

        self::assertSame($expected, array_slice($lines, 0, count($expected)));
    }

    /**
     * A fault with a secret, markup, and a fault beneath it.
     *
     * @return RuntimeException
     */
    private static function fault(): RuntimeException
    {
        return new RuntimeException(self::SECRET . ' <b>', 7, new LogicException('what came first'));
    }

    /**
     * @param string $address
     * @return Request
     */
    private static function from(string $address): Request
    {
        return TestRequest::get('/')->withServer(ServerVariable::RemoteAddress, $address)->request();
    }

    /**
     * Has the server state $value as the environment, or nothing where it is null.
     *
     * @param string|null $value
     * @return void
     */
    private static function stated(?string $value): void
    {
        unset($_SERVER[ServerVariable::Environment->value]);

        if ($value !== null) {
            $_SERVER[ServerVariable::Environment->value] = $value;
        }
    }
}
