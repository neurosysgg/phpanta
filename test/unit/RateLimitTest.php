<?php

declare(strict_types=1);

namespace Phpanta\Test\Unit;

use Phpanta\Exception\SecurityPolicyException;
use Phpanta\Http\Answer;
use Phpanta\Http\CacheControl;
use Phpanta\Http\Header;
use Phpanta\Http\HttpStatusCode;
use Phpanta\Http\PlainTextResponse;
use Phpanta\Http\Request;
use Phpanta\Http\RequestHeader;
use Phpanta\Http\ResponseHeader;
use Phpanta\Http\RetryAfter;
use Phpanta\Http\ServerVariable;
use Phpanta\Service\Layer\RateLimit;
use Phpanta\Support\Directory;
use Phpanta\Support\File;
use Phpanta\Support\FileLock;
use Phpanta\Support\Throttle;
use Phpanta\Support\ThrottleVerdict;
use Phpanta\Test\TestRequest;
use Phpanta\Text\FrameworkText;
use Phpanta\Text\Language;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The layer that answers an address over its limit with a 429 — keyed by the remote address, and
 * a refusal no cache keeps.
 */
#[CoversClass(RateLimit::class)]
#[CoversClass(RetryAfter::class)]
#[CoversClass(Throttle::class)]
#[CoversClass(ThrottleVerdict::class)]
#[CoversClass(FileLock::class)]
#[CoversClass(File::class)]
#[CoversClass(Directory::class)]
#[CoversClass(Request::class)]
#[CoversClass(PlainTextResponse::class)]
#[CoversClass(Answer::class)]
#[CoversClass(Header::class)]
#[CoversClass(CacheControl::class)]
final class RateLimitTest extends TestCase
{
    /** A directory of this test's own, removed afterwards. */
    private Directory $directory;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        $this->directory = Directory::temporary('phpanta-rate-limit-');
    }

    /**
     * @return void
     */
    protected function tearDown(): void
    {
        $this->directory->remove();
    }

    /**
     * A limit of one request an hour, so the second is refused whenever the test runs.
     *
     * @return RateLimit
     */
    private function limited(): RateLimit
    {
        return new RateLimit(new Throttle($this->directory, 1, 3600));
    }

    /**
     * $address asking for a page in German, answered through $layer.
     *
     * @param RateLimit $layer
     * @param string    $address
     * @return Answer
     */
    private static function ask(RateLimit $layer, string $address): Answer
    {
        $request = TestRequest::get('/')
            ->withServer(ServerVariable::RemoteAddress, $address)
            ->with(RequestHeader::AcceptLanguage, 'de')
            ->request();

        return $layer->handle($request, new EchoController('page'))->answer($request);
    }

    /**
     * @return void
     */
    public function testUnderTheLimitTheRequestGoesThrough(): void
    {
        $answer = self::ask($this->limited(), '203.0.113.9');

        self::assertSame(HttpStatusCode::Ok, $answer->status());
        self::assertSame('page GET', $answer->body());
    }

    /**
     * Over it, a 429 in the request's language that says how long to wait and that no cache keeps.
     *
     * @return void
     */
    public function testOverTheLimitTheAnswerIsA429(): void
    {
        $layer = $this->limited();
        (void) self::ask($layer, '203.0.113.9');

        $answer = self::ask($layer, '203.0.113.9');
        $wait   = (int) $answer->header(ResponseHeader::RetryAfter)?->value->render();

        self::assertSame(HttpStatusCode::TooManyRequests, $answer->status());
        self::assertSame(FrameworkText::TooManyRequests->in(Language::German) . "\n", $answer->body());
        self::assertSame('no-store, private', $answer->header(ResponseHeader::CacheControl)?->value->render());

        // The hour, less the second a slow run may have crossed between the two requests.
        self::assertGreaterThanOrEqual(3599, $wait);
        self::assertLessThanOrEqual(3600, $wait);
    }

    /**
     * Two addresses are two allowances.
     *
     * @return void
     */
    public function testTwoAddressesAreCountedApart(): void
    {
        $layer = $this->limited();

        self::assertSame(HttpStatusCode::Ok, self::ask($layer, '203.0.113.9')->status());
        self::assertSame(HttpStatusCode::TooManyRequests, self::ask($layer, '203.0.113.9')->status());
        self::assertSame(HttpStatusCode::Ok, self::ask($layer, '2001:db8::1')->status());
    }

    /**
     * The key is the address the server reports, and a request with none has the empty one.
     *
     * @return void
     */
    public function testTheRequestSaysWhereItCameFrom(): void
    {
        self::assertSame(
            '203.0.113.9',
            TestRequest::get('/')->withServer(ServerVariable::RemoteAddress, '203.0.113.9')->request()->remoteAddress(),
        );
        self::assertSame('', TestRequest::get('/')->request()->remoteAddress());
    }

    /**
     * A negative wait is not a header.
     *
     * @return void
     */
    public function testRetryAfterRefusesANegativeWait(): void
    {
        self::assertSame('0', new RetryAfter(0)->render());

        $this->expectException(SecurityPolicyException::class);

        new RetryAfter(-1);
    }
}
