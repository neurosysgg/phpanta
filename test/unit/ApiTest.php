<?php

declare(strict_types=1);

namespace Phpanta\Test\Unit;

use OpenSSLAsymmetricKey;
use Phpanta\App;
use Phpanta\Controller\ApiController;
use Phpanta\Controller\UnroutedController;
use Phpanta\Http\AcceptedTypes;
use Phpanta\Http\Allow;
use Phpanta\Http\Answer;
use Phpanta\Http\Api\ApiListing;
use Phpanta\Http\Api\ApiResult;
use Phpanta\Http\Api\ApiService;
use Phpanta\Http\Api\ApiVersion;
use Phpanta\Http\Api\CapabilityAction;
use Phpanta\Http\Api\HealthAction;
use Phpanta\Http\Api\ListingEntry;
use Phpanta\Http\Api\UpdateAction;
use Phpanta\Http\AuthScheme;
use Phpanta\Http\Header;
use Phpanta\Http\HttpMethod;
use Phpanta\Http\HttpStatusCode;
use Phpanta\Http\JsonResponse;
use Phpanta\Http\MediaRange;
use Phpanta\Http\PlainTextResponse;
use Phpanta\Http\RedirectResponse;
use Phpanta\Http\Representation;
use Phpanta\Http\Request;
use Phpanta\Http\RequestHeader;
use Phpanta\Http\ResponseHeader;
use Phpanta\Http\RobotsPolicy;
use Phpanta\Http\ServerVariable;
use Phpanta\Http\SignedChallenge;
use Phpanta\Http\TextBody;
use Phpanta\Http\ViewResponse;
use Phpanta\Model\Api\ApiCredential;
use Phpanta\Model\Api\ApiEnvelope;
use Phpanta\Model\Api\SerialRefusal;
use Phpanta\Model\Api\VerifiedRequest;
use Phpanta\Model\Health\HealthFact;
use Phpanta\Model\Health\HealthSection;
use Phpanta\Model\Update\ApplyManifest;
use Phpanta\Model\Update\Deployment;
use Phpanta\Router;
use Phpanta\Service\Api\HealthCheck;
use Phpanta\Service\Api\UpdatePatch;
use Phpanta\Service\Api\UpdateRollback;
use Phpanta\Service\Api\UpdateVersion;
use Phpanta\Service\ApiGate;
use Phpanta\Service\ReleaseRecord;
use Phpanta\Service\UpdateApplier;
use Phpanta\Support\AdminPath;
use Phpanta\Support\Collection;
use Phpanta\Support\Directory;
use Phpanta\Support\DropPath;
use Phpanta\Support\File;
use Phpanta\Support\FileLock;
use Phpanta\Support\MethodPolicy;
use Phpanta\Support\PublicKey;
use Phpanta\Support\RequirementInitialization;
use Phpanta\Support\Route;
use Phpanta\Test\TestRequest;
use Phpanta\Text\AdminText;
use Phpanta\Text\Language;
use Phpanta\Tool\Api\ResultReader;
use Phpanta\View\AdminEntranceView;
use Phpanta\View\ApiListingView;
use Phpanta\View\ApiResultView;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use stdClass;

/**
 * `/admin`: what it tells a stranger, what it proves of a caller, and what it answers one it can
 * verify.
 *
 * **The half of the update feature at the door**, split from {@link UpdateTest} when `/update` became
 * one address in a family. {@link ApiGate} refuses without saying why, so a refusal here is asserted
 * as a *response* rather than a message. Past the signature the posture inverts and the sentence is
 * the assertion, which is what the other file is about.
 *
 * Three properties are worth naming, because everything here is one of them:
 *
 * - **Uniformity.** Below the entrance, a caller the gate cannot verify gets one answer at every
 *   depth, under every verb, whether the address exists or not: a page request is sent to the
 *   entrance, a request for data is challenged. So a stranger learns there is an admin and nothing
 *   about what is in it.
 * - **Binding.** A credential authenticates one request — one method, one path, one body — and not
 *   merely *some* request. That is what stops a read's credential being replayed as a write, and it
 *   is the property the framed-body predecessor did not have.
 * - **Replay.** A serial is spent by a write and by nothing else.
 *
 * **{@link UpdatePatch} and {@link Allow} are named below although neither is this file's subject**,
 * and that is the `#[CoversClass]` trap rather than untidiness: a test class declaring any of these
 * records coverage for *only* the classes it names, so `UpdatePatch::isWrite()` and `Allow::of()`
 * read as 0% while being called on every controller test here. Both were caught exactly that way.
 */
#[CoversClass(ApiController::class)]
#[CoversClass(UnroutedController::class)]
#[CoversClass(ApiGate::class)]
#[CoversClass(ApiCredential::class)]
#[CoversClass(ApiEnvelope::class)]
#[CoversClass(VerifiedRequest::class)]
#[CoversClass(ApiService::class)]
#[CoversClass(ApiVersion::class)]
#[CoversClass(UpdateAction::class)]
#[CoversClass(HealthAction::class)]
#[CoversClass(CapabilityAction::class)]
#[CoversClass(UpdatePatch::class)]
#[CoversClass(UpdateVersion::class)]
#[CoversClass(UpdateRollback::class)]
#[CoversClass(ApplyManifest::class)]
#[CoversClass(UpdateApplier::class)]
#[CoversClass(ReleaseRecord::class)]
#[CoversClass(Deployment::class)]
#[CoversClass(Allow::class)]
#[CoversClass(PublicKey::class)]
#[CoversClass(FileLock::class)]
#[CoversClass(SerialRefusal::class)]
#[CoversClass(PlainTextResponse::class)]
#[CoversClass(Answer::class)]
#[CoversClass(TextBody::class)]
#[CoversClass(ApiResult::class)]
#[CoversClass(ApiResultView::class)]
#[CoversClass(JsonResponse::class)]
#[CoversClass(ViewResponse::class)]
#[CoversClass(AcceptedTypes::class)]
#[CoversClass(MediaRange::class)]
#[CoversClass(Representation::class)]
#[CoversClass(HealthSection::class)]
#[CoversClass(HealthFact::class)]
#[CoversClass(SignedChallenge::class)]
#[CoversClass(RedirectResponse::class)]
#[CoversClass(ApiListing::class)]
#[CoversClass(ListingEntry::class)]
#[CoversClass(ApiListingView::class)]
#[CoversClass(AdminEntranceView::class)]
final class ApiTest extends TestCase
{
    /**
     * Every depth below the entrance, of every service, real and not — what a stranger's every
     * request has to be answered alike at.
     */
    private const array DEPTHS = [
        '/admin/update',
        '/admin/update/v1',
        self::PATCH,
        '/admin/update/v1/nope',
        '/admin/update/v9',
        '/admin/health',
        '/admin/health/v1',
        self::HEALTH,
        '/admin/capability/v1',
        self::CAPABILITY,
        '/admin/nope',
        '/admin/nope/v1',
        '/admin/nope/v1/nope',
        '/admin/update/v1/patch/extra',
        '/admin/nope/v1/nope/a/b',
        '/admin/machine',
        '/admin/machine/v1',
        '/admin/machine/v1/files/etc/passwd',
    ];

    private const string PATCH      = '/admin/update/v1/patch';
    private const string VERSION    = '/admin/update/v1/version';
    private const string ROLLBACK   = '/admin/update/v1/rollback';
    private const string PROBE      = '/admin/update/v1/probe';
    private const string HEALTH     = '/admin/health/v1/report';
    private const string CAPABILITY = '/admin/capability/v1/extensions';

    private string $sandbox = '';
    private OpenSSLAsymmetricKey $privateKey;
    private File $keyFile;
    private File $serialFile;

    /**
     * A fresh keypair and a sandbox deployment per test.
     *
     * The key is generated rather than checked in, so the suite proves the whole chain end to end —
     * generate, sign, verify — rather than only the verifying half against a fixture whose origin
     * nothing states.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        self::assertNotFalse($key, 'this host cannot generate an EC key, so nothing below is meaningful');
        $this->privateKey = $key;

        $this->sandbox = sys_get_temp_dir() . '/phpanta-api-' . bin2hex(random_bytes(6));
        new Directory($this->sandbox)->create();

        $this->keyFile    = new File($this->sandbox . '/update.pub');
        $this->serialFile = new File($this->sandbox . '/.update-serial');

        $details = openssl_pkey_get_details($this->privateKey);
        self::assertIsArray($details);
        self::assertTrue($this->keyFile->write((string) $details['key']));
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

    // ───────────────────────────── the stranger ─────────────────────────────

    /**
     * Below the entrance, a caller the gate cannot verify gets one answer at every depth, under
     * every verb, whether the address exists or not: a page request is sent to the entrance, and a
     * request for data is challenged for a signature. Neither names a method.
     *
     * The depths matter as much as the verbs, and so does `nope` — an address that looks exactly like
     * a real one and is not. What a stranger may learn is that there is an admin; which services,
     * versions and actions are under it is what this holds back.
     *
     * @param string $method
     * @return void
     */
    #[DataProvider('everyMethodProvider')]
    public function testEveryDepthBelowTheEntranceAnswersAStrangerAlike(string $method): void
    {
        $router = new Router(App::current()->routeTable());

        foreach (self::DEPTHS as $path) {
            $asked = self::request($method, $path);
            $page  = $router->dispatch($asked)->answer($asked);
            $wants = self::request($method, $path, '', null, 'application/json');
            $data  = $router->dispatch($wants)->answer($wants);

            self::assertSame(HttpStatusCode::SeeOther, $page->status(), "$method $path");
            self::assertSame('/admin', $page->header(ResponseHeader::Location)?->value->render(), "$method $path");
            self::assertSame(HttpStatusCode::Unauthorized, $data->status(), "$method $path");
            self::assertSame('NS1', $data->header(ResponseHeader::WwwAuthenticate)?->value->render(), "$method $path");
            self::assertNull($page->header(ResponseHeader::Allow), "$method $path named a method");
            self::assertNull($data->header(ResponseHeader::Allow), "$method $path named a method");
        }
    }

    /**
     * The entrance is the one admin page anybody may see: a read of it is the page, kept by no
     * cache and not to be indexed, and anything else is sent back to it.
     *
     * @return void
     */
    public function testTheEntranceIsAPageForAnyone(): void
    {
        $page = TestRequest::get('/admin')->answer();
        $body = $page->body();

        self::assertSame(HttpStatusCode::Ok, $page->status());
        self::assertTrue(
            str_contains($body, AdminText::Entrance->in(Language::English))
            || str_contains($body, AdminText::Entrance->in(Language::German)),
            'the entrance does not say what it is',
        );
        self::assertSame('no-store, private', $page->header(ResponseHeader::CacheControl)?->value->render());
        self::assertSame(RobotsPolicy::hide()->render(), $page->header(ResponseHeader::Robots)?->value->render());
        self::assertSame(HttpStatusCode::Ok, TestRequest::to('HEAD', '/admin')->answer()->status());
        self::assertSame(HttpStatusCode::SeeOther, TestRequest::to('POST', '/admin')->answer()->status());
    }

    /**
     * A stranger naming only types the admin cannot write is told so, the same at every depth —
     * existence is not the question a `406` answers.
     *
     * @return void
     */
    public function testAStrangerAskingForAnotherTypeIsTold(): void
    {
        foreach (['/admin', self::VERSION, '/admin/nope'] as $path) {
            $answer = TestRequest::get($path)->with(RequestHeader::Accept, 'text/plain')->answer();

            self::assertSame(HttpStatusCode::NotAcceptable, $answer->status(), $path);
        }
    }

    /**
     * What used to be the API is an address that is not there: no route claims anything under
     * `/api`, so it answers exactly as `/no-such-page` does, under every verb.
     *
     * @param string $method
     * @return void
     */
    #[DataProvider('everyMethodProvider')]
    public function testTheOldApiIsAnAddressThatIsNotThere(string $method): void
    {
        $router  = new Router(App::current()->routeTable());
        $nowhere = self::request($method, '/no-such-page');
        $absent  = $router->dispatch($nowhere)->answer($nowhere);

        foreach (['/api', '/api/update/v1/patch', '/api/health/v1/report'] as $path) {
            $request = self::request($method, $path);

            $answer  = $router->dispatch($request)->answer($request);

            self::assertSame($absent->status(), $answer->status(), "$method $path");
        }
    }

    /**
     * Every method the framework recognises, plus one it does not.
     *
     * **The unrecognised verb is the row that matters**, and it is here rather than in a test of its
     * own because it is the same claim. {@link Request::method()} is null for it,
     * {@link MethodPolicy::Delegated} lets null reach the controller on purpose, and a gate that
     * asked `$method->value` without checking would raise an uncaught `TypeError` — a 500 where
     * every other verb is sent to the entrance. One differing status code and the property is gone,
     * to anybody who types `BREW`.
     *
     * @return iterable<string, array{string}>
     */
    public static function everyMethodProvider(): iterable
    {
        foreach (HttpMethod::cases() as $method) {
            yield $method->value => [$method->value];
        }

        yield 'BREW' => ['BREW'];
    }

    /**
     * The framework's routes — `/drop`, then the admin's — are the ones that accept a write method,
     * and the only ones.
     *
     * Asserted over the table an app hands the router, by reflection rather than by reading the
     * registration, because what matters is what the router will do and not what anybody wrote
     * down. The booted app declares no routes of its own, so fixture routes stand in front of the
     * framework's for a site's, registered the way a site registers one. Both directions: another
     * route accepting a write, and another route made `Delegated`, are each a hole. `/drop` is the
     * one delegated route outside the admin, because its controller answers as an address that is not
     * there wherever drops are off — which a method gate naming `POST` would not.
     *
     * @return void
     */
    public function testOnlyTheAdminRoutesAcceptAWriteMethod(): void
    {
        $accepting = [];
        $delegated = [];

        $table = new Collection(Route::class)
            ->with(...new Collection(ExportFixturePath::class)
                ->with(...ExportFixturePath::cases())
                ->map(static fn(ExportFixturePath $path): Route => new Route(
                    $path,
                    static fn(): object => new stdClass(),
                ))
                ->toValues())
            ->with(...App::current()->routeTable()->toValues());

        foreach ($table as $route) {
            $pattern = new ReflectionProperty(Route::class, 'pattern')->getValue($route);

            if ($route->accepts(HttpMethod::Post)) {
                $accepting[] = $pattern;
            }

            if (new ReflectionProperty(Route::class, 'methods')->getValue($route) === MethodPolicy::Delegated) {
                $delegated[] = $pattern;
            }
        }

        $framework = [DropPath::Index, ...AdminPath::cases()];

        self::assertSame($framework, $accepting);
        self::assertSame($framework, $delegated, 'another route stopped being method-gated');
    }

    /**
     * A route with no declared policy is read-only, which is every route but the admin's.
     *
     * @return void
     */
    public function testARouteWithNoDeclaredMethodsIsReadOnly(): void
    {
        $route = new Route(ExportFixturePath::Home, static fn(): object => new stdClass());

        self::assertTrue($route->accepts(HttpMethod::Get));
        self::assertTrue($route->accepts(HttpMethod::Head));
        self::assertFalse($route->accepts(HttpMethod::Post));
        self::assertFalse($route->accepts(null), 'an unrecognised method is not read-only');
    }

    /**
     * A delegated route accepts even a method the framework does not recognise.
     *
     * Deliberate: the controller has to *see* null, because the alternative is the router answering
     * `BREW /admin/…` before the controller could say who is asking.
     *
     * @return void
     */
    public function testADelegatedRouteAcceptsEvenAnUnknownMethod(): void
    {
        $route = new Route(AdminPath::Action, static fn(): object => new stdClass(), MethodPolicy::Delegated);

        self::assertTrue($route->accepts(null));

        foreach (HttpMethod::cases() as $method) {
            self::assertTrue($route->accepts($method), $method->value . ' was refused by the router');
        }
    }

    // ───────────────────────────── the gate ─────────────────────────────

    /**
     * A well-formed signed push is accepted, and hands back what it proved.
     *
     * @return void
     */
    public function testAValidPushIsAccepted(): void
    {
        $archive  = UpdateFixture::archive(['public/a.txt' => 'x']);
        $verified = $this->verdict(self::PATCH, HttpMethod::Post, $archive);

        self::assertInstanceOf(VerifiedRequest::class, $verified);
        self::assertSame($archive, $verified->body);
        self::assertSame(self::PATCH, $verified->envelope->path);
        self::assertSame('POST', $verified->envelope->method);
    }

    /**
     * A read is accepted with no body at all, and binds `sha256('')`.
     *
     * This is the whole reason the credential rides in `Authorization`: there is no body to frame
     * it into. The digest check runs anyway rather than being skipped for a method that "has no body",
     * which is what stops a signed GET smuggling one past it.
     *
     * @return void
     */
    public function testAReadIsAcceptedWithNoBody(): void
    {
        $verified = $this->verdict(self::VERSION, HttpMethod::Get, '');

        self::assertInstanceOf(VerifiedRequest::class, $verified);
        self::assertSame('', $verified->body);
        self::assertSame(0, $verified->envelope->size);
        self::assertSame(hash('sha256', ''), $verified->envelope->digest);
    }

    /**
     * Every way a request can fail to be one of ours, refused identically and in silence.
     *
     * One test rather than one per case, deliberately: the assertion is that they are all the same
     * `null`, and a per-case test would be a place for one of them to start being different.
     *
     * @param string $case
     * @return void
     */
    #[DataProvider('refusedProvider')]
    public function testTheGateRefusesInSilence(string $case): void
    {
        $archive = UpdateFixture::archive(['public/a.txt' => 'x']);
        $blob    = static fn(string $bytes): string => AuthScheme::NS1->value . ' ' . base64_encode($bytes);

        $verdict = match ($case) {
            'no credential'    => $this->push($archive, credential: ''),
            'another scheme'   => $this->push($archive, credential: 'Basic YTpi'),
            'no space'         => $this->push($archive, credential: 'NS1abcd'),
            'not base64'       => $this->push($archive, credential: 'NS1 !!!!'),
            'empty blob'       => $this->push($archive, credential: 'NS1 '),
            'shorter than its length prefix' => $this->push($archive, credential: $blob('ab')),
            'length past the end'  => $this->push($archive, credential: $blob(pack('N', 64) . 'short')),
            'manifest over the cap' => $this->push(
                $archive,
                credential: $blob(pack('N', 4096) . str_repeat('x', 4096)),
            ),
            'signature over the cap' => $this->push(
                $archive,
                credential: $blob(pack('N', 1) . 'x' . str_repeat('s', 512)),
            ),
            'tampered manifest'    => $this->push($archive, tamper: true),
            'signed by another key' => $this->push($archive, signWith: self::otherKey()),
            'stale serial'         => $this->push($archive, serial: time() - 3600),
            'future serial'        => $this->push($archive, serial: time() + 3600),
            'wrong digest'         => $this->push($archive, digest: str_repeat('a', 64)),
            'wrong size'           => $this->push($archive, size: strlen($archive) + 1),
            'a longer body than the envelope claims' => $this->push(
                $archive . 'extra',
                digest: hash('sha256', $archive),
                size: strlen($archive),
            ),
            'an unsigned body on a signed read' => $this->verdict(
                self::VERSION,
                HttpMethod::Get,
                'smuggled',
                digest: hash('sha256', ''),
                size: 0,
            ),
            'a size over the cap'  => $this->push($archive, size: 9_000_000),
            'an unrecognised verb' => $this->verdict(self::PATCH, 'BREW', $archive),
        };

        self::assertNull($verdict, $case . ' was let through');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function refusedProvider(): iterable
    {
        $cases = [
            'no credential',
            'another scheme',
            'no space',
            'not base64',
            'empty blob',
            'shorter than its length prefix',
            'length past the end',
            'manifest over the cap',
            'signature over the cap',
            'tampered manifest',
            'signed by another key',
            'stale serial',
            'future serial',
            'wrong digest',
            'wrong size',
            'a longer body than the envelope claims',
            'an unsigned body on a signed read',
            'a size over the cap',
            'an unrecognised verb',
        ];

        foreach ($cases as $case) {
            yield $case => [$case];
        }
    }

    /**
     * A credential minted for one request does not authenticate another.
     *
     * **The property the framed-body predecessor did not have.** Its manifest named no target, so a
     * credential authenticated *some* request rather than one — which was sound while `/update` was
     * the only address there was to sign for, and stops being sound the moment there are two.
     *
     * @param string $signedPath
     * @param string $signedMethod
     * @param string $sentPath
     * @param string $sentMethod
     * @return void
     */
    #[DataProvider('mismatchedBindingProvider')]
    public function testACredentialBindsTheRequestItWasMintedFor(
        string $signedPath,
        string $signedMethod,
        string $sentPath,
        string $sentMethod,
    ): void {
        self::assertNull($this->verdict(
            $sentPath,
            $sentMethod,
            '',
            signedPath: $signedPath,
            signedMethod: $signedMethod,
        ));
    }

    /**
     * @return iterable<string, array{string, string, string, string}>
     */
    public static function mismatchedBindingProvider(): iterable
    {
        yield 'a read replayed as a write' => [self::VERSION, 'GET', self::VERSION, 'POST'];
        yield 'one action replayed at another' => [self::VERSION, 'GET', self::PATCH, 'GET'];
        yield 'a percent-encoded path' => [self::VERSION, 'GET', '/admin/update/v1/%76ersion', 'GET'];
        yield 'another service' => [self::VERSION, 'GET', '/admin/other/v1/version', 'GET'];
    }

    /**
     * A trailing slash is the same signed path, because `Request::path()` takes it off.
     *
     * Not a hole — it is the same route, the same action and the same bytes — but it is the kind of
     * thing that should be written down rather than discovered, since the signature commits to the
     * *normalised* path and not to the wire target.
     *
     * @return void
     */
    public function testATrailingSlashIsTheSameSignedPath(): void
    {
        self::assertInstanceOf(
            VerifiedRequest::class,
            $this->verdict(self::VERSION . '/', HttpMethod::Get, '', signedPath: self::VERSION),
        );
    }

    /**
     * With no key file, everything is refused — which is the off switch.
     *
     * @return void
     */
    public function testWithNoKeyFileEveryPushIsRefused(): void
    {
        self::assertTrue($this->keyFile->delete());
        self::assertNull($this->verdict(self::PATCH, HttpMethod::Post, UpdateFixture::archive([])));
    }

    /**
     * A key file that is not a usable key refuses everything, the same way an absent one does.
     *
     * @return void
     */
    public function testAnUnusableKeyFileRefusesEverything(): void
    {
        self::assertTrue($this->keyFile->write('not a key'));
        self::assertNull($this->verdict(self::PATCH, HttpMethod::Post, UpdateFixture::archive([])));
    }

    /**
     * A serial is accepted once, and everything at or below it afterwards is refused.
     *
     * @return void
     */
    public function testASerialIsAcceptedOnlyOnce(): void
    {
        $serial = time();
        $spent  = $this->gate()->spend($serial);
        self::assertInstanceOf(FileLock::class, $spent);
        $spent->release();

        self::assertNull($this->verdict(self::VERSION, HttpMethod::Get, '', serial: $serial));
        self::assertNull($this->verdict(self::VERSION, HttpMethod::Get, '', serial: $serial - 1));
        self::assertInstanceOf(
            VerifiedRequest::class,
            $this->verdict(self::VERSION, HttpMethod::Get, '', serial: $serial + 1),
        );
    }

    /**
     * A write that arrives while another holds the lock runs nothing, spends nothing, and says so.
     *
     * Two writes in flight at once would each write and mirror over the other — deleting the files
     * the other had just written — so the second is refused rather than queued. Past the signature,
     * so the refusal is a sentence rather than the decoy.
     *
     * @return void
     */
    public function testAWriteWhileAnotherHoldsTheLockIsRefused(): void
    {
        $held = FileLock::exclusive(new File($this->serialFile->path . '.lock'));
        self::assertInstanceOf(FileLock::class, $held);

        try {
            $response = $this->respond(
                self::PATCH,
                HttpMethod::Post,
                UpdateFixture::archive(['public/written.txt' => 'x']),
            );

            self::assertSame(HttpStatusCode::Conflict, $response->status());
            self::assertStringContainsString('another write is in progress', self::text($response));
            self::assertNull($this->serialFile->read(), 'a refused write spent its serial');
            self::assertNull(new File($this->sandbox . '/public/written.txt')->read(), 'a refused write wrote');
        } finally {
            $held->release();
        }
    }

    /**
     * A serial overtaken between the gate's first look and the lock is refused under the lock.
     *
     * The check that keeps two overlapping writes from moving the record *backwards*: without it,
     * both pass the gate, the older one's record lands last, and the newer credential can be
     * replayed until its serial goes stale.
     *
     * @return void
     */
    public function testASerialOvertakenBeforeTheLockIsRefused(): void
    {
        $serial = time();
        $gate   = $this->gate();

        $newer = $gate->spend($serial + 1);
        self::assertInstanceOf(FileLock::class, $newer);
        $newer->release();

        self::assertSame(SerialRefusal::Stale, $gate->spend($serial));
        self::assertSame(
            $serial + 1,
            (int) trim((string) $this->serialFile->read()),
            'the record moved backwards',
        );
    }

    /**
     * A write whose serial is further ahead of this clock than rounding explains is refused rather than
     * recorded: in the record, it would refuse every correctly timed call until time caught up.
     *
     * @return void
     */
    public function testASerialAheadOfTheClockIsNotRecorded(): void
    {
        self::assertSame(SerialRefusal::Ahead, $this->gate()->spend(time() + 60));
        self::assertNull($this->serialFile->read(), 'a serial from the future was recorded');
        self::assertSame(HttpStatusCode::Conflict, SerialRefusal::Ahead->status());
        self::assertStringContainsString('clock is ahead', SerialRefusal::Ahead->message());
    }

    /**
     * A lock file that cannot be opened is no lock, answered the way a held one is.
     *
     * @return void
     */
    public function testALockFileThatCannotBeOpenedIsNoLock(): void
    {
        self::assertNull(FileLock::exclusive(new File($this->sandbox . '/no-such-directory/x.lock')));
    }

    /**
     * Releasing twice is releasing once, and a released lock can be taken again.
     *
     * @return void
     */
    public function testALockReleasedTwiceIsReleasedOnce(): void
    {
        $file = new File($this->sandbox . '/twice.lock');

        $lock = FileLock::exclusive($file);
        self::assertInstanceOf(FileLock::class, $lock);
        self::assertNull(FileLock::exclusive($file), 'a held lock was taken a second time');

        $lock->release();
        $lock->release();

        $again = FileLock::exclusive($file);
        self::assertInstanceOf(FileLock::class, $again);
        $again->release();
    }

    // ───────────────────────────── the envelope ─────────────────────────────

    /**
     * Every field required, every type exact, no defaults and no coercions.
     *
     * @param string $json
     * @return void
     */
    #[DataProvider('badEnvelopeProvider')]
    public function testAMalformedEnvelopeIsRefused(string $json): void
    {
        $this->expectException(\Phpanta\Exception\ApiException::class);

        ApiEnvelope::parse($json);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function badEnvelopeProvider(): iterable
    {
        $digest = str_repeat('a', 64);
        $full   = ['serial' => 1, 'method' => 'GET', 'path' => '/admin/update/v1/version',
                   'digest' => $digest, 'size' => 0];

        yield 'not JSON'      => ['{'];
        yield 'not an object' => ['"a string"'];

        foreach (array_keys($full) as $missing) {
            $without = $full;
            unset($without[$missing]);
            yield 'no ' . $missing => [(string) json_encode($without)];
        }

        yield 'serial as string'  => [(string) json_encode([...$full, 'serial' => '1'])];
        yield 'size as string'    => [(string) json_encode([...$full, 'size' => '0'])];
        yield 'method as int'     => [(string) json_encode([...$full, 'method' => 1])];
        yield 'path as int'       => [(string) json_encode([...$full, 'path' => 1])];
        yield 'digest not a hash' => [(string) json_encode([...$full, 'digest' => 'nope'])];
        yield 'digest uppercase'  => [(string) json_encode([...$full, 'digest' => strtoupper($digest)])];
        yield 'negative size'     => [(string) json_encode([...$full, 'size' => -1])];
    }

    // ───────────────────────────── past the gate ─────────────────────────────

    /**
     * A verified request reaches the handler its address names.
     *
     * **Both actions, and neither reaches a real deployment**, which is the boundary this file
     * stops at rather than a gap. {@link \Phpanta\Service\UpdateApplier} takes its
     * {@link Deployment} as a constructor argument precisely so a test cannot reach the live tree,
     * and {@link \Phpanta\Http\Api\ApiAction::handler()} has nowhere to pass one — so a push
     * through the controller resolves the real webroot, and on a CLI run there is none. Adding a
     * seam for it would put an update-specific parameter on the general controller.
     *
     * So the chain is tested a link at a time: the gate above, resolution below, this for the
     * delegation, and {@link UpdateTest} for {@link \Phpanta\Service\Api\UpdatePatch} against a
     * sandboxed applier. What proves the patch handler was built from the signed manifest and then
     * run is that its refusal comes back — see the 422 below.
     *
     * @return void
     */
    public function testAVerifiedRequestReachesTheHandlerItsAddressNames(): void
    {
        $version = $this->respond(self::VERSION, HttpMethod::Get, '');

        self::assertSame(HttpStatusCode::Ok, $version->status());
        self::assertStringContainsString(PHP_VERSION, self::text($version));

        // The other services through the same controller, which is what says the delegation is the
        // address's rather than the update service's. What each answers is HealthTest's and
        // CapabilityTest's subject; that these paths reach them at all is this one's.
        //
        // The health check's status is whatever the check itself comes to on this machine — a CLI
        // run has no webroot, so the declared set is a 503 here and a 200 on the live host — and
        // the controller has to pass it through untouched either way.
        $health = $this->respond(self::HEALTH, HttpMethod::Get, '');
        $direct = new HealthCheck(RequirementInitialization::requirements(App::current()))->handle();

        self::assertSame(UpdateFixture::statusOf($direct), $health->status());
        self::assertStringContainsString("extensions\n", self::text($health));

        $capability = $this->respond(self::CAPABILITY, HttpMethod::Get, '');

        self::assertSame(HttpStatusCode::Ok, $capability->status());
        self::assertStringContainsString("zend extensions\n", self::text($capability));
    }

    /**
     * A verified push advances the serial; a dry run and a read leave it exactly where it was.
     *
     * @return void
     */
    public function testOnlyAWriteConsumesTheSerial(): void
    {
        self::assertNull($this->serialFile->read(), 'the sandbox started with a serial');

        (void) $this->respond(self::VERSION, HttpMethod::Get, '');
        self::assertNull($this->serialFile->read(), 'a read spent a serial');

        (void) $this->respond(self::PATCH, HttpMethod::Post, 'not gzip', apply: false);
        self::assertNull($this->serialFile->read(), 'a dry run spent a serial');

        // A write spends its serial whatever becomes of it. The archive here will not expand, so
        // the push fails — and the serial still moves, because the rule is about what the caller
        // asked for and not about what came of it. Bytes that produced a failed push must never be
        // accepted a second time.
        $serial = time();
        (void) $this->respond(self::PATCH, HttpMethod::Post, 'not gzip', serial: $serial);
        self::assertSame($serial, (int) trim((string) $this->serialFile->read()));
    }

    /**
     * A serial that cannot be recorded is a 500, and **nothing is written**.
     *
     * The replay guard is armed before the action runs rather than after it, so a push can never
     * write the whole tree and only then discover it cannot arm the guard — leaving a deployment
     * updated and a credential that could update it again. See docs/history/api.md.
     *
     * @return void
     */
    public function testASerialThatCannotBeRecordedWritesNothing(): void
    {
        // A directory where the record belongs: File::write() renames onto its target, and a rename
        // over a non-empty directory cannot succeed.
        $blocked = new Directory($this->sandbox . '/blocked-serial');
        self::assertTrue($blocked->create());
        self::assertTrue($blocked->file('inside')->write('x'));

        $response = $this->respond(
            self::PATCH,
            HttpMethod::Post,
            UpdateFixture::archive(['public/written.txt' => 'x']),
            serialFile: new File($blocked->path),
        );

        self::assertSame(HttpStatusCode::InternalServerError, $response->status());
        self::assertStringContainsString('could not be recorded', self::text($response));
        self::assertNull(
            new File($this->sandbox . '/public/written.txt')->read(),
            'the push wrote even though the replay guard could not be armed',
        );
    }

    /**
     * An archive that verifies but will not expand is a 422 with a sentence, not the decoy.
     *
     * Past the signature the caller has proved it holds the private key, and there is nothing left
     * to hide from it: the decoy would only be a worse error message. It is also what proves the
     * patch handler was built from the signed manifest and then run, since this sentence is
     * {@link \Phpanta\Service\UpdateApplier}'s.
     *
     * **And the refused payload has spent its serial**, because the guard is armed in front of the
     * action. Nothing is lost by it: a corrected payload is different bytes with a fresh `time()` on
     * them. Spending it first costs a second and is what keeps a push from writing the whole tree
     * before discovering it cannot arm the guard.
     *
     * @return void
     */
    public function testAnArchiveThatWillNotExpandIsAnswered422(): void
    {
        $serial   = time();
        $response = $this->respond(self::PATCH, HttpMethod::Post, 'this is not gzip at all', serial: $serial);

        self::assertSame(HttpStatusCode::UnprocessableContent, $response->status());
        self::assertStringContainsString('refused:', self::text($response));
        self::assertStringContainsString('not gzip', self::text($response));
        self::assertSame(
            $serial,
            (int) trim((string) $this->serialFile->read()),
            'the bytes of a failed push stayed replayable',
        );
    }

    /**
     * A signed rollback reaches its handler through the controller, and is a write in every way the
     * gate cares about.
     *
     * **The deployment is `TestApp`'s fixture**, which is where {@link Deployment::current()}
     * resolves under this suite — {@link \Phpanta\Http\Api\ApiAction::handler()} has nowhere to pass
     * a sandbox, for the reason the test above gives. The fixture holds no record, so every call
     * here ends in the same refusal and none of them can write: which is exactly what lets the
     * controller's half be asserted end to end — the verb, the manifest read out of the signed
     * bytes, the serial spent for a write and not for a dry run, and the refusal's 422. What a
     * rollback does *with* a record is {@link RollbackTest}'s, against a sandbox.
     *
     * @return void
     */
    public function testARollbackIsASignedWriteThatReachesItsHandler(): void
    {
        $previous = $_SERVER['DOCUMENT_ROOT'] ?? null;
        $_SERVER['DOCUMENT_ROOT'] = App::current()->above()->path . '/public';

        try {
            self::assertFalse(
                Deployment::current()->previousRelease()->exists(),
                'the fixture holds a record of a previous release, which this test would roll back',
            );

            $wrongVerb = $this->respond(self::ROLLBACK, HttpMethod::Get, '');
            self::assertSame(HttpStatusCode::MethodNotAllowed, $wrongVerb->status());
            self::assertStringContainsString('rollback answers POST', self::text($wrongVerb));

            $dryRun = $this->respond(self::ROLLBACK, HttpMethod::Post, '', apply: false);
            self::assertSame(HttpStatusCode::UnprocessableContent, $dryRun->status());
            self::assertStringContainsString(
                'refused: there is no previous release to roll back to',
                self::text($dryRun),
            );
            self::assertNull($this->serialFile->read(), 'a rollback dry run spent a serial');

            $serial = time();
            $real   = $this->respond(self::ROLLBACK, HttpMethod::Post, '', serial: $serial);
            self::assertSame(HttpStatusCode::UnprocessableContent, $real->status());
            self::assertStringContainsString('there is no previous release to roll back to', self::text($real));
            self::assertSame(
                $serial,
                (int) trim((string) $this->serialFile->read()),
                'a rollback did not spend its serial',
            );
            self::assertFalse(
                Deployment::current()->previousRelease()->exists(),
                'a refused rollback created a record',
            );
        } finally {
            if ($previous === null) {
                unset($_SERVER['DOCUMENT_ROOT']);
            } else {
                $_SERVER['DOCUMENT_ROOT'] = $previous;
            }
        }
    }

    /**
     * A signed probe reaches its handler through the controller: POST only, a serial spent by a real
     * one and not by a dry run, and the deployment exactly as it was afterwards.
     *
     * The deployment is `TestApp`'s fixture, for the rollback test's reason. The probe really runs
     * there, in a directory it makes beside the fixture's roots and takes away again — which is the
     * property worth asserting through the controller: that nothing is left behind in a deployment
     * the probe was pointed at by nothing but the booted app.
     *
     * @return void
     */
    public function testAProbeIsASignedWriteThatLeavesNothingBehind(): void
    {
        $previous = $_SERVER['DOCUMENT_ROOT'] ?? null;
        $above    = App::current()->above();
        $_SERVER['DOCUMENT_ROOT'] = $above->path . '/public';
        $before   = scandir($above->path);

        try {
            $wrongVerb = $this->respond(self::PROBE, HttpMethod::Get, '');
            self::assertSame(HttpStatusCode::MethodNotAllowed, $wrongVerb->status());

            $dryRun = $this->respond(self::PROBE, HttpMethod::Post, '', apply: false);
            self::assertSame(HttpStatusCode::Ok, $dryRun->status());
            self::assertStringContainsString('dry run', self::text($dryRun));
            self::assertNull($this->serialFile->read(), 'a probe dry run spent a serial');

            $serial = time();
            $real   = $this->respond(self::PROBE, HttpMethod::Post, '', serial: $serial);
            self::assertSame(HttpStatusCode::Ok, $real->status(), self::text($real));
            self::assertMatchesRegularExpression('/^  left behind +nothing$/m', self::text($real));
            self::assertSame(
                $serial,
                (int) trim((string) $this->serialFile->read()),
                'a probe did not spend its serial',
            );
            self::assertSame($before, scandir($above->path), 'the probe left something in the deployment');
        } finally {
            if ($previous === null) {
                unset($_SERVER['DOCUMENT_ROOT']);
            } else {
                $_SERVER['DOCUMENT_ROOT'] = $previous;
            }
        }
    }

    /**
     * An address the vocabulary does not have is a real 404 — but only to the key holder.
     *
     * The three segments collapse to one answer deliberately: the difference between a service that
     * does not exist and an action that does not is of no use to somebody who has already been told
     * the address is wrong.
     *
     * @param string $path
     * @return void
     */
    #[DataProvider('unknownAddressProvider')]
    public function testAVerifiedCallerIsToldWhichAddressDoesNotExist(string $path): void
    {
        $response = $this->respond($path, HttpMethod::Get, '');

        self::assertSame(HttpStatusCode::NotFound, $response->status());
        self::assertStringContainsString('no such API action', self::text($response));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unknownAddressProvider(): iterable
    {
        yield 'no such service'          => ['/admin/nope/v1/version'];
        yield 'no such version'          => ['/admin/update/v9/version'];
        yield 'no such action'           => ['/admin/update/v1/nope'];
        yield 'no such version, health'  => ['/admin/health/v9/report'];
        yield 'no such action, health'   => ['/admin/health/v1/nope'];
        yield 'no such version, capability' => ['/admin/capability/v9/runtime'];
        yield 'no such action, capability'  => ['/admin/capability/v1/nope'];

        // An action of *another* service, which is the row a flat enum of every action the API has
        // would have passed: `patch` names something real, and it names nothing under `health`;
        // `report` is health's, and names nothing under `capability`. See ApiAction, where the
        // argument for an enum per service is made.
        yield 'another service\'s action' => ['/admin/health/v1/patch'];
        yield 'health\'s action, capability' => ['/admin/capability/v1/report'];
    }

    /**
     * A verified request for the wrong verb is a 405 naming the one that would work.
     *
     * Two different questions, and both are needed: the gate has already checked the credential was
     * minted for *this* method, and this asks whether the method is one the action answers on at
     * all. A caller who signs `GET /…/patch` correctly is asking for something that makes no sense.
     *
     * @return void
     */
    public function testAVerifiedCallerAskingForTheWrongVerbIsTold(): void
    {
        $response = $this->respond(self::PATCH, HttpMethod::Get, '');

        self::assertSame(HttpStatusCode::MethodNotAllowed, $response->status());
        self::assertStringContainsString('patch answers POST', self::text($response));
        self::assertSame(
            [
                'Content-Type: application/json',
                'Cache-Control: no-store, private',
                'X-Robots-Tag: ' . RobotsPolicy::hide()->render(),
                'Allow: POST',
                'Vary: Accept',
            ],
            self::lines($response),
        );
    }

    /**
     * A verified caller that names no type — a browser sends its own, curl sends `*∕*` — gets the
     * answer as a page in the app's shell, kept by no cache and varying on `Accept`.
     *
     * @return void
     */
    public function testAVerifiedCallerAskingForNothingInParticularGetsAPage(): void
    {
        foreach (['', '*/*', 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8'] as $accept) {
            $answer = $this->respond(self::HEALTH, HttpMethod::Get, '', accept: $accept);
            $lines  = self::lines($answer);

            self::assertContains('Content-Type: text/html; charset=utf-8', $lines, $accept);
            self::assertContains('Cache-Control: no-store, private', $lines, $accept);
            self::assertStringContainsString('health/v1/report', $answer->body(), $accept);
            self::assertStringContainsString('<table>', $answer->body(), $accept);

            $vary = array_find($lines, static fn(string $line): bool => str_starts_with($line, 'Vary: '));
            self::assertIsString($vary, $accept);
            self::assertStringContainsString('Accept', substr($vary, strlen('Vary: ')), $accept);
        }
    }

    /**
     * A verified caller that asks for JSON gets the result as data, refusals included.
     *
     * @return void
     */
    public function testAVerifiedCallerAskingForJsonGetsData(): void
    {
        $answer = $this->respond('/admin/update/v1/nope', HttpMethod::Get, '');

        self::assertSame(HttpStatusCode::NotFound, $answer->status());
        self::assertSame(
            '{"status":404,"sections":[{"caption":null,"lines":["no such API action: GET update/v1/nope"]}]}',
            $answer->body(),
        );
    }

    /**
     * A verified caller that names only types it cannot have is told so — and **before** the action
     * runs, so a write is never carried out for a caller that then could not be told how it went. The
     * serial is not spent.
     *
     * @return void
     */
    public function testAVerifiedCallerAskingForAnotherTypeIsRefusedBeforeAnythingRuns(): void
    {
        $before = $this->serialFile->read();
        $answer = $this->respond(self::ROLLBACK, HttpMethod::Post, '', accept: 'text/plain');

        self::assertSame(HttpStatusCode::NotAcceptable, $answer->status());
        self::assertSame("this answers text/html or application/json\n", $answer->body());
        self::assertSame(
            [
                'Content-Type: text/plain; charset=utf-8',
                'Cache-Control: no-store, private',
                'X-Robots-Tag: ' . RobotsPolicy::hide()->render(),
                'Vary: Accept',
            ],
            self::lines($answer),
        );
        self::assertSame($before, $this->serialFile->read(), 'a 406 spent a serial');
    }

    /**
     * A verified caller can walk the admin from the top: the entrance lists the services, a service
     * its versions, a version its actions — as data, and as a page that links each.
     *
     * @return void
     */
    public function testAVerifiedCallerCanWalkTheAdminFromTheTop(): void
    {
        $top     = $this->respond('/admin', HttpMethod::Get, '');
        $service = $this->respond('/admin/update', HttpMethod::Get, '');
        $version = $this->respond('/admin/update/v1', HttpMethod::Get, '');
        $page    = $this->respond('/admin/update/v1', HttpMethod::Get, '', accept: '');

        foreach ([$top, $service, $version] as $answer) {
            self::assertSame(HttpStatusCode::Ok, $answer->status());
            self::assertContains('Content-Type: application/json', self::lines($answer));
        }

        self::assertSame(
            ['update', 'health', 'capability', 'access'],
            array_column(self::decoded($top)['entries'], 'name'),
        );
        self::assertSame(['v1'], array_column(self::decoded($service)['entries'], 'name'));
        self::assertSame(
            array_map(static fn(UpdateAction $action): string => $action->value, UpdateAction::cases()),
            array_column(self::decoded($version)['entries'], 'name'),
        );

        self::assertSame(HttpStatusCode::Ok, $page->status());
        self::assertContains('Content-Type: text/html; charset=utf-8', self::lines($page));
        self::assertStringContainsString('<a href="/admin/update/v1/version">version</a>', $page->body());
    }

    /**
     * Past the gate, an address that is not there is a real `404` with a sentence, at every depth,
     * and a listing answers only a read — a `405` naming the read methods.
     *
     * @return void
     */
    public function testAVerifiedCallerIsToldWhatIsNotThereAndThatAListingOnlyLists(): void
    {
        foreach (['/admin/nope', '/admin/nope/v1', '/admin/update/v9'] as $path) {
            $answer = $this->respond($path, HttpMethod::Get, '');

            self::assertSame(HttpStatusCode::NotFound, $answer->status(), $path);
            self::assertSame("no such admin address: $path\n", self::text($answer), $path);
        }

        $post = $this->respond('/admin', HttpMethod::Post, '');

        self::assertSame(HttpStatusCode::MethodNotAllowed, $post->status());
        self::assertSame('GET, HEAD', $post->header(ResponseHeader::Allow)?->value->render());
    }

    /**
     * The version action reports the serial, the build stamp and the PHP version.
     *
     * Asserted against the handler rather than through the controller, because
     * {@link \Phpanta\Http\Api\ApiAction::handler()} has nowhere to pass a serial file — so a
     * `version` through the controller reads the live one, which a test may not depend on. The
     * controller's half of the claim is above: that it delegates here at all.
     *
     * @return void
     */
    public function testTheVersionActionReportsWhatIsDeployed(): void
    {
        self::assertTrue($this->serialFile->write("1757000000\n"));

        $body = UpdateFixture::bodyOf(new UpdateVersion($this->serialFile)->handle());

        self::assertStringContainsString('1757000000', $body);
        self::assertStringContainsString("entry  " . App::current()->buildId() . "\n", $body);
        self::assertStringContainsString(PHP_VERSION, $body);
    }

    /**
     * A deployment that has never accepted a push says so rather than claiming serial zero.
     *
     * @return void
     */
    public function testAnUnpushedDeploymentReportsNoSerial(): void
    {
        $body = UpdateFixture::bodyOf(new UpdateVersion(new File($this->sandbox . '/nothing-here'))->handle());

        self::assertStringContainsString('serial -', $body);
        self::assertStringNotContainsString('serial 0', $body);
    }

    // ───────────────────────────── the key ─────────────────────────────

    /**
     * Only an EC public key is accepted; an RSA one parses perfectly and is refused anyway.
     *
     * @return void
     */
    public function testOnlyAnEcPublicKeyIsAccepted(): void
    {
        self::assertInstanceOf(PublicKey::class, PublicKey::fromPem((string) $this->keyFile->read()));

        $this->expectException(\Phpanta\Exception\UpdateException::class);
        PublicKey::fromPem('not a key at all');
    }

    /**
     * An RSA key is a usable key and the wrong one, so it is refused by type rather than by parse.
     *
     * @return void
     */
    public function testAnRsaKeyIsRefused(): void
    {
        $rsa = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]);
        self::assertNotFalse($rsa);

        $details = openssl_pkey_get_details($rsa);
        self::assertIsArray($details);

        $this->expectException(\Phpanta\Exception\UpdateException::class);
        PublicKey::fromPem((string) $details['key']);
    }

    /**
     * An EC key on another curve is refused too: P-384 verifies a SHA-256 signature just as happily,
     * so accepting it would widen the algorithm without anybody having decided to.
     *
     * @return void
     */
    public function testAnEcKeyOnAnotherCurveIsRefused(): void
    {
        $p384 = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'secp384r1']);
        self::assertNotFalse($p384);

        $details = openssl_pkey_get_details($p384);
        self::assertIsArray($details);

        $this->expectException(\Phpanta\Exception\UpdateException::class);
        $this->expectExceptionMessage('not P-256');
        PublicKey::fromPem((string) $details['key']);
    }

    /**
     * A key verifies what it signed and nothing else.
     *
     * @return void
     */
    public function testTheKeyVerifiesOnlyWhatItSigned(): void
    {
        $signature = null;
        openssl_sign('the bytes', $signature, $this->privateKey, OPENSSL_ALGO_SHA256);

        $key = PublicKey::fromPem((string) $this->keyFile->read());

        self::assertTrue($key->verifies('the bytes', (string) $signature));
        self::assertFalse($key->verifies('other bytes', (string) $signature));
        self::assertFalse($key->verifies('the bytes', 'not a signature'));
    }

    // ───────────────────────────── fixtures ─────────────────────────────

    /**
     * @return ApiGate
     */
    private function gate(): ApiGate
    {
        return new ApiGate($this->keyFile, $this->serialFile);
    }

    /**
     * A signed credential, with every knob a test might want to turn wrong.
     *
     * @param string $path What the manifest claims the path is.
     * @param string $method What it claims the method is.
     * @param string $body The body it vouches for.
     * @param bool $tamper Flip a byte of the manifest after signing.
     * @param OpenSSLAsymmetricKey|null $signWith
     * @param int|null $serial
     * @param string|null $digest
     * @param int|null $size
     * @param array<string, bool> $fields The action's own manifest fields.
     * @return string The whole `Authorization` value.
     */
    private function credential(
        string $path,
        string $method,
        string $body,
        bool $tamper = false,
        ?OpenSSLAsymmetricKey $signWith = null,
        ?int $serial = null,
        ?string $digest = null,
        ?int $size = null,
        array $fields = [],
    ): string {
        $manifest = json_encode([
            'serial' => $serial ?? time(),
            'method' => $method,
            'path'   => $path,
            'digest' => $digest ?? hash('sha256', $body),
            'size'   => $size ?? strlen($body),
            ...$fields,
        ], JSON_THROW_ON_ERROR);

        $signature = null;
        openssl_sign($manifest, $signature, $signWith ?? $this->privateKey, OPENSSL_ALGO_SHA256);

        if ($tamper) {
            $manifest[10] = $manifest[10] === '0' ? '1' : '0';
        }

        return AuthScheme::NS1->value . ' ' . base64_encode(
            pack('N', strlen($manifest)) . $manifest . (string) $signature,
        );
    }

    /**
     * A POST of $archive to the patch endpoint, with every knob a refusal test might turn wrong.
     *
     * Sugar over {@link self::verdict()} for the one address most of these are about, so a row in
     * the match above reads as the thing it is varying and nothing else.
     *
     * @param string $archive
     * @param string|null $credential
     * @param bool $tamper
     * @param OpenSSLAsymmetricKey|null $signWith
     * @param int|null $serial
     * @param string|null $digest
     * @param int|null $size
     * @return VerifiedRequest|null
     */
    private function push(
        string $archive,
        ?string $credential = null,
        bool $tamper = false,
        ?OpenSSLAsymmetricKey $signWith = null,
        ?int $serial = null,
        ?string $digest = null,
        ?int $size = null,
    ): ?VerifiedRequest {
        return $this->verdict(
            self::PATCH,
            HttpMethod::Post,
            $archive,
            credential: $credential,
            tamper: $tamper,
            signWith: $signWith,
            serial: $serial,
            digest: $digest,
            size: $size,
        );
    }

    /**
     * What the gate makes of a request for $path carrying $body.
     *
     * @param string $path The path the request is *sent* to.
     * @param HttpMethod|string $method The method it is sent with.
     * @param string $body
     * @param string|null $credential An `Authorization` value to send instead of a correct one.
     * @param string|null $signedPath What the credential claims, when it must differ from $path.
     * @param string|null $signedMethod Same, for the method.
     * @param bool $tamper
     * @param OpenSSLAsymmetricKey|null $signWith
     * @param int|null $serial
     * @param string|null $digest
     * @param int|null $size
     * @return VerifiedRequest|null
     */
    private function verdict(
        string $path,
        HttpMethod|string $method,
        string $body,
        ?string $credential = null,
        ?string $signedPath = null,
        ?string $signedMethod = null,
        bool $tamper = false,
        ?OpenSSLAsymmetricKey $signWith = null,
        ?int $serial = null,
        ?string $digest = null,
        ?int $size = null,
    ): ?VerifiedRequest {
        $verb = $method instanceof HttpMethod ? $method->value : $method;

        $credential ??= $this->credential(
            $signedPath ?? $path,
            $signedMethod ?? $verb,
            $body,
            $tamper,
            $signWith,
            $serial,
            $digest,
            $size,
        );

        return $this->gate()->accepts(self::request($verb, $path, $credential, $body));
    }

    /**
     * The whole endpoint, end to end: a real request, a real gate over the sandbox's key and
     * serial, and an applier that can reach nothing but the sandbox.
     *
     * @param string $path
     * @param HttpMethod $method
     * @param string $body
     * @param bool $apply
     * @param int|null $serial
     * @param File|null $serialFile
     * @param string $accept What the request says it reads — data unless a test says otherwise,
     *                       since data is what the signing CLI asks for. `''` sends no `Accept`.
     * @return Answer What the controller's response answers the request with.
     */
    private function respond(
        string $path,
        HttpMethod $method,
        string $body,
        bool $apply = true,
        ?int $serial = null,
        ?File $serialFile = null,
        string $accept = 'application/json',
    ): Answer {
        $segments = explode('/', ltrim($path, '/'));

        $controller = new ApiController(
            $segments[1] ?? null,
            $segments[2] ?? null,
            $segments[3] ?? null,
            new ApiGate($this->keyFile, $serialFile ?? $this->serialFile),
        );

        $request = self::request($method->value, $path, $this->credential(
            $path,
            $method->value,
            $body,
            serial: $serial,
            fields: $method === HttpMethod::Post ? ['apply' => $apply, 'mirror' => false] : [],
        ), $body, $accept);

        return $controller->handle($request)->answer($request);
    }

    /**
     * A request for $path, arrived by $method, carrying $credential and $body.
     *
     * Built by {@link TestRequest}, so the credential arrives the way a server hands it over and the
     * body is the one {@link Request::body()} answers — nothing written into `$_SERVER`, and no
     * stream wrapper standing in for `php://input`.
     *
     * @param string      $method
     * @param string      $path
     * @param string      $credential An `Authorization` value; empty sends none.
     * @param string|null $body       Null sends none.
     * @param string      $accept     An `Accept` value; empty sends none.
     * @return Request
     */
    private static function request(
        string $method,
        string $path,
        string $credential = '',
        ?string $body = null,
        string $accept = '',
    ): Request {
        $request = TestRequest::to($method, $path);

        if ($credential !== '') {
            $request = $request->withServer(ServerVariable::Authorization, $credential);
        }

        if ($accept !== '') {
            $request = $request->with(RequestHeader::Accept, $accept);
        }

        if ($body !== null) {
            $request = $request->withBody($body);
        }

        return $request->request();
    }

    /**
     * @param Answer $answer
     * @return list<string>
     */
    private static function lines(Answer $answer): array
    {
        return $answer->headers()->map(static fn(Header $header): string => $header->line())->toValues();
    }

    /**
     * What a verified answer says, as the text the signing CLI prints from it — read back through
     * the CLI's own reader, so a test of wording asserts what a terminal shows. An answer that is not
     * a result comes back as its body.
     *
     * @param Answer $answer
     * @return string
     */
    private static function text(Answer $answer): string
    {
        return ResultReader::read($answer->body())?->text() ?? $answer->body();
    }

    /**
     * A listing's data, decoded.
     *
     * @param Answer $answer
     * @return array{address: string, entries: list<array<string, mixed>>}
     */
    private static function decoded(Answer $answer): array
    {
        /** @var array{address: string, entries: list<array<string, mixed>>} $data */
        $data = json_decode($answer->body(), true, 8, JSON_THROW_ON_ERROR);

        return $data;
    }

    /**
     * An applier that can reach nothing but the sandbox.
     *
     * @return UpdateApplier
     */
    private function applier(): UpdateApplier
    {
        return new UpdateApplier(new Deployment(
            new Directory($this->sandbox),
            new Directory($this->sandbox . '/public'),
        ));
    }

    /**
     * @return OpenSSLAsymmetricKey
     */
    private static function otherKey(): OpenSSLAsymmetricKey
    {
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        self::assertNotFalse($key);

        return $key;
    }
}
