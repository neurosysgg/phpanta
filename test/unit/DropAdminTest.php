<?php

declare(strict_types=1);

namespace Phpanta\Test\Unit;

use OpenSSLAsymmetricKey;
use Phpanta\App;
use Phpanta\Controller\ApiController;
use Phpanta\Controller\DropController;
use Phpanta\CredentialFile;
use Phpanta\Exception\ApiException;
use Phpanta\Http\Answer;
use Phpanta\Http\Api\ActionField;
use Phpanta\Http\Api\ApiListing;
use Phpanta\Http\Api\ApiService;
use Phpanta\Http\Api\DropAction;
use Phpanta\Http\AuthScheme;
use Phpanta\Http\CsrfField;
use Phpanta\Http\DropField;
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
use Phpanta\Http\Upload;
use Phpanta\Model\Api\ApiEnvelope;
use Phpanta\Model\Api\VerifiedRequest;
use Phpanta\Model\Drop\DropConfig;
use Phpanta\Model\Drop\DropManifest;
use Phpanta\Model\Drop\DropTerms;
use Phpanta\Model\Passkey\CeremonyType;
use Phpanta\Model\Passkey\Passkey;
use Phpanta\Service\Api\DropCreate;
use Phpanta\Service\Api\DropList;
use Phpanta\Service\Api\DropRevoke;
use Phpanta\Service\ApiGate;
use Phpanta\Service\Drop\DropCipher;
use Phpanta\Service\Drop\DropStore;
use Phpanta\Service\Passkey\AdminBrowser;
use Phpanta\Service\Passkey\PasskeyRegistry;
use Phpanta\Support\Base64Url;
use Phpanta\Support\Collection;
use Phpanta\Support\Directory;
use Phpanta\Support\DropPath;
use Phpanta\Support\File;
use Phpanta\Support\Throttle;
use Phpanta\Test\TestRequest;
use Phpanta\View\ApiActionFormView;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The `drop` service as the admin serves it: there only where `data/drop.json` switches it on, making
 * a drop for a signed call — its bytes the body — or for a browser the admin let in, with one tap;
 * listing what is kept without a word of what any holds; and taking one away. Each drop made here is
 * then opened at `/drop` by the link the admin answered, which is the whole of what the admin says.
 *
 * The switch and the key are the test app's own, written for each test and taken away after it; the
 * gate verifies against a key made here, and the browser's passkey is one made here too — see
 * {@link MachineAdminTest}, whose arrangement this is.
 */
#[CoversClass(ApiController::class)]
#[CoversClass(ApiListing::class)]
#[CoversClass(ApiService::class)]
#[CoversClass(DropAction::class)]
#[CoversClass(ActionField::class)]
#[CoversClass(DropManifest::class)]
#[CoversClass(DropConfig::class)]
#[CoversClass(DropStore::class)]
#[CoversClass(DropCipher::class)]
#[CoversClass(DropCreate::class)]
#[CoversClass(DropList::class)]
#[CoversClass(DropRevoke::class)]
#[CoversClass(DropTerms::class)]
#[CoversClass(ApiActionFormView::class)]
#[CoversClass(AdminBrowser::class)]
#[CoversClass(DropPath::class)]
final class DropAdminTest extends TestCase
{
    /** The origin the browser's passkey ceremonies run at. */
    private const string ORIGIN = 'https://example.test';

    /** The browser's enrolled device. */
    private const string DEVICE = 'drop-device';

    /** Whether the test app's data directory was made here, and so is taken away here. */
    private static bool $madeData = false;

    private string $sandbox = '';

    /**
     * The serial the last signed call carried. Each call's is one more — a write's must be newer than
     * the last write's, and no more than five seconds ahead of the clock — so it starts well inside
     * the window behind now.
     */
    private int $serial = 0;

    private OpenSSLAsymmetricKey $signing;

    private OpenSSLAsymmetricKey $device;

    private SessionSeal $seal;

    private PasskeyRegistry $registry;

    /**
     * @return void
     */
    public static function setUpBeforeClass(): void
    {
        $data           = App::current()->data();
        self::$madeData = !$data->exists();

        if (self::$madeData) {
            self::assertTrue($data->create());
        }
    }

    /**
     * @return void
     */
    public static function tearDownAfterClass(): void
    {
        if (self::$madeData) {
            rmdir(App::current()->data()->path);
        }
    }

    /**
     * A signing key and its public half, a device enrolled, and the service switched on with a key.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->sandbox  = (string) realpath(Directory::temporary('phpanta-drop-admin-')->path);
        $this->serial   = time() - 250;
        $this->signing  = self::key();
        $this->device   = self::key();
        $this->seal     = SessionSeal::fromKey(random_bytes(32));
        $this->registry = new PasskeyRegistry(new File($this->sandbox . '/admin-passkeys.json'));

        self::assertTrue(new File($this->sandbox . '/update.pub')->write(self::pem($this->signing)));
        self::assertTrue($this->registry->keep(new Passkey(self::DEVICE, 'phone', self::der($this->device))));
        self::assertTrue(new Directory($this->sandbox . '/throttle')->create());

        $this->switchOn();
        self::assertTrue(App::current()->dataFile(CredentialFile::DropKey)->write(
            base64_encode(random_bytes(DropCipher::KEY_BYTES)),
        ));
    }

    /**
     * @return void
     */
    protected function tearDown(): void
    {
        (void) App::current()->dataFile(CredentialFile::Drop)->delete();
        (void) App::current()->dataFile(CredentialFile::DropKey)->delete();

        $drops = App::current()->data()->directory(DropStore::DIRECTORY);

        if ($drops->exists()) {
            UpdateFixture::removeTree($drops->path);
        }

        if ($this->sandbox !== '') {
            UpdateFixture::removeTree($this->sandbox);
        }
    }

    // ───────────────────────── there, or not ─────────────────────────

    /**
     * With no switch the service is not named and not there; with one, it is listed with its three
     * actions.
     *
     * @return void
     */
    public function testTheServiceIsThereOnlyWhereItIsSwitchedOn(): void
    {
        (void) App::current()->dataFile(CredentialFile::Drop)->delete();

        self::assertNotContains('drop', self::names($this->signed(HttpMethod::Get, '/admin')));
        self::assertSame(HttpStatusCode::NotFound, $this->signed(HttpMethod::Get, '/admin/drop')->status());
        self::assertSame(
            HttpStatusCode::NotFound,
            $this->signed(HttpMethod::Post, DropAction::Create->href(), ['apply' => true], 'x')->status(),
        );

        $this->switchOn();

        self::assertContains('drop', self::names($this->signed(HttpMethod::Get, '/admin')));
        self::assertSame(['create', 'list', 'revoke'], self::names($this->signed(HttpMethod::Get, '/admin/drop/v1')));
    }

    /**
     * Without a key the admin says where it goes and how to mint one, and makes nothing.
     *
     * @return void
     */
    public function testWithoutAKeyTheAdminSaysHowToMintOne(): void
    {
        (void) App::current()->dataFile(CredentialFile::DropKey)->delete();

        $made = $this->signed(HttpMethod::Post, DropAction::Create->href(), ['apply' => true], 'x');

        self::assertSame(HttpStatusCode::UnprocessableContent, $made->status());
        self::assertStringContainsString('drop.key holds no drop key: mint one', $made->body());
        self::assertSame(
            HttpStatusCode::UnprocessableContent,
            $this->signed(HttpMethod::Get, DropAction::List->href())->status(),
        );
    }

    // ───────────────────────── a signed caller ─────────────────────────

    /**
     * A signed call's body is the drop, and the link the admin answers is what opens it — once, where
     * it opens once.
     *
     * @return void
     */
    public function testASignedCallMakesADropItsLinkOpens(): void
    {
        $made = $this->signed(
            HttpMethod::Post,
            DropAction::Create->href(),
            ['apply' => true, 'lifetime' => '1h', 'once' => true],
            'the secret, and nothing else',
        );

        self::assertSame(HttpStatusCode::Created, $made->status());
        self::assertStringContainsString(DropTerms::opens(true), $made->body());

        $token    = self::token($made);
        $revealed = $this->reveal($token);

        self::assertSame(HttpStatusCode::Ok, $revealed->status());
        self::assertSame('the secret, and nothing else', $revealed->body());
        self::assertStringStartsWith('text/plain', self::headerOf($revealed, ResponseHeader::ContentType));
        self::assertSame(HttpStatusCode::NotFound, $this->reveal($token)->status(), 'read once');
    }

    /**
     * A signed call that names its bytes makes a file, saved under that name as bytes — whatever it
     * claims to be.
     *
     * @return void
     */
    public function testASignedFileIsSavedUnderItsName(): void
    {
        $bytes    = "\x00\x01<html>\xff";
        $fields   = ['apply' => true, 'filename' => 'page.html'];
        $made     = $this->signed(HttpMethod::Post, DropAction::Create->href(), $fields, $bytes);
        $revealed = $this->reveal(self::token($made), page: true);

        self::assertSame(HttpStatusCode::Ok, $revealed->status());
        self::assertSame($bytes, $revealed->body());
        self::assertSame('application/octet-stream', self::headerOf($revealed, ResponseHeader::ContentType));
        self::assertStringContainsString(
            'filename="page.html"',
            self::headerOf($revealed, ResponseHeader::ContentDisposition),
        );
        self::assertSame((string) strlen($bytes), self::headerOf($revealed, ResponseHeader::ContentLength));
    }

    /**
     * A dry run says what would be kept, and keeps nothing.
     *
     * @return void
     */
    public function testADryRunKeepsNothing(): void
    {
        $dry = $this->signed(
            HttpMethod::Post,
            DropAction::Create->href(),
            ['apply' => false, 'once' => true],
            'rehearsal',
        );

        self::assertSame(HttpStatusCode::Ok, $dry->status());
        self::assertStringContainsString('a dry run: no drop was made', $dry->body());
        self::assertStringNotContainsString('/drop#', $dry->body());
        self::assertTrue(App::current()->data()->directory(DropStore::DIRECTORY)->files('*.drop')->isEmpty());
    }

    /**
     * A drop is held to what the deployment lets it be, and a manifest to what it may carry — each
     * refusal a sentence saying which.
     *
     * @return void
     */
    public function testMakingOneIsHeldToWhatTheDeploymentAllows(): void
    {
        self::assertTrue(App::current()->dataFile(CredentialFile::Drop)->write('{"maxBytes": 8, "maxLifetime": 3600}'));

        $refusals = [
            'a drop is 8 B at most here'                   => [
                ['apply' => true],
                'too long for it',
                HttpStatusCode::ContentTooLarge,
            ],
            'kept for 1 h 0 min at most here'              => [['apply' => true, 'lifetime' => '2h'], 'ok', null],
            'text is UTF-8'                                => [['apply' => true], "\xff\xfe", null],
            'there is nothing to drop'                     => [['apply' => true], '', null],
            'refused: a filename is one segment'           => [['apply' => true, 'filename' => 'a/b'], 'ok', null],
            'refused: a lifetime is a number'              => [['apply' => true, 'lifetime' => 'soon'], 'ok', null],
            'refused: the create manifest carries'         => [['apply' => true, 'once' => 'yes'], 'ok', null],
            'refused: a password is 1024 bytes at most'    => [
                ['apply' => true, 'password' => str_repeat('p', 1_025)],
                'ok',
                null,
            ],
            'refused: drop create needs apply'             => [['once' => true], 'ok', null],
        ];

        foreach ($refusals as $said => [$fields, $body, $status]) {
            $answer = $this->signed(HttpMethod::Post, DropAction::Create->href(), $fields, $body);

            self::assertSame($status ?? HttpStatusCode::UnprocessableContent, $answer->status(), $said);
            self::assertStringContainsString($said, $answer->body());
        }

        self::assertTrue(App::current()->data()->directory(DropStore::DIRECTORY)->files('*.drop')->isEmpty());
    }

    /**
     * Making a drop refuses what it cannot keep — two files, a file that cannot be read, a store that
     * cannot be written — each in a sentence, keeping nothing.
     *
     * @return void
     */
    public function testMakingOneRefusesWhatItCannotKeep(): void
    {
        $config   = DropConfig::parse('{}');
        $manifest = DropManifest::parse('{"apply": true}', DropAction::Create);
        $cipher   = DropCipher::fromKey(random_bytes(DropCipher::KEY_BYTES));
        $store    = new DropStore(new Directory($this->sandbox . '/drops'), $cipher);
        $file     = new File($this->sandbox . '/one.txt');
        $gone     = new File($this->sandbox . '/gone.txt');

        self::assertNotNull($config);
        self::assertTrue($file->write('one'));
        self::assertTrue(new File($this->sandbox . '/blocker')->write('a file'));

        $refusals = [
            'a drop is one file: send one at a time' => [
                $store,
                [new Upload('a.txt', $file, 3), new Upload('b.txt', $file, 3)],
                HttpStatusCode::UnprocessableContent,
            ],
            'the file sent could not be read' => [
                $store,
                [new Upload('gone.txt', $gone, 3)],
                HttpStatusCode::UnprocessableContent,
            ],
            'the drop was not kept: The drop store' => [
                new DropStore(new Directory($this->sandbox . '/blocker/drops'), $cipher),
                [],
                HttpStatusCode::InternalServerError,
            ],
        ];

        foreach ($refusals as $said => [$into, $uploads, $status]) {
            $sent   = new Collection(Upload::class)->with(...$uploads);
            $result = new DropCreate($into, $config, $manifest, 'bytes', $sent)->handle();

            self::assertSame($status, $result->status, $said);
            self::assertStringContainsString($said, $result->text());
        }

        self::assertFalse(new Directory($this->sandbox . '/drops')->exists(), 'nothing was kept');
    }

    /**
     * The listing says how large each drop is, since when, until when and how it opens — and not a word
     * of what it holds or what it is called — with a way to take each away.
     *
     * @return void
     */
    public function testTheListingSaysNothingOfWhatADropHolds(): void
    {
        $empty = $this->signed(HttpMethod::Get, DropAction::List->href());

        self::assertSame(HttpStatusCode::Ok, $empty->status());
        self::assertStringContainsString('no drop is kept here', $empty->body());

        (void) $this->signed(
            HttpMethod::Post,
            DropAction::Create->href(),
            ['apply' => true, 'filename' => 'payroll.pdf', 'password' => 'pw'],
            'the salaries',
        );

        $id   = (string) DropStore::current()?->summaries()->first()?->id;
        $list = $this->signed(HttpMethod::Get, DropAction::List->href());
        $page = $this->signed(HttpMethod::Get, DropAction::List->href(), accept: 'text/html');

        self::assertTrue(DropStore::isId($id));
        self::assertStringContainsString($id, $list->body());
        self::assertStringContainsString(DropTerms::needs(true), $list->body());
        self::assertStringNotContainsString('payroll', $list->body());
        self::assertStringNotContainsString('salaries', $list->body());
        self::assertStringContainsString('href="' . DropAction::Revoke->href($id) . '"', $page->body());
    }

    /**
     * Taking a drop away is a dry run first, then gone — its link opens nothing, and a second try says
     * there is nothing to take.
     *
     * @return void
     */
    public function testRevokingTakesADropAway(): void
    {
        $made  = $this->signed(HttpMethod::Post, DropAction::Create->href(), ['apply' => true], 'short-lived');
        $token = self::token($made);
        $id    = (string) DropStore::current()?->summaries()->first()?->id;
        $at    = DropAction::Revoke->href($id);

        $dry = $this->signed(HttpMethod::Post, $at, ['apply' => false]);

        self::assertSame(HttpStatusCode::Ok, $dry->status());
        self::assertStringContainsString('a dry run: the drop is still kept', $dry->body());
        self::assertTrue((bool) DropStore::current()?->has($id));

        $taken = $this->signed(HttpMethod::Post, $at, ['apply' => true]);

        self::assertSame(HttpStatusCode::Ok, $taken->status());
        self::assertStringContainsString('took away ' . $id, $taken->body());
        self::assertSame(HttpStatusCode::NotFound, $this->reveal($token)->status());
        self::assertSame(HttpStatusCode::NotFound, $this->signed(HttpMethod::Post, $at, ['apply' => true])->status());
        self::assertSame(
            HttpStatusCode::NotFound,
            $this->signed(HttpMethod::Post, DropAction::Revoke->href(), ['apply' => true])->status(),
            'no drop named',
        );
        self::assertSame(
            HttpStatusCode::NotFound,
            $this->signed(HttpMethod::Get, DropAction::List->href() . '/' . $id)->status(),
            'the listing takes no path',
        );
    }

    // ───────────────────────── a browser ─────────────────────────

    /**
     * A browser the admin let in makes a drop with one tap — here, text from the form's box — and every
     * field but the tap is optional.
     *
     * @return void
     */
    public function testABrowserMakesADropWithOneTap(): void
    {
        $admitted = Session::fresh($this->seal)->withAdmin(self::DEVICE, time());
        $form     = $this->browsing(TestRequest::get(DropAction::Create->href()), $admitted);

        self::assertSame(HttpStatusCode::Ok, $form->status());
        self::assertStringContainsString('enctype="multipart/form-data"', $form->body());
        self::assertStringContainsString('<textarea name="text"></textarea>', $form->body());
        self::assertStringContainsString('<input type="file" name="file">', $form->body());
        self::assertStringContainsString('<input type="checkbox" name="once" value="true">', $form->body());
        self::assertStringContainsString(
            '<input type="password" name="password" autocomplete="new-password">',
            $form->body(),
        );
        self::assertStringContainsString('<input type="text" name="lifetime">', $form->body());

        $made = $this->answer($this->tapped(DropAction::Create, $admitted, [
            [ActionField::Text, 'from a browser'],
            [ActionField::Once, 'true'],
        ]));

        self::assertSame(HttpStatusCode::Created, $made->status());
        self::assertSame('from a browser', $this->reveal(self::token($made))->body());
    }

    /**
     * A browser's one file is kept under the name it was sent with. A test of its own, because a
     * browser's write takes the second its challenge was minted as its serial, and two in one second
     * are one too many.
     *
     * @return void
     */
    public function testABrowserDropsTheFileItSends(): void
    {
        $admitted = Session::fresh($this->seal)->withAdmin(self::DEVICE, time());
        $file     = new File($this->sandbox . '/photo.jpg');
        self::assertTrue($file->write('not really a jpeg'));

        $post     = $this->tapped(DropAction::Create, $admitted)->withUpload(ActionField::File, $file, 'photo.jpg');
        $kept     = $this->answer($post);
        $revealed = $this->reveal(self::token($kept));

        self::assertSame(HttpStatusCode::Created, $kept->status());
        self::assertSame('not really a jpeg', $revealed->body());
        self::assertStringContainsString(
            'filename="photo.jpg"',
            self::headerOf($revealed, ResponseHeader::ContentDisposition),
        );
    }

    /**
     * Each action is answered by its own handler, built from the switch and the key when it is asked
     * — and one asked for once the switch is gone is refused in a sentence.
     *
     * @return void
     */
    public function testEachActionIsBuiltFromTheSwitchFile(): void
    {
        $envelope = ApiEnvelope::of(time(), HttpMethod::Post, DropAction::Create->href());
        $verified = new VerifiedRequest($envelope, '{"apply":true}', 'x');

        self::assertInstanceOf(DropCreate::class, DropAction::Create->handler($verified));
        self::assertInstanceOf(DropList::class, DropAction::List->handler($verified));
        self::assertInstanceOf(DropRevoke::class, DropAction::Revoke->handler($verified, 'x'));
        self::assertTrue(DropAction::offered(null)->isEmpty());
        self::assertCount(3, DropAction::offered(DropConfig::parse('{}')));
        self::assertTrue(DropAction::Revoke->takesPath());
        self::assertFalse(DropAction::Create->takesPath());
        self::assertSame(HttpMethod::Get, DropAction::List->method());

        (void) App::current()->dataFile(CredentialFile::Drop)->delete();

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('the drop service is off here');

        (void) DropAction::List->handler($verified);
    }

    // ───────────────────────── helpers ─────────────────────────

    /**
     * Switches the service on, with its defaults.
     *
     * @return void
     */
    private function switchOn(): void
    {
        self::assertTrue(App::current()->dataFile(CredentialFile::Drop)->write('{}'));
    }

    /**
     * What `/drop` answers a post of $token — and $password — as the page's own form or as anything
     * else.
     *
     * @param string $token
     * @param string $password
     * @param bool   $page
     * @return Answer
     */
    private function reveal(string $token, string $password = '', bool $page = false): Answer
    {
        $request = TestRequest::to(HttpMethod::Post, DropPath::Index->to())
            ->withField(DropField::Token, $token)
            ->withField(DropField::Password, $password);
        $built   = ($page ? $request->withField(DropField::Page, 'true') : $request)->request();

        return new DropController(null, new Throttle(new Directory($this->sandbox . '/throttle'), 100, 900))
            ->handle($built)
            ->answer($built);
    }

    /**
     * The token in the link an answer carries.
     *
     * @param Answer $answer
     * @return string
     */
    private static function token(Answer $answer): string
    {
        self::assertSame(
            1,
            preg_match('~/drop#([A-Za-z0-9_-]{43})~', str_replace('\\/', '/', $answer->body()), $found),
            'no link in: ' . $answer->body(),
        );

        return $found[1];
    }

    /**
     * What the admin answers a call signed with the sandbox's key.
     *
     * @param HttpMethod                     $method
     * @param string                         $path
     * @param array<string, bool|int|string> $fields
     * @param string                         $body
     * @param string                         $accept
     * @return Answer
     */
    private function signed(
        HttpMethod $method,
        string $path,
        array $fields = [],
        string $body = '',
        string $accept = 'application/json',
    ): Answer {
        $manifest = (string) json_encode([
            'serial' => ++$this->serial,
            'method' => $method->value,
            'path'   => $path,
            'digest' => hash('sha256', $body),
            'size'   => strlen($body),
            ...$fields,
        ], JSON_UNESCAPED_SLASHES);

        openssl_sign($manifest, $signature, $this->signing, OPENSSL_ALGO_SHA256);

        $request = TestRequest::to($method, $path)
            ->withServer(ServerVariable::Authorization, AuthScheme::NS1->value . ' ' . base64_encode(
                pack('N', strlen($manifest)) . $manifest . (string) $signature,
            ))
            ->with(RequestHeader::Accept, $accept);

        return $this->answer($body === '' ? $request : $request->withBody($body));
    }

    /**
     * $action's post from the browser holding $session: its form asked for, then posted with the token
     * it handed out, $fields, `apply`, and the device's answer to the challenge it minted.
     *
     * @param DropAction                    $action
     * @param Session                       $session
     * @param list<array{Parameter, string}> $fields
     * @return TestRequest
     */
    private function tapped(DropAction $action, Session $session, array $fields = []): TestRequest
    {
        $form    = $this->browsing(TestRequest::get($action->href()), $session);
        $session = $this->sessionOf($form);
        $post    = $this->carrying(TestRequest::to(HttpMethod::Post, $action->href()), $session)
            ->withField(CsrfField::Token, (string) $session->token())
            ->withField(ActionField::Apply, 'true');

        foreach ([...$fields, ...$this->assertion((string) $session->challenge()?->value)] as [$field, $value]) {
            $post = $post->withField($field, $value);
        }

        return $post;
    }

    /**
     * What the admin answers $request from the browser holding $session.
     *
     * @param TestRequest $request
     * @param Session     $session
     * @return Answer
     */
    private function browsing(TestRequest $request, Session $session): Answer
    {
        return $this->answer($this->carrying($request, $session));
    }

    /**
     * What the admin's controller answers $request, over the sandbox's key, serial and devices — the
     * address split as the router would split it.
     *
     * @param TestRequest $request
     * @return Answer
     */
    private function answer(TestRequest $request): Answer
    {
        $built    = $request->request();
        $segments = explode('/', trim($built->path(), '/'));
        $subject  = count($segments) > 4 ? rawurldecode(implode('/', array_slice($segments, 4))) : null;

        return new ApiController(
            $segments[1] ?? null,
            $segments[2] ?? null,
            $segments[3] ?? null,
            new ApiGate(new File($this->sandbox . '/update.pub'), new File($this->sandbox . '/serial')),
            new AdminBrowser(
                $this->seal,
                $this->registry,
                new Throttle(new Directory($this->sandbox . '/throttle'), 100, 900),
                Origin::of(self::ORIGIN),
            ),
            $subject,
        )->handle($built)->answer($built);
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
     * The session an answer set.
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
     * @return list<array{Parameter, string}>
     */
    private function assertion(string $challenge): array
    {
        $data   = hash('sha256', 'example.test', true) . chr(0x05) . pack('N', 1);
        $client = (string) json_encode(
            [
                'type' => CeremonyType::Get->value,
                'challenge' => $challenge,
                'origin' => self::ORIGIN,
                'crossOrigin' => false,
            ],
            JSON_UNESCAPED_SLASHES,
        );

        openssl_sign($data . hash('sha256', $client, true), $signature, $this->device, OPENSSL_ALGO_SHA256);

        return [
            [PasskeyFormField::Credential, self::DEVICE],
            [PasskeyFormField::ClientData, Base64Url::encode($client)],
            [PasskeyFormField::AuthenticatorData, Base64Url::encode($data)],
            [PasskeyFormField::Signature, Base64Url::encode((string) $signature)],
        ];
    }

    /**
     * What $name says in an answer, or `''` where the answer does not carry it.
     *
     * @param Answer         $answer
     * @param ResponseHeader $name
     * @return string
     */
    private static function headerOf(Answer $answer, ResponseHeader $name): string
    {
        return (string) $answer->header($name)?->value->render();
    }

    /**
     * The names a listing lists.
     *
     * @param Answer $answer
     * @return list<string>
     */
    private static function names(Answer $answer): array
    {
        /** @var array{entries: list<array{name: string}>} $data */
        $data = json_decode($answer->body(), true, 16, JSON_THROW_ON_ERROR);

        return array_column($data['entries'], 'name');
    }

    /**
     * A new P-256 key.
     *
     * @return OpenSSLAsymmetricKey
     */
    private static function key(): OpenSSLAsymmetricKey
    {
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        self::assertNotFalse($key);

        return $key;
    }

    /**
     * $key's public half, as PEM.
     *
     * @param OpenSSLAsymmetricKey $key
     * @return string
     */
    private static function pem(OpenSSLAsymmetricKey $key): string
    {
        $details = openssl_pkey_get_details($key);
        self::assertIsArray($details);

        return (string) $details['key'];
    }

    /**
     * $key's public half, as the DER a browser hands over.
     *
     * @param OpenSSLAsymmetricKey $key
     * @return string
     */
    private static function der(OpenSSLAsymmetricKey $key): string
    {
        return (string) base64_decode((string) preg_replace('/-----[^-]+-----|\s+/', '', self::pem($key)), true);
    }
}
