<?php

declare(strict_types=1);

namespace Phpanta\Test\Unit;

use ArrayObject;
use Phpanta\Http\HttpMethod;
use Phpanta\Support\Directory;
use Phpanta\Support\File;
use Phpanta\Tool\Cli\ExitCode;
use Phpanta\Tool\Cli\Output;
use Phpanta\Tool\Cli\Runner;
use Phpanta\Tool\Command\ApiCall;
use Phpanta\Tool\Http\OutboundHeader;
use Phpanta\Tool\Http\Request;
use Phpanta\Tool\Http\Response;
use Phpanta\Tool\Http\Transport;
use PHPUnit\Framework\TestCase;

/**
 * `php tools/api.php`: what the operator sees, and what a script can branch on.
 *
 * **The exit code is the answer's**, which is what makes a failed `health` check scriptable after a
 * push — and **only a 404 is explained as a refusal**, because only a 404 is one. A 503 from a
 * verified handler carries its own report, and prefacing it with "check your key" would send
 * somebody looking in exactly the wrong place.
 *
 * No `#[CoversClass]`, like every other test of `tools/`: `tools/` is not coverage source, so a
 * class named there would record nothing.
 */
final class ApiCallTest extends TestCase
{
    private string $sandbox = '';
    private File $keyFile;

    /**
     * A real private key on disk, because the command signs before it sends.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->sandbox = sys_get_temp_dir() . '/phpanta-apicall-' . bin2hex(random_bytes(6));
        new Directory($this->sandbox)->create();

        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        self::assertNotFalse($key, 'this host cannot generate an EC key, so nothing below is meaningful');
        self::assertTrue(openssl_pkey_export($key, $pem));

        $this->keyFile = new File($this->sandbox . '/update.key');
        self::assertTrue($this->keyFile->write((string) $pem, 0o600));
    }

    /**
     * @return void
     */
    protected function tearDown(): void
    {
        if ($this->sandbox !== '') {
            UpdateFixture::removeTree($this->sandbox);
        }
    }

    /**
     * A 2xx prints the body and nothing else, and exits 0.
     *
     * @return void
     */
    public function testAnOkAnswerIsPrintedAndSucceeds(): void
    {
        [$code, $out, $error] = $this->call(200, "0 fail\n", 'health', 'v1', 'report');

        self::assertSame(ExitCode::Success, $code);
        self::assertSame("0 fail\n", $out);
        self::assertSame('', $error);
    }

    /**
     * A failed health check prints its report, says what came back, and exits 1 — without the
     * refusal's list of causes, which would name the key for a fault that is not the key's.
     *
     * @return void
     */
    public function testA503PrintsItsReportAndFailsWithoutBlamingTheKey(): void
    {
        [$code, $out, $error] = $this->call(503, "deployment\n  DOCUMENT_ROOT  FAIL\n", 'health', 'v1', 'deployment');

        self::assertSame(ExitCode::Failure, $code);
        self::assertSame("deployment\n  DOCUMENT_ROOT  FAIL\n", $out);
        self::assertSame("\nanswered 503.\n", $error);
    }

    /**
     * A 404 is the one refusal, and is explained as one.
     *
     * @return void
     */
    public function testA404IsExplainedAsARefusal(): void
    {
        [$code, , $error] = $this->call(404, '<!doctype html>', 'capability', 'v1', 'runtime');

        self::assertSame(ExitCode::Failure, $code);
        self::assertStringStartsWith("\nrefused with 404.\n", $error);
        self::assertStringContainsString('data/update.pub', $error);
    }

    /**
     * The new service is addressed by the same command, with no line of it naming the service.
     *
     * @return void
     */
    public function testTheCapabilityServiceIsAddressedLikeAnyOther(): void
    {
        /** @var ArrayObject<int, Request> $sent */
        $sent = new ArrayObject();

        [$code] = $this->call(200, '', 'capability', 'v1', 'extensions', $sent);

        self::assertSame(ExitCode::Success, $code);
        self::assertCount(1, $sent);
        self::assertSame('https://example.test/api/capability/v1/extensions', $sent[0]->url->render());
    }

    /**
     * An action no service has is refused before anything is sent, since `/api` would answer it
     * exactly as it answers a bad key.
     *
     * @return void
     */
    public function testAnActionNoServiceHasIsRefusedBeforeSending(): void
    {
        /** @var ArrayObject<int, Request> $sent */
        $sent = new ArrayObject();

        [$code, , $error] = $this->call(200, '', 'capability', 'v1', 'report', $sent);

        self::assertSame(ExitCode::Usage, $code);
        self::assertStringContainsString('no such action', $error);
        self::assertCount(0, $sent);
    }

    /**
     * A rollback is a write with no body: sent as a POST, signed with `apply`, and `--dry-run` is
     * what turns `apply` off.
     *
     * @return void
     */
    public function testARollbackIsSignedAsAWriteAndItsDryRunAsNone(): void
    {
        /** @var ArrayObject<int, Request> $sent */
        $sent = new ArrayObject();

        [$code] = $this->call(200, "applied\n", 'update', 'v1', 'rollback', $sent);
        [$dry]  = $this->call(200, "dry run\n", 'update', 'v1', 'rollback', $sent, ['--dry-run']);

        self::assertSame(ExitCode::Success, $code);
        self::assertSame(ExitCode::Success, $dry);
        self::assertCount(2, $sent);
        self::assertSame('https://example.test/api/update/v1/rollback', $sent[0]->url->render());
        self::assertSame(HttpMethod::Post, $sent[0]->method);
        self::assertSame('', $sent[0]->body());
        self::assertTrue(self::manifestOf($sent[0])['apply'], 'a rollback was signed as a dry run');
        self::assertFalse(self::manifestOf($sent[1])['apply'], '--dry-run did not reach the manifest');
    }

    /**
     * A read has nothing to rehearse, so `--dry-run` on one is refused before anything is sent —
     * rather than ignored, which would be a flag trusted on the wrong address one day.
     *
     * @return void
     */
    public function testADryRunOfAReadIsRefusedBeforeSending(): void
    {
        /** @var ArrayObject<int, Request> $sent */
        $sent = new ArrayObject();

        [$code, , $error] = $this->call(200, '', 'update', 'v1', 'version', $sent, ['--dry-run']);

        self::assertSame(ExitCode::Usage, $code);
        self::assertStringContainsString('has no dry run', $error);
        self::assertCount(0, $sent);
    }

    /**
     * `patch` is still the one action this command will not send: its body is a tree, and building
     * that is `push-update`'s whole job.
     *
     * @return void
     */
    public function testThePatchActionIsStillRefused(): void
    {
        /** @var ArrayObject<int, Request> $sent */
        $sent = new ArrayObject();

        [$code, , $error] = $this->call(200, '', 'update', 'v1', 'patch', $sent);

        self::assertSame(ExitCode::Usage, $code);
        self::assertStringContainsString('carries a body', $error);
        self::assertCount(0, $sent);
    }

    /**
     * An unverified write is the 405 an unrouted write gets, and is explained as the refusal it is.
     *
     * @return void
     */
    public function testA405ToAWriteIsExplainedAsARefusal(): void
    {
        [$code, , $error] = $this->call(405, "Method Not Allowed\n", 'update', 'v1', 'rollback');

        self::assertSame(ExitCode::Failure, $code);
        self::assertStringStartsWith("\nrefused with 405.\n", $error);
        self::assertStringContainsString('data/update.pub', $error);
    }

    /**
     * A verified refusal is not a key problem: a rollback with nothing to roll back is a 422 whose
     * body says so, and the command says only what came back.
     *
     * @return void
     */
    public function testA422IsNotBlamedOnTheKey(): void
    {
        [$code, $out, $error] = $this->call(422, "refused: there is no previous release\n", 'update', 'v1', 'rollback');

        self::assertSame(ExitCode::Failure, $code);
        self::assertSame("refused: there is no previous release\n", $out);
        self::assertSame("\nanswered 422.\n", $error);
    }

    /**
     * The manifest a request was signed with, read back out of its `Authorization` header.
     *
     * @param Request $request
     * @return array<string, mixed>
     */
    private static function manifestOf(Request $request): array
    {
        $line = (string) $request->header(OutboundHeader::Authorization)?->line();
        $blob = (string) base64_decode(substr($line, (int) strpos($line, 'NS1 ') + 4), true);

        /** @var array{1: int} $length */
        $length = unpack('N', substr($blob, 0, 4));

        /** @var array<string, mixed> $manifest */
        $manifest = json_decode(substr($blob, 4, $length[1]), true, 8, JSON_THROW_ON_ERROR);

        return $manifest;
    }

    /**
     * Runs the command against a transport that answers $status with $body.
     *
     * @param int $status
     * @param string $body
     * @param string $service
     * @param string $version
     * @param string $action
     * @param ArrayObject<int, Request>|null $sent Where each request sent is recorded.
     * @param list<string> $flags More words for the command line, after the key.
     * @return array{ExitCode, string, string}
     */
    private function call(
        int $status,
        string $body,
        string $service,
        string $version,
        string $action,
        ?ArrayObject $sent = null,
        array $flags = [],
    ): array {
        $transport = new readonly class ($status, $body, $sent ?? new ArrayObject()) implements Transport {
            /**
             * @param int $status
             * @param string $body
             * @param ArrayObject<int, Request> $sent
             */
            public function __construct(private int $status, private string $body, private ArrayObject $sent) {}

            /**
             * @param Request $request
             * @return Response
             */
            public function send(Request $request): Response
            {
                $this->sent->append($request);

                return new Response($this->status, $this->body);
            }
        };

        $out   = fopen('php://memory', 'r+');
        $error = fopen('php://memory', 'r+');

        $code = Runner::execute(
            new ApiCall('https://example.test', '.config/example/update.key', $transport),
            ['--key', $this->keyFile->path, ...$flags, $service, $version, $action],
            new Output($out, $error),
        );

        rewind($out);
        rewind($error);

        return [$code, (string) stream_get_contents($out), (string) stream_get_contents($error)];
    }
}
