<?php

declare(strict_types=1);

namespace Phpanta\Test\Unit;

use ArrayObject;
use BackedEnum;
use Phpanta\Http\Api\ApiAction;
use Phpanta\Http\Api\ApiService;
use Phpanta\Http\Api\ApiVersion;
use Phpanta\Http\Api\CapabilityAction;
use Phpanta\Http\Api\HealthAction;
use Phpanta\Http\Api\UpdateAction;
use Phpanta\Http\AuthScheme;
use Phpanta\Http\HttpMethod;
use Phpanta\Http\ServerVariable;
use Phpanta\Model\Api\VerifiedRequest;
use Phpanta\Service\ApiGate;
use Phpanta\Support\Directory;
use Phpanta\Support\File;
use Phpanta\Test\TestRequest;
use Phpanta\Tool\Api\PrivateKey;
use Phpanta\Tool\Api\SignedRequest;
use Phpanta\Tool\Http\OutboundHeader;
use Phpanta\Tool\Http\Request;
use Phpanta\Tool\Http\Response;
use Phpanta\Tool\Http\Transport;
use Phpanta\Tool\Http\Url;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Uri\Rfc3986\Uri;

/**
 * The two halves of the signed handshake, checked against each other.
 *
 * **This is the test that would have caught the failure `AuthScheme` was written to end**, one layer
 * out. The client mints a credential and the server reads one, in two directories, and a
 * disagreement about any of it — the scheme token, the frame, which bytes are signed, what the
 * manifest calls its fields, which path the signature commits to — fails **closed and in silence**:
 * every push refused, identically, on every attempt, with nothing in any log, and the first thing
 * anybody would suspect is the key.
 *
 * So the assertion is not that either side is right but that they **agree**: {@link SignedRequest}
 * builds the request, and {@link ApiGate} — the real one, over a real generated keypair — is asked
 * to verify it, with nothing in between restating the format.
 *
 * No `#[CoversClass]`, like every other test of `tools/`: that layer is outside the coverage source
 * (`phpunit.xml.dist` includes `src/` and nothing else), so naming classes here would record
 * coverage against files no report reads.
 */
final class ApiClientTest extends TestCase
{
    private string $sandbox = '';
    private File $keyFile;
    private File $publicKeyFile;
    private File $serialFile;

    /**
     * A real keypair on disk, because the client reads its half from a file and the server reads
     * the other half from another one.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->sandbox = sys_get_temp_dir() . '/phpanta-client-' . bin2hex(random_bytes(6));
        new Directory($this->sandbox)->create();

        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        self::assertNotFalse($key, 'this host cannot generate an EC key, so nothing below is meaningful');

        self::assertTrue(openssl_pkey_export($key, $pem));
        $details = openssl_pkey_get_details($key);
        self::assertIsArray($details);

        $this->keyFile       = new File($this->sandbox . '/update.key');
        $this->publicKeyFile = new File($this->sandbox . '/update.pub');
        $this->serialFile    = new File($this->sandbox . '/.update-serial');

        self::assertTrue($this->keyFile->write((string) $pem, 0o600));
        self::assertTrue($this->publicKeyFile->write((string) $details['key']));
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
     * A push the client builds is a push the gate accepts, body and all.
     *
     * @return void
     */
    public function testAPushTheClientBuildsVerifiesOnTheServer(): void
    {
        $archive  = UpdateFixture::archive(['public/a.txt' => 'x']);
        $verified = $this->verify($this->build(UpdateAction::Patch, $archive, ['apply' => true, 'mirror' => false]));

        self::assertInstanceOf(VerifiedRequest::class, $verified);
        self::assertSame($archive, $verified->body);
        self::assertSame('/admin/update/v1/patch', $verified->envelope->path);
        self::assertSame('POST', $verified->envelope->method);
    }

    /**
     * A read the client builds verifies too, carrying no body at all.
     *
     * @return void
     */
    public function testAReadTheClientBuildsVerifiesOnTheServer(): void
    {
        $verified = $this->verify($this->build(UpdateAction::Version, '', []));

        self::assertInstanceOf(VerifiedRequest::class, $verified);
        self::assertSame('', $verified->body);
        self::assertSame('/admin/update/v1/version', $verified->envelope->path);
        self::assertSame('GET', $verified->envelope->method);
    }

    /**
     * The client signs the address it sends to, byte for byte.
     *
     * The two are built in one place and in that order, so they cannot disagree — but the *reason*
     * they cannot is worth pinning, because {@link \Phpanta\Support\FillsPlaceholders::to()} `rawurlencode`s
     * each value and is therefore not the inverse of {@link \Phpanta\Support\Route::matches()}. A
     * later action whose name needs encoding would still agree; one rebuilt from the router's
     * captures on the server side would not.
     *
     * @return void
     */
    public function testTheUrlAndTheSignedPathAreTheSameString(): void
    {
        $request = $this->build(UpdateAction::Version, '', []);

        self::assertSame(
            'https://example.test/admin/update/v1/version',
            $request->url->render(),
        );
        self::assertSame(
            '/admin/update/v1/version',
            $this->verify($request)?->envelope->path,
        );
    }

    /**
     * Every other service needs nothing of this client, which is the claim the client makes.
     *
     * {@link SignedRequest} takes an {@link \Phpanta\Http\Api\ApiService}, an
     * {@link \Phpanta\Http\Api\ApiVersion} and an `ApiAction&BackedEnum`, and derives the path,
     * the method and the scheme from them — so `health` and `capability` are signed and addressed
     * by the same code that signs a push, with no branch anywhere naming a service. That is easy to
     * believe and was worth a row each: `ApiCall` also refuses an action it cannot resolve *before*
     * sending, because the method it signs for comes from the action's own enum, and it lists what
     * the server offers when it is given less than a whole address.
     *
     * @param ApiService $service
     * @param ApiAction&BackedEnum $action
     * @param string $path
     * @return void
     */
    #[DataProvider('serviceProvider')]
    public function testEveryServiceIsSignedByTheSameClient(
        ApiService $service,
        ApiAction&BackedEnum $action,
        string $path,
    ): void {
        $request = SignedRequest::build(
            new Url('https://example.test'),
            $service,
            ApiVersion::V1,
            $action,
            '',
            [],
            PrivateKey::fromFile($this->keyFile),
        );

        $verified = $this->verify($request);

        self::assertSame('https://example.test' . $path, $request->url->render());
        self::assertInstanceOf(VerifiedRequest::class, $verified);
        self::assertSame($path, $verified->envelope->path);
        self::assertSame('GET', $verified->envelope->method);
        self::assertSame('', $verified->body);
    }

    /**
     * @return iterable<string, array{ApiService, ApiAction&BackedEnum, string}>
     */
    public static function serviceProvider(): iterable
    {
        yield 'health'     => [ApiService::Health, HealthAction::Report, '/admin/health/v1/report'];
        yield 'capability' => [ApiService::Capability, CapabilityAction::Settings, '/admin/capability/v1/settings'];
    }

    /**
     * A base given with a trailing slash does not become a different host.
     *
     * `//admin/update/v1/version` parses as an *authority*, so the first segment would be read as a
     * host and the request would go somewhere else entirely. Measured, not assumed.
     *
     * @return void
     */
    public function testATrailingSlashOnTheBaseIsNotAnAuthority(): void
    {
        $request = SignedRequest::build(
            new Url('https://example.test/'),
            ApiService::Update,
            ApiVersion::V1,
            UpdateAction::Version,
            '',
            [],
            PrivateKey::fromFile($this->keyFile),
        );

        self::assertSame('https://example.test/admin/update/v1/version', $request->url->render());
    }

    /**
     * The credential travels under the scheme the server matches on, in the header it reads.
     *
     * Both sides name {@link AuthScheme::NS1} rather than spelling `NS1` — this asserts the value
     * that reaches the wire, which is the half a shared enum cannot prove on its own.
     *
     * @return void
     */
    public function testTheCredentialTravelsAsAnNs1Authorization(): void
    {
        $header = $this->build(UpdateAction::Version, '', [])->header(OutboundHeader::Authorization);

        self::assertNotNull($header);
        self::assertStringStartsWith('NS1 ', $header->value->render());

        // Comfortably inside Apache's 8190-byte LimitRequestFieldSize, which is the whole reason
        // ApiCredential caps the manifest at 2048 rather than the 8192 the framed body allowed.
        self::assertLessThan(1024, strlen($header->line()));
    }

    /**
     * A push goes out as a POST with the archive as its body; a read as a GET with none.
     *
     * @return void
     */
    public function testTheMethodComesFromTheActionAndTheBodyFollowsIt(): void
    {
        $push = $this->build(UpdateAction::Patch, 'the archive', ['apply' => true, 'mirror' => false]);
        $read = $this->build(UpdateAction::Version, '', []);

        self::assertSame(HttpMethod::Post, $push->method);
        self::assertTrue($push->hasBody());
        self::assertSame('the archive', $push->body());

        self::assertSame(HttpMethod::Get, $read->method);
        self::assertFalse($read->hasBody());
    }

    /**
     * A key that is not one is an ordinary mistake with an ordinary message.
     *
     * @return void
     */
    public function testAnUnusableKeyIsRefusedWithARecipe(): void
    {
        self::assertTrue($this->keyFile->write('not a key', 0o600));

        $this->expectException(\Phpanta\Tool\Cli\UsageException::class);
        $this->expectExceptionMessageMatches('/not a readable PEM private key/');

        PrivateKey::fromFile($this->keyFile);
    }

    /**
     * A key other users can read is refused before it signs anything, the way ssh refuses an
     * unprotected identity.
     *
     * @return void
     */
    public function testAKeyOtherUsersCanReadIsRefused(): void
    {
        self::assertTrue($this->keyFile->write((string) $this->keyFile->read(), 0o644));

        $this->expectException(\Phpanta\Tool\Cli\UsageException::class);
        $this->expectExceptionMessageMatches('/can be read by other users \(mode 644\)/');

        PrivateKey::fromFile($this->keyFile);
    }

    /**
     * A key file that is not there names itself and the command that makes one.
     *
     * @return void
     */
    public function testAMissingKeyNamesThePathAndTheRecipe(): void
    {
        $this->expectException(\Phpanta\Tool\Cli\UsageException::class);
        $this->expectExceptionMessageMatches('/ec_paramgen_curve:P-256/');

        PrivateKey::fromFile(new File($this->sandbox . '/nothing-here.key'));
    }

    /**
     * A transport sees exactly one request, and it is the signed one.
     *
     * @return void
     */
    public function testTheTransportIsHandedTheSignedRequest(): void
    {
        /** @var ArrayObject<int, Request> $sent */
        $sent      = new ArrayObject();
        $transport = new class ($sent) implements Transport {
            /** @param ArrayObject<int, Request> $sent */
            public function __construct(private ArrayObject $sent) {}

            /**
             * @param Request $request
             * @return Response
             */
            public function send(Request $request): Response
            {
                $this->sent->append($request);

                return new Response(200, "serial -\n");
            }
        };

        $response = $transport->send($this->build(UpdateAction::Version, '', []));

        self::assertTrue($response->isOk());
        self::assertCount(1, $sent);
        self::assertNotNull($sent[0]->header(OutboundHeader::Authorization));
    }

    /**
     * One signed request, built the way a command builds it.
     *
     * @param UpdateAction $action
     * @param string $body
     * @param array<string, bool> $fields
     * @return Request
     */
    private function build(UpdateAction $action, string $body, array $fields): Request
    {
        return SignedRequest::build(
            new Url('https://example.test'),
            ApiService::Update,
            ApiVersion::V1,
            $action,
            $body,
            $fields,
            PrivateKey::fromFile($this->keyFile),
        );
    }

    /**
     * What the real gate makes of a request the real client built.
     *
     * The outbound request is turned back into an inbound one through {@link TestRequest} — that
     * translation is what an HTTP round trip would do, and doing it here is what lets this run
     * without a server. What is *not* restated is the format: the header value and the body go
     * across untouched.
     *
     * @param Request $request
     * @return VerifiedRequest|null
     */
    private function verify(Request $request): ?VerifiedRequest
    {
        $credential = $request->header(OutboundHeader::Authorization);
        self::assertNotNull($credential);

        $inbound = TestRequest::to($request->method, Uri::parse($request->url->render())?->getPath() ?? '')
            ->withServer(ServerVariable::Authorization, $credential->value->render())
            ->withBody($request->hasBody() ? $request->body() : '')
            ->request();

        return new ApiGate($this->publicKeyFile, $this->serialFile)->accepts($inbound);
    }
}
