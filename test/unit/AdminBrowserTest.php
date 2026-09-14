<?php

declare(strict_types=1);

namespace Phpanta\Test\Unit;

use OpenSSLAsymmetricKey;
use Phpanta\Controller\ApiController;
use Phpanta\Http\Answer;
use Phpanta\Http\Api\AccessAction;
use Phpanta\Http\Api\ActionField;
use Phpanta\Http\Api\AdminHeaders;
use Phpanta\Http\CsrfField;
use Phpanta\Http\EmptyResponse;
use Phpanta\Http\HttpMethod;
use Phpanta\Http\HttpStatusCode;
use Phpanta\Http\Origin;
use Phpanta\Http\Parameter;
use Phpanta\Http\PasskeyFormField;
use Phpanta\Http\RequestHeader;
use Phpanta\Http\ResponseHeader;
use Phpanta\Http\ServerVariable;
use Phpanta\Http\Session;
use Phpanta\Http\SessionSeal;
use Phpanta\Model\Api\ApiEnvelope;
use Phpanta\Model\Passkey\CeremonyType;
use Phpanta\Model\Passkey\Challenge;
use Phpanta\Model\Passkey\ChallengePurpose;
use Phpanta\Model\Passkey\EnrolmentCode;
use Phpanta\Model\Passkey\Passkey;
use Phpanta\Service\ApiGate;
use Phpanta\Service\Passkey\AdminBrowser;
use Phpanta\Service\Passkey\AdminCaller;
use Phpanta\Service\Passkey\BrowserRequest;
use Phpanta\Service\Passkey\PasskeyRegistry;
use Phpanta\Support\Base64Url;
use Phpanta\Support\Directory;
use Phpanta\Support\File;
use Phpanta\Support\Throttle;
use Phpanta\Test\TestRequest;
use Phpanta\Text\AdminText;
use Phpanta\Text\Language;
use Phpanta\View\AdminEnrolmentView;
use Phpanta\View\AdminEntranceView;
use Phpanta\View\AdminForm;
use Phpanta\View\ApiActionFormView;
use Phpanta\View\ApiListingView;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The admin, for a browser: the entrance's unlock, registration and lock, and what a browser it has
 * let in may do past it — every read the signing key may make, and each write a tap of its passkey.
 *
 * **Real ceremonies throughout.** A P-256 key made here plays the device: the authenticator data, the
 * client data and the signature are built the way an authenticator builds them, and checked by the
 * verifier the admin really uses, so a test that passes here is a ceremony that would pass for a
 * browser. The controller is the admin's own, over a gate with no key — which verifies nobody, so
 * every request here is a browser's.
 */
#[CoversClass(AdminBrowser::class)]
#[CoversClass(AdminCaller::class)]
#[CoversClass(BrowserRequest::class)]
#[CoversClass(ApiController::class)]
#[CoversClass(AdminEntranceView::class)]
#[CoversClass(AdminEnrolmentView::class)]
#[CoversClass(ApiActionFormView::class)]
#[CoversClass(ApiListingView::class)]
#[CoversClass(AdminForm::class)]
#[CoversClass(AdminHeaders::class)]
#[CoversClass(ApiEnvelope::class)]
#[CoversClass(ActionField::class)]
final class AdminBrowserTest extends TestCase
{
    private const string ORIGIN = 'https://example.test';
    /** A credential id as a browser writes one — base64url, `device` — which a listing spells the same way. */
    private const string DEVICE = 'ZGV2aWNl';

    private string $sandbox = '';
    private OpenSSLAsymmetricKey $private;
    private string $der = '';
    private SessionSeal $seal;
    private PasskeyRegistry $registry;

    /**
     * A device with a key of its own, enrolled, and a deployment with a session key.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->sandbox = sys_get_temp_dir() . '/phpanta-browser-' . bin2hex(random_bytes(6));
        new Directory($this->sandbox . '/throttle')->create();

        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        self::assertNotFalse($key, 'this host cannot generate an EC key, so nothing here is meaningful');
        $details = openssl_pkey_get_details($key);
        self::assertIsArray($details);

        $this->private  = $key;
        $this->der      = (string) base64_decode(
            (string) preg_replace('/-----[^-]+-----|\s+/', '', (string) $details['key']),
            true,
        );
        $this->seal     = SessionSeal::fromKey(random_bytes(32));
        $this->registry = new PasskeyRegistry(new File($this->sandbox . '/admin-passkeys.json'));

        self::assertTrue($this->registry->keep(new Passkey(self::DEVICE, 'phone', $this->der)));
    }

    /**
     * @return void
     */
    protected function tearDown(): void
    {
        UpdateFixture::removeTree($this->sandbox);
    }

    // ───────────────────────── the entrance ─────────────────────────

    /**
     * A deployment that does not say where it is lets no browser in, and its entrance says so — and
     * sends anything posted to it back.
     *
     * @return void
     */
    public function testAnEntranceThatLetsNoBrowserInSaysSo(): void
    {
        $page = $this->answer(TestRequest::get('/admin'), new AdminBrowser());
        $post = $this->answer(TestRequest::to(HttpMethod::Post, '/admin'), new AdminBrowser());

        self::assertSame(HttpStatusCode::Ok, $page->status());
        self::assertStringContainsString(AdminText::PasskeysOff->in(Language::English), $page->body());
        self::assertStringNotContainsString('<form', $page->body());
        self::assertNull($page->header(ResponseHeader::SetCookie));
        self::assertSame(HttpStatusCode::SeeOther, $post->status());

        // One that says where it is but has no session key has no session to let a browser in with.
        $keyless = $this->answer(TestRequest::get('/admin'), new AdminBrowser(origin: Origin::of(self::ORIGIN)));

        self::assertStringContainsString(AdminText::PasskeysOff->in(Language::English), $keyless->body());
    }

    /**
     * The entrance offers both ceremonies over one challenge, which its session keeps, with the form
     * token its forms carry — and is kept by no cache.
     *
     * @return void
     */
    public function testTheEntranceOffersBothCeremoniesOverOneChallenge(): void
    {
        $answer    = $this->answer(TestRequest::get('/admin'));
        $session   = $this->sessionOf($answer);
        $challenge = $session->challenge();

        self::assertSame(HttpStatusCode::Ok, $answer->status());
        self::assertNotNull($challenge);
        self::assertTrue($challenge->expects(ChallengePurpose::Entrance, time()));
        self::assertStringContainsString('data-passkey="webauthn.get"', $answer->body());
        self::assertStringContainsString('data-passkey="webauthn.create"', $answer->body());
        self::assertSame(2, substr_count($answer->body(), 'data-challenge="' . $challenge->value . '"'));
        self::assertSame(
            2,
            substr_count($answer->body(), '<p data-passkey-status hidden>The passkey did not answer'),
            'a passkey form holds no words for the client module to show when nobody answers',
        );
        self::assertStringContainsString('value="' . $session->token() . '"', $answer->body());
        self::assertSame('no-store, private', $answer->header(ResponseHeader::CacheControl)?->value->render());
    }

    /**
     * An enrolled passkey's answer to the entrance's challenge lets the browser in, and the count it
     * reported is kept; the browser then sees the admin, with the way out.
     *
     * @return void
     */
    public function testAnEnrolledPasskeyUnlocksTheAdmin(): void
    {
        $session = $this->atTheEntrance();
        $answer  = $this->answer($this->posted('/admin', $session, [
            [PasskeyFormField::Ceremony, 'unlock'],
            ...$this->assertion((string) $session->challenge()?->value, count: 1),
        ]));
        $admitted = $this->sessionOf($answer);

        self::assertSame(HttpStatusCode::SeeOther, $answer->status());
        self::assertSame('/admin', $answer->header(ResponseHeader::Location)?->value->render());
        self::assertSame(self::DEVICE, $admitted->admin());
        self::assertNull($admitted->challenge(), 'the challenge the unlock answered was not spent');
        self::assertSame(1, $this->registry->find(self::DEVICE)?->count);

        $inside = $this->answer($this->carrying(TestRequest::get('/admin'), $admitted));

        self::assertSame(HttpStatusCode::Ok, $inside->status());
        self::assertStringContainsString('/admin/access', $inside->body());
        self::assertStringContainsString('value="logout"', $inside->body());
    }

    /**
     * An answer that is not the device's, a device that is not enrolled, and a count that did not
     * rise open nothing — and the entrance says so, once.
     *
     * @return void
     */
    public function testAnUnlockThatDoesNotAnswerIsRefusedAndSaysSo(): void
    {
        self::assertTrue($this->registry->keep(new Passkey(self::DEVICE, 'phone', $this->der, 5)));

        foreach (
            [
                'another signature' => ['signature' => 'not this'],
                'not enrolled'      => ['credential' => 'stranger'],
                'a count not risen' => ['count' => 5],
            ] as $case => $wrong
        ) {
            $session = $this->atTheEntrance();
            $answer  = $this->answer($this->posted('/admin', $session, [
                [PasskeyFormField::Ceremony, 'unlock'],
                ...$this->assertion(
                    (string) $session->challenge()?->value,
                    $wrong['count'] ?? 6,
                    $wrong['credential'] ?? self::DEVICE,
                    $wrong['signature'] ?? '',
                ),
            ]));
            $refused = $this->sessionOf($answer);

            self::assertSame(HttpStatusCode::SeeOther, $answer->status(), $case);
            self::assertNull($refused->admin(), $case);
            self::assertNull($refused->challenge(), $case);

            $again = $this->answer($this->carrying(TestRequest::get('/admin'), $refused));

            self::assertStringContainsString(AdminText::UnlockRefused->in(Language::English), $again->body(), $case);
        }
    }

    /**
     * A post without the token the page handed out, one naming no ceremony, and a body that is not a
     * form are all sent back to the entrance with nothing opened.
     *
     * @return void
     */
    public function testAPostTheEntranceDidNotHandOutOpensNothing(): void
    {
        $session = $this->atTheEntrance();
        $fields  = $this->assertion((string) $session->challenge()?->value);

        $forged  = $this->carrying(TestRequest::to(HttpMethod::Post, '/admin'), $session)
            ->withField(CsrfField::Token, 'not the token')
            ->withField(PasskeyFormField::Ceremony, 'unlock');
        $nothing = $this->posted('/admin', $session, $fields);
        $text    = $this->carrying(TestRequest::to(HttpMethod::Post, '/admin'), $session)
            ->withServer(ServerVariable::ContentType, 'text/plain')
            ->withBody('ceremony=unlock');

        foreach ([$forged, $nothing, $text] as $request) {
            $answer = $this->answer($request);

            self::assertSame(HttpStatusCode::SeeOther, $answer->status());
            self::assertNull($answer->header(ResponseHeader::SetCookie));
        }

        self::assertSame(0, $this->registry->find(self::DEVICE)?->count);
    }

    /**
     * An answer to a challenge minted for something else — a write's, here — opens nothing at the
     * entrance, however well it is signed.
     *
     * @return void
     */
    public function testAChallengeMintedForSomethingElseOpensNothing(): void
    {
        $challenge = Challenge::mint(ChallengePurpose::Write, time(), 'POST /admin/access/v1/revoke');
        $session   = Session::fresh($this->seal)->withToken()->withChallenge($challenge);
        $answer    = $this->answer($this->posted('/admin', $session, [
            [PasskeyFormField::Ceremony, 'unlock'],
            ...$this->assertion($challenge->value, count: 1),
        ]));

        self::assertSame(HttpStatusCode::SeeOther, $answer->status());
        self::assertNull($this->sessionOf($answer)->admin());
        self::assertSame(0, $this->registry->find(self::DEVICE)?->count);
    }

    /**
     * A device that registers over the entrance's challenge is shown an enrolment code for its key —
     * one this deployment opens — with the key's fingerprint and the call that enrols it. Nothing is
     * stored.
     *
     * @return void
     */
    public function testARegistrationEarnsAnEnrolmentCode(): void
    {
        $session = $this->atTheEntrance();
        $answer  = $this->answer($this->posted('/admin', $session, [
            [PasskeyFormField::Ceremony, 'register'],
            ...$this->registration((string) $session->challenge()?->value),
        ]));

        self::assertSame(HttpStatusCode::Ok, $answer->status());
        self::assertStringContainsString(Passkey::fingerprintOf($this->der), $answer->body());
        self::assertSame(1, preg_match('/--code (\S+)</', $answer->body(), $match));
        self::assertStringContainsString('access v1 enrol --name', $answer->body());
        self::assertSame($this->der, EnrolmentCode::open($this->seal, $match[1], time())?->key);
        self::assertNull($this->sessionOf($answer)->challenge());
        self::assertCount(1, $this->registry->all());
    }

    /**
     * A registration that is not one — no key attested, or no key at all — earns nothing, and says so.
     *
     * @return void
     */
    public function testARegistrationThatIsNotOneEarnsNothing(): void
    {
        foreach ([0x05, null] as $flags) {
            $session = $this->atTheEntrance();
            $fields  = $this->registration((string) $session->challenge()?->value, $flags ?? 0x45);

            $answer = $this->answer($this->posted('/admin', $session, [
                [PasskeyFormField::Ceremony, 'register'],
                ...($flags === null ? array_slice($fields, 0, 3) : $fields),
            ]));

            self::assertSame(HttpStatusCode::SeeOther, $answer->status());

            $again = $this->answer($this->carrying(TestRequest::get('/admin'), $this->sessionOf($answer)));

            self::assertStringContainsString(AdminText::RegistrationRefused->in(Language::English), $again->body());
        }
    }

    /**
     * The same answer sent twice — the session that carried its challenge copied, and the post sent
     * again — opens the admin once: the store has recorded that challenge as answered.
     *
     * @return void
     */
    public function testAnUnlockSentTwiceOpensTheAdminOnce(): void
    {
        $session = $this->atTheEntrance();
        $post    = $this->posted('/admin', $session, [
            [PasskeyFormField::Ceremony, 'unlock'],
            ...$this->assertion((string) $session->challenge()?->value),
        ]);

        self::assertSame(self::DEVICE, $this->sessionOf($this->answer($post))->admin());
        self::assertSame($session->challenge()?->minted(), $this->registry->find(self::DEVICE)?->unlocked);
        self::assertNull($this->sessionOf($this->answer($post))->admin(), 'the same answer opened the admin twice');
    }

    /**
     * Locking the admin ends the session — this one, and a copy of it: the browser is a stranger again,
     * and so is anybody holding the cookie it had.
     *
     * @return void
     */
    public function testLockingTheAdminEndsTheSessionAndEveryCopyOfIt(): void
    {
        $admitted = $this->admitted();
        $answer   = $this->answer($this->posted('/admin', $admitted, [[PasskeyFormField::Ceremony, 'logout']]));

        self::assertSame(HttpStatusCode::SeeOther, $answer->status());
        self::assertNull($this->sessionOf($answer)->admin());
        self::assertSame(
            HttpStatusCode::SeeOther,
            $this->answer($this->carrying(TestRequest::get('/admin/access'), $admitted))->status(),
            'a copy of the session outlived the lock',
        );
    }

    /**
     * A lock the store cannot record still ends this browser's session, and says that a copy of it may
     * not have ended.
     *
     * @return void
     */
    public function testALockTheStoreCannotRecordSaysSo(): void
    {
        // Enrolling the device took the store's lock, which left its file behind; a directory in its
        // place is a lock nobody can take.
        self::assertTrue(new File($this->sandbox . '/admin-passkeys.json.lock')->delete());
        new Directory($this->sandbox . '/admin-passkeys.json.lock')->create();

        $answer  = $this->answer($this->posted('/admin', $this->admitted(), [[PasskeyFormField::Ceremony, 'logout']]));
        $session = $this->sessionOf($answer);
        $again   = $this->answer($this->carrying(TestRequest::get('/admin'), $session));

        self::assertNull($session->admin());
        self::assertStringContainsString(AdminText::LockUnrecorded->in(Language::English), $again->body());
    }

    /**
     * Posts to the entrance are counted per address; past the limit they are a `429` saying when to
     * come back, and where they cannot be counted the entrance takes none.
     *
     * @return void
     */
    public function testTheEntranceCountsItsPosts(): void
    {
        $once    = new Throttle(new Directory($this->sandbox . '/throttle'), 1, 900);
        $nowhere = new Throttle(new Directory($this->sandbox . '/nowhere'), 1, 900);
        $session = $this->atTheEntrance();
        $post    = $this->posted('/admin', $session, [[PasskeyFormField::Ceremony, 'unlock']])
            ->withServer(ServerVariable::RemoteAddress, '192.0.2.1');

        self::assertSame(HttpStatusCode::SeeOther, $this->answer($post, $this->browser($once))->status());

        $refused = $this->answer($post, $this->browser($once));

        self::assertSame(HttpStatusCode::TooManyRequests, $refused->status());
        self::assertNotNull($refused->header(ResponseHeader::RetryAfter));

        $uncounted = $this->answer($post, $this->browser($nowhere));

        self::assertSame(HttpStatusCode::ServiceUnavailable, $uncounted->status());
        self::assertStringContainsString('data/throttle/', $uncounted->body());
    }

    /**
     * A method that is neither a read nor a post is sent back to the entrance, as below it.
     *
     * @return void
     */
    public function testAnyOtherMethodAtTheEntranceIsSentBack(): void
    {
        self::assertSame(HttpStatusCode::SeeOther, $this->answer(TestRequest::to('PUT', '/admin'))->status());
    }

    /**
     * In development, and from loopback only, the origin the request says it comes from comes first:
     * a local copy of a site that names its public origin runs a real ceremony at the address it is
     * served on — and the same request from anywhere else is held to the public origin. An app that
     * names none still offers the ceremonies there.
     *
     * @return void
     */
    public function testDevelopmentOnLoopbackRunsAtTheRequestsOwnOrigin(): void
    {
        $was = $_SERVER[ServerVariable::Environment->value] ?? null;
        $_SERVER[ServerVariable::Environment->value] = 'development';
        $local = 'https://dev.test';

        try {
            $unlocked = [];

            foreach (['127.0.0.1' => true, '192.0.2.1' => false] as $address => $loopback) {
                $session = $this->atTheEntrance();
                $answer  = $this->answer($this->posted('/admin', $session, [
                    [PasskeyFormField::Ceremony, 'unlock'],
                    ...$this->assertion((string) $session->challenge()?->value, 1, origin: $local),
                ])->withServer(ServerVariable::RemoteAddress, $address)->with(RequestHeader::Origin, $local));

                $unlocked[$loopback ? 'loopback' : 'elsewhere'] = $this->sessionOf($answer)->admin();
            }

            $originless = new AdminBrowser($this->seal, $this->registry, new Throttle(
                new Directory($this->sandbox . '/throttle'),
                10,
                900,
            ));
            $page = $this->answer(
                TestRequest::get('/admin')->withServer(ServerVariable::RemoteAddress, '127.0.0.1'),
                $originless,
            );
        } finally {
            if ($was === null) {
                unset($_SERVER[ServerVariable::Environment->value]);
            } else {
                $_SERVER[ServerVariable::Environment->value] = $was;
            }
        }

        self::assertSame(['loopback' => self::DEVICE, 'elsewhere' => null], $unlocked);
        self::assertStringContainsString('data-passkey="webauthn.get"', $page->body());
    }

    // ───────────────────────── past it ─────────────────────────

    /**
     * A browser let in sees what the key sees: every listing, every read, and each write named — and
     * is told plainly where an action is the signing key's alone, missing, or not answered on a verb.
     *
     * @return void
     */
    public function testABrowserLetInSeesWhatTheKeySees(): void
    {
        $admitted = $this->admitted();
        $see      = fn(string $method, string $path, string $accept = 'text/html'): Answer => $this->answer(
            $this->carrying(TestRequest::to($method, $path)->with(RequestHeader::Accept, $accept), $admitted),
        );

        $version = $see('GET', '/admin/access/v1');

        self::assertSame(HttpStatusCode::Ok, $version->status());
        self::assertStringContainsString('href="/admin/access/v1/revoke"', $version->body());
        self::assertStringNotContainsString('href="/admin/access/v1/enrol"', $version->body());
        self::assertSame(HttpStatusCode::Ok, $see('GET', '/admin/access')->status());
        self::assertSame(HttpStatusCode::Ok, $see('GET', '/admin/access/v1/passkeys')->status());
        self::assertSame(HttpStatusCode::Ok, $see('GET', '/admin', 'application/json')->status());

        self::assertSame(HttpStatusCode::Forbidden, $see('GET', '/admin/update/v1/patch')->status());
        self::assertSame(HttpStatusCode::NotFound, $see('GET', '/admin/access/v1/nope')->status());
        self::assertSame(HttpStatusCode::MethodNotAllowed, $see('POST', '/admin/access/v1/passkeys')->status());
        self::assertSame(
            HttpStatusCode::MethodNotAllowed,
            $see('GET', '/admin/access/v1/revoke', 'application/json')->status(),
        );
        self::assertSame(HttpStatusCode::MethodNotAllowed, $see('POST', '/admin/access')->status());
    }

    /**
     * A write is its form, holding a challenge for that one address; a tap of the unlocking passkey
     * carries it to the action's handler with the fields the form sent — and the tap is spent, so the
     * same answer twice opens nothing.
     *
     * The handler keeps to the deployment's own store, which in the test app enrols nobody; so what
     * arrives is its refusal naming the credential id the form sent, and that a handler said it at all
     * is the browser's half. The handler's own half is {@link AccessTest}'s.
     *
     * @return void
     */
    public function testAWriteIsItsFormAndATapCarriesItOut(): void
    {
        $form     = $this->answer($this->carrying(TestRequest::get('/admin/access/v1/revoke'), $this->admitted()));
        $session  = $this->sessionOf($form);
        $challenge = (string) $session->challenge()?->value;

        self::assertSame(HttpStatusCode::Ok, $form->status());
        self::assertTrue(
            $session->challenge()?->expects(ChallengePurpose::Write, time(), 'POST /admin/access/v1/revoke'),
        );
        self::assertStringContainsString('data-challenge="' . $challenge . '"', $form->body());
        self::assertStringContainsString('name="passkey"', $form->body());
        self::assertStringContainsString('value="false"', $form->body());

        $fields = [
            [ActionField::Passkey, self::DEVICE],
            [ActionField::Apply, 'false'],
            ...$this->assertion($challenge, count: 1),
        ];
        $dry    = $this->answer($this->posted('/admin/access/v1/revoke', $session, $fields));

        self::assertSame(HttpStatusCode::UnprocessableContent, $dry->status());
        self::assertStringContainsString('no enrolled device has that credential id', $dry->body());
        self::assertNull($this->sessionOf($dry)->challenge());
        self::assertNotNull($this->registry->find(self::DEVICE));

        $replayed = $this->answer($this->posted('/admin/access/v1/revoke', $this->sessionOf($dry), $fields));

        self::assertSame(HttpStatusCode::Forbidden, $replayed->status());
    }

    /**
     * A browser's write applied is a write like a push: it spends a serial, under the same lock,
     * before its handler runs.
     *
     * @return void
     */
    public function testAnAppliedWriteFromTheBrowserSpendsASerial(): void
    {
        $serial  = new File($this->sandbox . '/.update-serial');
        $session = $this->revokeForm($this->admitted());

        (void) $this->answer($this->posted('/admin/access/v1/revoke', $session, [
            [ActionField::Passkey, self::DEVICE],
            [ActionField::Apply, 'true'],
            ...$this->assertion((string) $session->challenge()?->value, count: 1),
        ]), null, $serial);

        self::assertMatchesRegularExpression('/\A\d+\n\z/', (string) $serial->read());
    }

    /**
     * A write's tap sent twice — the session that carried its challenge copied, and the post sent again
     * — writes once: its serial is the moment the challenge was minted, and the first one spent it.
     *
     * @return void
     */
    public function testAWriteSentTwiceWritesOnce(): void
    {
        $serial  = new File($this->sandbox . '/.update-serial');
        $session = $this->revokeForm($this->admitted());
        $post    = $this->posted('/admin/access/v1/revoke', $session, [
            [ActionField::Passkey, self::DEVICE],
            [ActionField::Apply, 'true'],
            ...$this->assertion((string) $session->challenge()?->value),
        ]);

        (void) $this->answer($post, null, $serial);
        $spent = $serial->read();
        $again = $this->answer($post, null, $serial);

        self::assertSame($session->challenge()?->minted() . "\n", $spent);
        self::assertSame(HttpStatusCode::Conflict, $again->status());
        self::assertStringContainsString('a newer write was accepted', $again->body());
        self::assertSame($spent, $serial->read());
    }

    /**
     * A passkey taken out of the store opens nothing on the next request, whatever the session it
     * unlocked says.
     *
     * @return void
     */
    public function testARevokedPasskeyOpensNothingAtOnce(): void
    {
        $admitted = $this->admitted();

        self::assertSame(
            HttpStatusCode::Ok,
            $this->answer($this->carrying(TestRequest::get('/admin/access'), $admitted))->status(),
        );
        self::assertTrue($this->registry->forget(self::DEVICE));
        self::assertSame(
            HttpStatusCode::SeeOther,
            $this->answer($this->carrying(TestRequest::get('/admin/access'), $admitted))->status(),
        );
    }

    /**
     * A write answered by another credential, over no challenge, without the token, or not as a form
     * at all is refused, and nothing is written.
     *
     * @return void
     */
    public function testAWriteWithoutAFreshTapIsRefused(): void
    {
        $admitted  = $this->admitted();
        $session   = $this->revokeForm($admitted);
        $challenge = (string) $session->challenge()?->value;
        $fields    = [[ActionField::Passkey, self::DEVICE], [ActionField::Apply, 'true']];

        $requests = [
            'another credential' => $this->posted('/admin/access/v1/revoke', $session, [
                ...$fields,
                ...$this->assertion($challenge, count: 1, credential: 'other'),
            ]),
            'no challenge'       => $this->posted('/admin/access/v1/revoke', $admitted, [
                ...$fields,
                ...$this->assertion($challenge, count: 1),
            ]),
            'not a form'         => $this->carrying(
                TestRequest::to(HttpMethod::Post, '/admin/access/v1/revoke'),
                $session,
            )
                ->withServer(ServerVariable::ContentType, 'text/plain')
                ->withBody('apply=true'),
        ];

        foreach ($requests as $case => $request) {
            self::assertSame(HttpStatusCode::Forbidden, $this->answer($request)->status(), $case);
        }

        self::assertNotNull($this->registry->find(self::DEVICE));
    }

    /**
     * An action every browser may reach is named by the same catalog either way.
     *
     * @return void
     */
    public function testTheBrowsersActionsAreTheKeysLessTheKeysOwn(): void
    {
        self::assertFalse(AccessAction::Enrol->fromBrowser());
        self::assertTrue(ActionField::Apply->isFlag());
        self::assertFalse(ActionField::Passkey->isFlag());
    }

    // ───────────────────────── helpers ─────────────────────────

    /**
     * What the admin answers $request, through a gate with no key and $browser (the test's, by default).
     *
     * @param TestRequest       $request
     * @param AdminBrowser|null $browser
     * @param File|null         $serial
     * @return Answer
     */
    private function answer(TestRequest $request, ?AdminBrowser $browser = null, ?File $serial = null): Answer
    {
        $built    = $request->request();
        $segments = explode('/', trim($built->path(), '/'));

        return new ApiController(
            $segments[1] ?? null,
            $segments[2] ?? null,
            $segments[3] ?? null,
            new ApiGate(new File($this->sandbox . '/update.pub'), $serial ?? new File($this->sandbox . '/serial')),
            $browser ?? $this->browser(),
        )->handle($built)->answer($built);
    }

    /**
     * The browser's side of the admin, over this test's store, seal and origin.
     *
     * @param Throttle|null $throttle
     * @return AdminBrowser
     */
    private function browser(?Throttle $throttle = null): AdminBrowser
    {
        return new AdminBrowser(
            $this->seal,
            $this->registry,
            $throttle ?? new Throttle(new Directory($this->sandbox . '/throttle'), 100, 900),
            Origin::of(self::ORIGIN),
        );
    }

    /**
     * A session as the entrance page leaves it: a form token and the entrance's challenge.
     *
     * @return Session
     */
    private function atTheEntrance(): Session
    {
        return Session::fresh($this->seal)
            ->withToken()
            ->withChallenge(Challenge::mint(ChallengePurpose::Entrance, time()));
    }

    /**
     * The session the revoke form leaves, for a browser holding $admitted: its write challenge minted.
     *
     * @param Session $admitted
     * @return Session
     */
    private function revokeForm(Session $admitted): Session
    {
        return $this->sessionOf($this->answer($this->carrying(TestRequest::get('/admin/access/v1/revoke'), $admitted)));
    }

    /**
     * A session the device has unlocked.
     *
     * @return Session
     */
    private function admitted(): Session
    {
        return Session::fresh($this->seal)->withAdmin(self::DEVICE, time());
    }

    /**
     * $request, carrying $session in its cookie.
     *
     * @param TestRequest $request
     * @param Session     $session
     * @return TestRequest
     */
    private function carrying(TestRequest $request, Session $session): TestRequest
    {
        $set = (string) $session->attachTo(new EmptyResponse())
            ->answer(TestRequest::get('/')->request())
            ->header(ResponseHeader::SetCookie)?->value->render();

        return $request->with(RequestHeader::Cookie, (string) strtok($set, ';'));
    }

    /**
     * A form posted to $path by the browser holding $session, with its token and $fields.
     *
     * @param string                          $path
     * @param Session                         $session
     * @param list<array{Parameter, string}>  $fields
     * @return TestRequest
     */
    private function posted(string $path, Session $session, array $fields): TestRequest
    {
        $request = $this->carrying(TestRequest::to(HttpMethod::Post, $path), $session)
            ->withField(CsrfField::Token, (string) $session->token());

        foreach ($fields as [$field, $value]) {
            $request = $request->withField($field, $value);
        }

        return $request;
    }

    /**
     * The session an answer set, opened as the next request would open it.
     *
     * @param Answer $answer
     * @return Session
     */
    private function sessionOf(Answer $answer): Session
    {
        $set = (string) $answer->header(ResponseHeader::SetCookie)?->value->render();

        return Session::of(
            TestRequest::get('/')->with(RequestHeader::Cookie, (string) strtok($set, ';'))->request(),
            $this->seal,
        );
    }

    /**
     * The device's answer to $challenge, as the client posts it.
     *
     * @param string $challenge
     * @param int    $count      The count the authenticator reports.
     * @param string $credential Which credential it says it is.
     * @param string $signature  Signed over something else, where not ''.
     * @param string $origin     Where the ceremony ran.
     * @return list<array{Parameter, string}>
     */
    private function assertion(
        string $challenge,
        int $count = 0,
        string $credential = self::DEVICE,
        string $signature = '',
        string $origin = self::ORIGIN,
    ): array {
        $data   = self::authenticatorData(0x05, $count, (string) parse_url($origin, PHP_URL_HOST));
        $client = self::clientData(CeremonyType::Get, $challenge, $origin);
        $signed = $this->sign($signature === '' ? $data : $signature, $client);

        return [
            [PasskeyFormField::Credential, $credential],
            [PasskeyFormField::ClientData, Base64Url::encode($client)],
            [PasskeyFormField::AuthenticatorData, Base64Url::encode($data)],
            [PasskeyFormField::Signature, Base64Url::encode($signed)],
        ];
    }

    /**
     * The device registering over $challenge: its key last, so a test can leave it off.
     *
     * @param string $challenge
     * @param int    $flags
     * @return list<array{Parameter, string}>
     */
    private function registration(string $challenge, int $flags = 0x45): array
    {
        return [
            [PasskeyFormField::Credential, 'Y3JlZGVudGlhbA'],
            [PasskeyFormField::ClientData, Base64Url::encode(self::clientData(CeremonyType::Create, $challenge))],
            [PasskeyFormField::AuthenticatorData, Base64Url::encode(self::authenticatorData($flags, 0))],
            [PasskeyFormField::Key, Base64Url::encode($this->der)],
        ];
    }

    /**
     * Authenticator data for this origin's host: its hash, $flags, and $count.
     *
     * @param int    $flags
     * @param int    $count
     * @param string $host  The relying party.
     * @return string
     */
    private static function authenticatorData(int $flags, int $count, string $host = 'example.test'): string
    {
        return hash('sha256', $host, true) . chr($flags) . pack('N', $count);
    }

    /**
     * Client data for $type over $challenge, on $origin.
     *
     * @param CeremonyType $type
     * @param string       $challenge
     * @param string       $origin
     * @return string
     */
    private static function clientData(CeremonyType $type, string $challenge, string $origin = self::ORIGIN): string
    {
        return (string) json_encode(
            ['type' => $type->value, 'challenge' => $challenge, 'origin' => $origin, 'crossOrigin' => false],
            JSON_UNESCAPED_SLASHES,
        );
    }

    /**
     * The device's signature over $data and $client, as an authenticator signs.
     *
     * @param string $data
     * @param string $client
     * @return string
     */
    private function sign(string $data, string $client): string
    {
        self::assertTrue(openssl_sign(
            $data . hash('sha256', $client, true),
            $signature,
            $this->private,
            OPENSSL_ALGO_SHA256,
        ));

        return (string) $signature;
    }
}
