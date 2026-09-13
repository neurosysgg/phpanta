<?php

declare(strict_types=1);

namespace Phpanta\Test\Unit;

use ArrayObject;
use Phpanta\Support\Directory;
use Phpanta\Support\File;
use Phpanta\Tool\Cli\ExitCode;
use Phpanta\Tool\Cli\Output;
use Phpanta\Tool\Cli\Runner;
use Phpanta\Tool\Command\ApiCall;
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
 * No `#[CoversClass]`, like every other test of `tools/` — see {@link ApiClientTest}.
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
        $this->sandbox = sys_get_temp_dir() . '/neurosys-apicall-' . bin2hex(random_bytes(6));
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
        self::assertSame('https://neurosys.gg/api/capability/v1/extensions', $sent[0]->url->render());
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
     * Runs the command against a transport that answers $status with $body.
     *
     * @param int $status
     * @param string $body
     * @param string $service
     * @param string $version
     * @param string $action
     * @param ArrayObject<int, Request>|null $sent Where each request sent is recorded.
     * @return array{ExitCode, string, string}
     */
    private function call(
        int $status,
        string $body,
        string $service,
        string $version,
        string $action,
        ?ArrayObject $sent = null,
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
            new ApiCall('https://neurosys.gg', '.config/neurosys/update.key', $transport),
            ['--key', $this->keyFile->path, $service, $version, $action],
            new Output($out, $error),
        );

        rewind($out);
        rewind($error);

        return [$code, (string) stream_get_contents($out), (string) stream_get_contents($error)];
    }
}
