<?php

declare(strict_types=1);

namespace Phpanta\Test\Unit;

use ArrayObject;
use Phpanta\Http\Api\ApiListing;
use Phpanta\Http\HttpMethod;
use Phpanta\Support\Directory;
use Phpanta\Support\File;
use Phpanta\Text\Language;
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
 * push — and **only a 401 is explained as a refusal**, because only a 401 is one; an answer that is
 * not the admin's at all is explained as a server older than it. A 503 from a verified handler
 * carries its own report, and prefacing it with "check your key" would send somebody looking in
 * exactly the wrong place.
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
     * A 2xx prints the answer's text and nothing else, and exits 0.
     *
     * @return void
     */
    public function testAnOkAnswerIsPrintedAndSucceeds(): void
    {
        [$code, $out, $error] = $this->call(200, self::written(200, null, '0 fail'), 'health', 'v1', 'report');

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
        [$code, $out, $error] = $this->call(
            503,
            self::written(503, 'deployment', 'DOCUMENT_ROOT  FAIL'),
            'health',
            'v1',
            'deployment',
        );

        self::assertSame(ExitCode::Failure, $code);
        self::assertSame("deployment\n  DOCUMENT_ROOT  FAIL\n", $out);
        self::assertSame("\nanswered 503.\n", $error);
    }

    /**
     * A 401 is the one refusal, and is explained as one — for a read and a write alike.
     *
     * @return void
     */
    public function testA401IsExplainedAsARefusal(): void
    {
        $refusal = self::written(401, null, 'this needs a signed request');

        foreach ([['capability', 'v1', 'runtime'], ['update', 'v1', 'rollback']] as [$service, $version, $action]) {
            [$code, $out, $error] = $this->call(401, $refusal, $service, $version, $action);

            self::assertSame(ExitCode::Failure, $code);
            self::assertSame("this needs a signed request\n", $out);
            self::assertStringStartsWith("\nrefused with 401.\n", $error);
            self::assertStringContainsString('data/update.pub', $error);
        }
    }

    /**
     * An answer that is not the admin's at all — the site's own 404 page — is a server older than
     * the admin, and says so rather than printing the page.
     *
     * @return void
     */
    public function testAnAnswerThatIsNotTheAdminsIsAnOlderServer(): void
    {
        [$code, $out, $error] = $this->call(404, '<!doctype html>', 'capability', 'v1', 'runtime');

        self::assertSame(ExitCode::Failure, $code);
        self::assertSame('', $out);
        self::assertStringStartsWith("\nanswered 404, and not the way the admin answers.\n", $error);
        self::assertStringContainsString('older than this command', $error);
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
        self::assertSame('https://example.test/admin/capability/v1/extensions', $sent[0]->url->render());
    }

    /**
     * An action this checkout does not know is refused before anything is sent, since the method a
     * call is signed for comes from the action's own enum.
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

        [$code] = $this->call(200, self::written(200, null, 'applied'), 'update', 'v1', 'rollback', $sent);
        [$dry]  = $this->call(
            200,
            self::written(200, null, 'dry run'),
            'update',
            'v1',
            'rollback',
            $sent,
            ['--dry-run'],
        );

        self::assertSame(ExitCode::Success, $code);
        self::assertSame(ExitCode::Success, $dry);
        self::assertCount(2, $sent);
        self::assertSame('https://example.test/admin/update/v1/rollback', $sent[0]->url->render());
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
     * A verified refusal is not a key problem: a rollback with nothing to roll back is a 422 whose
     * body says so, and the command says only what came back.
     *
     * @return void
     */
    public function testA422IsNotBlamedOnTheKey(): void
    {
        [$code, $out, $error] = $this->call(
            422,
            self::written(422, null, 'refused: there is no previous release'),
            'update',
            'v1',
            'rollback',
        );

        self::assertSame(ExitCode::Failure, $code);
        self::assertSame("refused: there is no previous release\n", $out);
        self::assertSame("\nanswered 422.\n", $error);
    }

    /**
     * Less than a whole address asks the server what it offers there — the entrance, a service, a
     * version — with a signed read, and prints what came back a line each.
     *
     * @return void
     */
    public function testLessThanAnAddressListsWhatTheServerOffers(): void
    {
        /** @var ArrayObject<int, Request> $sent */
        $sent    = new ArrayObject();
        $listing = (string) json_encode(ApiListing::services(Language::English), JSON_THROW_ON_ERROR);

        [$code, $out, $error] = $this->invoke(200, $listing, [], $sent);
        $this->invoke(200, $listing, ['update'], $sent);
        $this->invoke(200, $listing, ['update', 'v1'], $sent);

        self::assertSame(ExitCode::Success, $code);
        self::assertStringStartsWith("/admin\n", $out);
        self::assertSame('', $error);

        self::assertSame(
            ['https://example.test/admin', 'https://example.test/admin/update', 'https://example.test/admin/update/v1'],
            array_map(static fn(Request $request): string => $request->url->render(), $sent->getArrayCopy()),
        );
        self::assertSame(HttpMethod::Get, $sent[0]->method);
        self::assertSame('/admin', self::manifestOf($sent[0])['path']);
    }

    /**
     * A listing of something the server does not have is its refusal, printed as it came.
     *
     * @return void
     */
    public function testAListingOfNothingPrintsTheRefusal(): void
    {
        $refusal = self::written(404, null, 'no such admin address: /admin/nope');

        [$code, $out, $error] = $this->invoke(404, $refusal, ['nope']);

        self::assertSame(ExitCode::Failure, $code);
        self::assertSame("no such admin address: /admin/nope\n", $out);
        self::assertSame("\nanswered 404.\n", $error);
    }

    /**
     * A listing only reads, so it has no dry run either.
     *
     * @return void
     */
    public function testAListingHasNoDryRun(): void
    {
        /** @var ArrayObject<int, Request> $sent */
        $sent = new ArrayObject();

        [$code, , $error] = $this->invoke(200, '', ['update'], $sent, ['--dry-run']);

        self::assertSame(ExitCode::Usage, $code);
        self::assertStringContainsString('has no dry run', $error);
        self::assertCount(0, $sent);
    }

    /**
     * An action's own fields come from their flags: an enrolment is signed with the code and the name
     * it was given, and a flag missing, one the action does not take, or one on a listing, is refused
     * before anything is sent.
     *
     * @return void
     */
    public function testAnActionsFieldsComeFromItsFlags(): void
    {
        /** @var ArrayObject<int, Request> $sent */
        $sent = new ArrayObject();

        [$code] = $this->call(
            200,
            self::written(200, null, 'enrolled'),
            'access',
            'v1',
            'enrol',
            $sent,
            ['--code', 'sealed', '--name', 'phone'],
        );

        self::assertSame(ExitCode::Success, $code);
        self::assertCount(1, $sent);

        $manifest = self::manifestOf($sent[0]);

        self::assertSame(['sealed', 'phone', true], [$manifest['code'], $manifest['name'], $manifest['apply']]);

        [$missing, , $needs]  = $this->call(200, '', 'access', 'v1', 'enrol', $sent, ['--code', 'sealed']);
        [$extra, , $takes]    = $this->call(200, '', 'access', 'v1', 'passkeys', $sent, ['--passkey', 'one']);
        [$listing, , $lists]  = $this->invoke(200, '', ['access'], $sent, ['--name', 'phone']);

        self::assertSame([ExitCode::Usage, ExitCode::Usage, ExitCode::Usage], [$missing, $extra, $listing]);
        self::assertStringContainsString('enrol needs --name', $needs);
        self::assertStringContainsString('passkeys takes no --passkey', $takes);
        self::assertStringContainsString('a listing takes no --name', $lists);
        self::assertCount(1, $sent);
    }

    /**
     * An admin answer as the server writes one: a status and one section of lines.
     *
     * @param int $status
     * @param string|null $caption
     * @param string ...$lines
     * @return string
     */
    private static function written(int $status, ?string $caption, string ...$lines): string
    {
        return (string) json_encode(
            ['status' => $status, 'sections' => [['caption' => $caption, 'lines' => $lines]]],
            JSON_THROW_ON_ERROR,
        );
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
     * Runs the command for one whole address against a transport that answers $status with $body.
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
        return $this->invoke($status, $body, [$service, $version, $action], $sent, $flags);
    }

    /**
     * Runs the command with $operands against a transport that answers $status with $body.
     *
     * @param int $status
     * @param string $body
     * @param list<string> $operands
     * @param ArrayObject<int, Request>|null $sent Where each request sent is recorded.
     * @param list<string> $flags More words for the command line, after the key.
     * @return array{ExitCode, string, string}
     */
    private function invoke(
        int $status,
        string $body,
        array $operands,
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
            ['--key', $this->keyFile->path, ...$flags, ...$operands],
            new Output($out, $error),
        );

        rewind($out);
        rewind($error);

        return [$code, (string) stream_get_contents($out), (string) stream_get_contents($error)];
    }
}
