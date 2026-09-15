<?php

declare(strict_types=1);

namespace Phpanta\Test\Unit;

use OpenSSLAsymmetricKey;
use Phpanta\App;
use Phpanta\Controller\ApiController;
use Phpanta\CredentialFile;
use Phpanta\Exception\ApiException;
use Phpanta\Exception\InputException;
use Phpanta\Exception\RouteException;
use Phpanta\Http\Answer;
use Phpanta\Http\Api\ActionField;
use Phpanta\Http\Api\AdminHeaders;
use Phpanta\Http\Api\ApiListing;
use Phpanta\Http\Api\ApiResult;
use Phpanta\Http\Api\ApiService;
use Phpanta\Http\Api\ListingEntry;
use Phpanta\Http\Api\MachineAction;
use Phpanta\Http\Api\ResultFile;
use Phpanta\Http\AuthScheme;
use Phpanta\Http\ContentDisposition;
use Phpanta\Http\CsrfField;
use Phpanta\Http\EmptyResponse;
use Phpanta\Http\FileEntryKey;
use Phpanta\Http\HttpMethod;
use Phpanta\Http\HttpStatusCode;
use Phpanta\Http\MultipartParameters;
use Phpanta\Http\Origin;
use Phpanta\Http\Parameter;
use Phpanta\Http\PasskeyFormField;
use Phpanta\Http\Request;
use Phpanta\Http\RequestHeader;
use Phpanta\Http\ResponseHeader;
use Phpanta\Http\ServerVariable;
use Phpanta\Http\Session;
use Phpanta\Http\SessionSeal;
use Phpanta\Model\Api\ApiEnvelope;
use Phpanta\Model\Api\VerifiedRequest;
use Phpanta\Model\Machine\MachineConfig;
use Phpanta\Model\Passkey\CeremonyType;
use Phpanta\Model\Passkey\Passkey;
use Phpanta\Service\Api\MachineBytes;
use Phpanta\Service\Api\MachineDelete;
use Phpanta\Service\Api\MachineFiles;
use Phpanta\Service\Api\MachineFolder;
use Phpanta\Service\Api\MachineProcesses;
use Phpanta\Service\Api\MachineRename;
use Phpanta\Service\Api\MachineRun;
use Phpanta\Service\Api\MachineSystem;
use Phpanta\Service\Api\MachineUpload;
use Phpanta\Service\ApiGate;
use Phpanta\Service\Passkey\AdminBrowser;
use Phpanta\Service\Passkey\PasskeyRegistry;
use Phpanta\Support\AdminPath;
use Phpanta\Support\Base64Url;
use Phpanta\Support\Directory;
use Phpanta\Support\File;
use Phpanta\Support\PlaceholderType;
use Phpanta\Support\Throttle;
use Phpanta\Test\TestRequest;
use Phpanta\View\AdminForm;
use Phpanta\View\ApiActionFormView;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The `machine` service as the admin serves it: there only where `data/machine.json` switches it on,
 * reached by a signed call or a browser the admin let in, with the path after an action as part of
 * the address — and a browser's upload, several files under one tap.
 *
 * The file is the test app's own `data/machine.json`, written for each test and taken away after it,
 * with the service's root a sandbox. The gate verifies against a key made here, and the browser's
 * passkey is a key made here too; see {@link AdminBrowserTest} for how its ceremonies are built.
 */
#[CoversClass(ApiController::class)]
#[CoversClass(ApiListing::class)]
#[CoversClass(ListingEntry::class)]
#[CoversClass(ApiService::class)]
#[CoversClass(MachineAction::class)]
#[CoversClass(MachineConfig::class)]
#[CoversClass(ApiResult::class)]
#[CoversClass(ResultFile::class)]
#[CoversClass(ContentDisposition::class)]
#[CoversClass(AdminHeaders::class)]
#[CoversClass(ApiActionFormView::class)]
#[CoversClass(AdminForm::class)]
#[CoversClass(AdminBrowser::class)]
#[CoversClass(VerifiedRequest::class)]
#[CoversClass(MultipartParameters::class)]
#[CoversClass(Request::class)]
#[CoversClass(PlaceholderType::class)]
#[CoversClass(AdminPath::class)]
#[CoversClass(ActionField::class)]
final class MachineAdminTest extends TestCase
{
    private const string ORIGIN = 'https://example.test';

    /** A credential id as a browser writes one. */
    private const string DEVICE = 'ZGV2aWNl';

    /** Whether this suite made the test app's `data/`, and so takes it away again. */
    private static bool $madeData = false;

    private string $sandbox = '';
    private string $root = '';
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
     * A root with a file and a picture in it, a signing key and its public half, and a device enrolled.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->sandbox = (string) realpath(Directory::temporary('phpanta-machine-admin-')->path);
        $this->root    = $this->sandbox . '/root';

        self::assertTrue(new Directory($this->root)->create());
        self::assertTrue(new File($this->root . '/readme.txt')->write("hello\n"));
        self::assertTrue(new File($this->root . '/photo.png')->write("\x89PNG\r\n\x1a\nbytes"));

        $this->signing = self::key();
        $this->device  = self::key();
        $this->seal    = SessionSeal::fromKey(random_bytes(32));
        $this->registry = new PasskeyRegistry(new File($this->sandbox . '/admin-passkeys.json'));

        self::assertTrue(new File($this->sandbox . '/update.pub')->write(self::pem($this->signing)));
        self::assertTrue($this->registry->keep(new Passkey(self::DEVICE, 'phone', self::der($this->device))));
        self::assertTrue(new Directory($this->sandbox . '/throttle')->create());

        $this->switchOn();
    }

    /**
     * @return void
     */
    protected function tearDown(): void
    {
        (void) App::current()->dataFile(CredentialFile::Machine)->delete();

        if ($this->sandbox !== '') {
            UpdateFixture::removeTree($this->sandbox);
        }
    }

    // ───────────────────────── there, or not ─────────────────────────

    /**
     * With no file the service is not named and not there; with one, it is listed at every depth, and
     * what it offers is what the file lets it.
     *
     * @return void
     */
    public function testTheServiceIsThereOnlyWhereItIsSwitchedOn(): void
    {
        (void) App::current()->dataFile(CredentialFile::Machine)->delete();

        self::assertNotContains('machine', self::names($this->signed(HttpMethod::Get, '/admin')));
        self::assertSame(HttpStatusCode::NotFound, $this->signed(HttpMethod::Get, '/admin/machine')->status());
        self::assertSame(HttpStatusCode::NotFound, $this->signed(HttpMethod::Get, '/admin/machine/v1')->status());
        self::assertSame(
            HttpStatusCode::NotFound,
            $this->signed(HttpMethod::Get, '/admin/machine/v1/system')->status(),
        );

        $this->switchOn(commands: true);

        self::assertContains('machine', self::names($this->signed(HttpMethod::Get, '/admin')));
        self::assertSame(['v1'], self::names($this->signed(HttpMethod::Get, '/admin/machine')));
        self::assertSame(
            array_map(static fn(MachineAction $action): string => $action->value, MachineAction::cases()),
            self::names($this->signed(HttpMethod::Get, '/admin/machine/v1')),
        );

        $this->switchOn(writes: false);

        self::assertSame(
            ['system', 'processes', 'files', 'raw', 'download'],
            self::names($this->signed(HttpMethod::Get, '/admin/machine/v1')),
        );
        $folder = $this->signed(HttpMethod::Post, $this->at(MachineAction::Folder), ['apply' => true, 'target' => 'x']);
        self::assertSame(
            HttpStatusCode::NotFound,
            $folder->status(),
            'a write switched off is an action the admin does not have',
        );
    }

    // ───────────────────────── a signed caller ─────────────────────────

    /**
     * A signed call reads the machine: its readings, with their counters, as data; a directory's
     * entries; and a file's bytes, typed by its kind, in the part a range asks for.
     *
     * @return void
     */
    public function testASignedCallReadsTheMachine(): void
    {
        $system = self::data($this->signed(HttpMethod::Get, '/admin/machine/v1/system'));

        self::assertSame('live', $system['sections'][0]['caption']);
        self::assertArrayHasKey('cpu-total', $system['sections'][0]['counters']);

        $files = self::data($this->signed(HttpMethod::Get, $this->at(MachineAction::Files)));

        self::assertSame(['photo.png', 'readme.txt'], array_column($files['sections'][0]['entries'], 'name'));

        $photo = $this->signed(HttpMethod::Get, $this->at(MachineAction::Raw, '/photo.png'));

        self::assertSame(HttpStatusCode::Ok, $photo->status());
        self::assertSame("\x89PNG\r\n\x1a\nbytes", $photo->body());
        self::assertSame('image/png', $photo->header(ResponseHeader::ContentType)?->value->render());
        self::assertSame('inline', $photo->header(ResponseHeader::ContentDisposition)?->value->render());
        self::assertSame('bytes', $photo->header(ResponseHeader::AcceptRanges)?->value->render());

        $part = $this->signed(HttpMethod::Get, $this->at(MachineAction::Raw, '/readme.txt'), range: 'bytes=0-2');

        self::assertSame(HttpStatusCode::PartialContent, $part->status());
        self::assertSame('hel', $part->body());

        $saved = $this->signed(HttpMethod::Get, $this->at(MachineAction::Download, '/readme.txt'));

        self::assertStringStartsWith(
            'attachment; filename="readme.txt"',
            (string) $saved->header(ResponseHeader::ContentDisposition)?->value->render(),
        );

        $nowhere = $this->signed(HttpMethod::Get, $this->at(MachineAction::Files, '/nothing'));

        self::assertSame(HttpStatusCode::NotFound, $nowhere->status());
        self::assertStringContainsString('nothing the machine service reaches is at', $nowhere->body());
    }

    /**
     * Past an action that takes no path there is no address — a real `404` for a verified caller —
     * and a page is the same answer, in the app's shell.
     *
     * @return void
     */
    public function testAnActionThatTakesNoPathHasNoAddressPastIt(): void
    {
        $paths = ['/admin/machine/v1/system/etc', '/admin/update/v1/version/x/y'];

        foreach ($paths as $path) {
            $answer = $this->signed(HttpMethod::Get, $path);

            self::assertSame(HttpStatusCode::NotFound, $answer->status(), $path);
            self::assertStringContainsString(
                'no such API action: GET ' . substr($path, strlen('/admin/')),
                $answer->body(),
            );
        }

        $page = $this->signed(HttpMethod::Get, $this->at(MachineAction::Files), accept: 'text/html');

        self::assertSame(HttpStatusCode::Ok, $page->status());
        self::assertStringContainsString('<machine-filter hidden>', $page->body());
        self::assertStringContainsString('<title>machine/v1/files/' . ltrim($this->root, '/'), $page->body());
        self::assertStringContainsString(
            '<machine-stats data-source="/admin/machine/v1/system">',
            $this->signed(HttpMethod::Get, '/admin/machine/v1/system', accept: 'text/html')->body(),
        );
    }

    /**
     * A signed write is a write like any other: a dry run changes nothing and spends no serial, and
     * the real one does both. A signed call carries no files, so it keeps none.
     *
     * @return void
     */
    public function testASignedWriteChangesTheMachineOnce(): void
    {
        $dry = $this->signed(
            HttpMethod::Post,
            $this->at(MachineAction::Folder),
            ['apply' => false, 'target' => 'made'],
        );

        self::assertSame(HttpStatusCode::Ok, $dry->status());
        self::assertDirectoryDoesNotExist($this->root . '/made');
        self::assertNull(new File($this->sandbox . '/serial')->read());

        $made = $this->signed(
            HttpMethod::Post,
            $this->at(MachineAction::Folder),
            ['apply' => true, 'target' => 'made'],
        );

        self::assertSame(HttpStatusCode::Ok, $made->status());
        self::assertDirectoryExists($this->root . '/made');
        self::assertMatchesRegularExpression('/\A\d+\n\z/', (string) new File($this->sandbox . '/serial')->read());

        $files = $this->signed(
            HttpMethod::Post,
            $this->at(MachineAction::Upload),
            ['apply' => true],
            serial: time() + 1,
        );

        self::assertSame(HttpStatusCode::UnprocessableContent, $files->status());
        self::assertStringContainsString('no file was sent to keep', $files->body());

        $refused = $this->signed(
            HttpMethod::Post,
            $this->at(MachineAction::Rename, '/readme.txt'),
            ['apply' => true, 'target' => '../x'],
            serial: time() + 2,
        );

        self::assertSame(HttpStatusCode::UnprocessableContent, $refused->status());
        self::assertStringContainsString('refused: the rename manifest must carry a name', $refused->body());
    }

    // ───────────────────────── a browser ─────────────────────────

    /**
     * A browser the admin let in reads without a tap, and keeps files with one: the upload's form sends
     * files, several at once, and the tap carries every one of them to the directory its address names.
     *
     * @return void
     */
    public function testABrowserKeepsSeveralFilesWithOneTap(): void
    {
        $admitted = Session::fresh($this->seal)->withAdmin(self::DEVICE, time());
        $listing  = $this->browsing(TestRequest::get($this->at(MachineAction::Files)), $admitted);

        self::assertSame(HttpStatusCode::Ok, $listing->status());
        self::assertStringContainsString('href="' . $this->at(MachineAction::Upload) . '"', $listing->body());

        $form    = $this->browsing(TestRequest::get($this->at(MachineAction::Upload)), $admitted);
        $session = $this->sessionOf($form);

        self::assertStringContainsString('enctype="multipart/form-data"', $form->body());
        self::assertStringContainsString('<input type="file" name="files[]" multiple required>', $form->body());

        $one = new File($this->sandbox . '/one.txt');
        $two = new File($this->sandbox . '/two.flac');
        self::assertTrue($one->write('first'));
        self::assertTrue($two->write('second'));

        $post = $this->carrying(TestRequest::to(HttpMethod::Post, $this->at(MachineAction::Upload)), $session)
            ->withField(CsrfField::Token, (string) $session->token())
            ->withField(ActionField::Apply, 'true');

        foreach ($this->assertion((string) $session->challenge()?->value) as [$field, $value]) {
            $post = $post->withField($field, $value);
        }

        $kept = $this->answer($post->withUploads(ActionField::Files, $one, $two));

        self::assertSame(HttpStatusCode::Ok, $kept->status());
        self::assertSame('first', new File($this->root . '/one.txt')->read());
        self::assertSame('second', new File($this->root . '/two.flac')->read());
        self::assertStringContainsString('kept ' . $this->root . '/two.flac', $kept->body());
    }

    /**
     * Each action is answered by its own handler, built from what `data/machine.json` says when it is
     * asked — and one asked for once the file is gone is refused in a sentence.
     *
     * @return void
     */
    public function testEachActionIsBuiltFromTheSwitchFile(): void
    {
        $verified = new VerifiedRequest(
            ApiEnvelope::of(time(), HttpMethod::Post, '/admin/machine/v1/run'),
            '{"apply":false,"target":"x","command":"ls"}',
            '',
        );

        $handlers = [
            MachineSystem::class    => MachineAction::System,
            MachineProcesses::class => MachineAction::Processes,
            MachineFiles::class     => MachineAction::Files,
            MachineBytes::class     => MachineAction::Download,
            MachineUpload::class    => MachineAction::Upload,
            MachineFolder::class    => MachineAction::Folder,
            MachineRename::class    => MachineAction::Rename,
            MachineDelete::class    => MachineAction::Delete,
            MachineRun::class       => MachineAction::Run,
        ];

        foreach ($handlers as $class => $action) {
            self::assertInstanceOf($class, $action->handler($verified, 'x'));
        }

        (void) App::current()->dataFile(CredentialFile::Machine)->delete();

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('the machine service is off here');

        (void) MachineAction::System->handler($verified);
    }

    // ───────────────────────── the plumbing ─────────────────────────

    /**
     * One file input that took several arrives as a list, which is read file by file — an empty one
     * left out — and a list nested deeper is refused; a plain file is a list of one, and nothing sent
     * is none.
     *
     * @return void
     */
    public function testSeveralFilesArriveAsOneList(): void
    {
        $one   = new File($this->sandbox . '/a');
        self::assertTrue($one->write('a'));
        $name  = FileEntryKey::Name->value;
        $tmp   = FileEntryKey::TmpName->value;
        $error = FileEntryKey::Error->value;
        $size  = FileEntryKey::Size->value;

        $list = new MultipartParameters([], ['files' => [
            $name  => ['a', ''],
            $tmp   => [$one->path, ''],
            $error => [UPLOAD_ERR_OK, UPLOAD_ERR_NO_FILE],
            $size  => [1, 0],
        ]]);

        self::assertSame(
            ['a'],
            $list->uploads(ActionField::Files)->map(static fn($upload): string => $upload->clientName())->toValues(),
        );

        $single = new MultipartParameters([], [
            'files' => [$name => 'a', $tmp => $one->path, $error => UPLOAD_ERR_OK, $size => 1],
        ]);

        self::assertSame(1, $single->uploads(ActionField::Files)->count());
        self::assertTrue(new MultipartParameters([], [])->uploads(ActionField::Files)->isEmpty());
        self::assertTrue(
            TestRequest::get('/')->request()->uploads(ActionField::Files)->isEmpty(),
            'no multipart form sent no files',
        );

        $cases = [
            'nested deeper' => [$name => [['a']], $tmp => [['x']], $error => [[UPLOAD_ERR_OK]], $size => [[1]]],
            'unreadable'    => [$name => 'a', $tmp => 'x', $error => 'no', $size => 1],
        ];

        foreach ($cases as $case => $entry) {
            try {
                (void) new MultipartParameters([], ['files' => $entry])->uploads(ActionField::Files);
                self::fail($case);
            } catch (InputException) {
                self::assertTrue(true, $case);
            }
        }
    }

    /**
     * A path placeholder spans segments and keeps its slashes, each segment encoded — and a dot
     * segment is refused where the link is written.
     *
     * @return void
     */
    public function testAPathPlaceholderSpansSegments(): void
    {
        self::assertSame(
            '/admin/machine/v1/files/home/a%20b/%C3%BC',
            AdminPath::Subject->to('machine', 'v1', 'files', 'home/a b/ü'),
        );
        self::assertSame('a/b', PlaceholderType::Path->decode('a/b'));
        self::assertSame('a b', PlaceholderType::Path->decode('a%20b'));
        self::assertSame('a%2Fb', PlaceholderType::Segment->encode('a/b'));
        self::assertTrue(PlaceholderType::Path->accepts('a/b.c/d'));

        foreach (['', 'a//b', 'a/../b', './a', 'a/'] as $refused) {
            self::assertFalse(PlaceholderType::Path->accepts($refused), $refused);
        }

        $this->expectException(RouteException::class);

        (void) AdminPath::Subject->to('machine', 'v1', 'files', 'a/../etc');
    }

    // ───────────────────────── helpers ─────────────────────────

    /**
     * Writes the test app's `data/machine.json`, over the sandbox's root.
     *
     * @param bool $writes
     * @param bool $commands
     * @return void
     */
    private function switchOn(bool $writes = true, bool $commands = false): void
    {
        self::assertTrue(App::current()->dataFile(CredentialFile::Machine)->write((string) json_encode([
            'roots'    => [$this->root],
            'writes'   => $writes,
            'commands' => $commands,
        ])));
    }

    /**
     * Where $action is, at $relative under the root.
     *
     * @param MachineAction $action
     * @param string        $relative
     * @return string
     */
    private function at(MachineAction $action, string $relative = ''): string
    {
        return $action->href(ltrim($this->root . $relative, '/'));
    }

    /**
     * What the admin answers a call signed with the sandbox's key.
     *
     * @param HttpMethod               $method
     * @param string                   $path
     * @param array<string, mixed>     $fields
     * @param string                   $accept
     * @param string                   $range
     * @param int|null                 $serial
     * @return Answer
     */
    private function signed(
        HttpMethod $method,
        string $path,
        array $fields = [],
        string $accept = 'application/json',
        string $range = '',
        ?int $serial = null,
    ): Answer {
        $manifest = (string) json_encode([
            'serial' => $serial ?? time(),
            'method' => $method->value,
            'path'   => $path,
            'digest' => hash('sha256', ''),
            'size'   => 0,
            ...$fields,
        ], JSON_UNESCAPED_SLASHES);

        openssl_sign($manifest, $signature, $this->signing, OPENSSL_ALGO_SHA256);

        $request = TestRequest::to($method, $path)
            ->withServer(ServerVariable::Authorization, AuthScheme::NS1->value . ' ' . base64_encode(
                pack('N', strlen($manifest)) . $manifest . (string) $signature,
            ))
            ->with(RequestHeader::Accept, $accept);

        return $this->answer($range === '' ? $request : $request->with(RequestHeader::Range, $range));
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
     * The names a listing lists.
     *
     * @param Answer $answer
     * @return list<string>
     */
    private static function names(Answer $answer): array
    {
        return array_column(self::data($answer)['entries'], 'name');
    }

    /**
     * An answer's data, decoded.
     *
     * @param Answer $answer
     * @return array<string, mixed>
     */
    private static function data(Answer $answer): array
    {
        /** @var array<string, mixed> $data */
        $data = json_decode($answer->body(), true, 16, JSON_THROW_ON_ERROR);

        return $data;
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
