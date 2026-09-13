<?php

declare(strict_types=1);

namespace Phpanta\Test\Unit;

use Phpanta\Controller\Controller;
use Phpanta\Exception\InputException;
use Phpanta\Exception\MimeTypeException;
use Phpanta\Exception\TooLargeException;
use Phpanta\Exception\UploadException;
use Phpanta\Http\FileEntryKey;
use Phpanta\Http\FormEncoding;
use Phpanta\Http\HttpMethod;
use Phpanta\Http\HttpStatusCode;
use Phpanta\Http\MimeType;
use Phpanta\Http\MultipartParameters;
use Phpanta\Http\PlainTextResponse;
use Phpanta\Http\Request;
use Phpanta\Http\Response;
use Phpanta\Http\ServerParameters;
use Phpanta\Http\ServerVariable;
use Phpanta\Http\Upload;
use Phpanta\Model\Health\ByteFloor;
use Phpanta\Model\Health\PhpSetting;
use Phpanta\Model\Health\Requirement;
use Phpanta\Model\Health\SettingRequirement;
use Phpanta\Model\Health\Toggle;
use Phpanta\Router;
use Phpanta\Support\Collection;
use Phpanta\Support\File;
use Phpanta\Support\MethodSet;
use Phpanta\Support\Route;
use Phpanta\Test\TestRequest;
use Phpanta\Text\FrameworkText;
use Phpanta\Text\Language;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A file a form sent: what PHP kept, what it refused and why, and the file kept where the page
 * wants it — or left where it was.
 */
#[CoversClass(Upload::class)]
#[CoversClass(MultipartParameters::class)]
#[CoversClass(FileEntryKey::class)]
#[CoversClass(FormEncoding::class)]
#[CoversClass(MimeType::class)]
#[CoversClass(Request::class)]
#[CoversClass(Router::class)]
#[CoversClass(File::class)]
#[CoversClass(FrameworkText::class)]
final class UploadTest extends TestCase
{
    /** A directory of this test's own: PHP's temporary files, and where they are kept. */
    private string $scratch;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        $this->scratch = sys_get_temp_dir() . '/phpanta-upload-' . bin2hex(random_bytes(6));
        mkdir($this->scratch);
    }

    /**
     * @return void
     */
    protected function tearDown(): void
    {
        foreach (scandir($this->scratch) ?: [] as $name) {
            if ($name !== '.' && $name !== '..') {
                $path = "$this->scratch/$name";
                is_dir($path) ? rmdir($path) : unlink($path);
            }
        }

        rmdir($this->scratch);
    }

    // ───────────────────────── what arrived ─────────────────────────

    /**
     * A file PHP kept is what the browser called it, how long it is, and its bytes.
     *
     * @return void
     */
    public function testAnUploadIsWhatPhpKept(): void
    {
        $upload = $this->sending($this->temporary('hello'), 'hello.txt')->request()->upload(UploadFieldFixture::File);

        self::assertInstanceOf(Upload::class, $upload);
        self::assertSame('hello.txt', $upload->clientName());
        self::assertSame(5, $upload->size());
        self::assertSame('hello', $upload->contents());
        self::assertSame('he', $upload->contents(2));
    }

    /**
     * No file is null, however it came to be none: not a multipart form, a form without that name,
     * a file input left empty.
     *
     * @return void
     */
    public function testNoFileIsNoUpload(): void
    {
        $form = TestRequest::to(HttpMethod::Post, '/')
            ->withServer(ServerVariable::ContentType, FormEncoding::UrlEncoded->value)
            ->withBody('file=a.txt');

        self::assertNull($form->request()->upload(UploadFieldFixture::File));
        self::assertNull(
            TestRequest::to(HttpMethod::Post, '/')
                ->withField(UploadFieldFixture::Caption, 'hi')
                ->request()
                ->upload(UploadFieldFixture::File),
        );
        self::assertNull(
            $this->sending($this->temporary(''), '', UPLOAD_ERR_NO_FILE)->request()->upload(UploadFieldFixture::File),
        );
    }

    /**
     * A file PHP did not keep is refused loudly, and by whose fault: too large is a 413, arrived in
     * part is a 400, and the host failing to keep it is the host's — a 500.
     *
     * @param int                                   $error
     * @param class-string<\Phpanta\Exception\SiteException> $exception
     * @return void
     */
    #[DataProvider('refusalProvider')]
    public function testAFilePhpDidNotKeepIsRefused(int $error, string $exception): void
    {
        $this->expectException($exception);

        (void) $this->sending($this->temporary('x'), 'x.txt', $error)->request()->upload(UploadFieldFixture::File);
    }

    /**
     * @return iterable<string, array{int, class-string}>
     */
    public static function refusalProvider(): iterable
    {
        yield 'over upload_max_filesize'  => [UPLOAD_ERR_INI_SIZE, TooLargeException::class];
        yield 'over the form\'s own size' => [UPLOAD_ERR_FORM_SIZE, TooLargeException::class];
        yield 'in part'                   => [UPLOAD_ERR_PARTIAL, InputException::class];
        yield 'no temporary directory'    => [UPLOAD_ERR_NO_TMP_DIR, UploadException::class];
        yield 'a failed write'            => [UPLOAD_ERR_CANT_WRITE, UploadException::class];
        yield 'an extension stopped it'   => [UPLOAD_ERR_EXTENSION, UploadException::class];
    }

    /**
     * One file input that took several is refused, as a name sent twice is.
     *
     * @return void
     */
    public function testMoreThanOneFileUnderOneNameIsRefused(): void
    {
        $request = Request::from(
            new ServerParameters([ServerVariable::ContentType->value => FormEncoding::Multipart->value]),
            null,
            new MultipartParameters([], [
                'file' => [
                    FileEntryKey::Name->value    => ['a.txt', 'b.txt'],
                    FileEntryKey::TmpName->value => ['/tmp/a', '/tmp/b'],
                    FileEntryKey::Error->value   => [UPLOAD_ERR_OK, UPLOAD_ERR_OK],
                    FileEntryKey::Size->value    => [1, 1],
                ],
            ]),
        );

        $this->expectException(InputException::class);
        $this->expectExceptionMessage("'file' sent more than one file.");

        (void) $request->upload(UploadFieldFixture::File);
    }

    /**
     * A name the browser gave that is not UTF-8 is refused, since the page would show it.
     *
     * @return void
     */
    public function testAClientNameThatIsNotUtf8IsRefused(): void
    {
        $this->expectException(InputException::class);

        (void) $this->sending($this->temporary('x'), "\xFF.txt")->request()->upload(UploadFieldFixture::File);
    }

    /**
     * What a request carries outside a test: nothing, under the CLI.
     *
     * @return void
     */
    public function testTheDoorHoldsWhatPhpParsed(): void
    {
        $posted = MultipartParameters::fromGlobals();

        self::assertTrue($posted->fields()->isEmpty());
        self::assertTrue($posted->lists()->isEmpty());
    }

    // ───────────────────────── too large ─────────────────────────

    /**
     * A file larger than the host takes, read by a page, is a 413 the router sends — the page
     * never checks.
     *
     * @return void
     */
    public function testAFileTooLargeForTheHostIsA413(): void
    {
        $answer = self::answered($this->sending($this->temporary('x'), 'x.txt', UPLOAD_ERR_INI_SIZE)->request());

        self::assertSame(HttpStatusCode::ContentTooLarge, $answer->status());
        self::assertSame(FrameworkText::ContentTooLarge->in(Language::English) . "\n", $answer->body());
    }

    /**
     * A multipart form longer than `post_max_size` — which PHP empties in silence — is a 413 too,
     * rather than a form that seems to have sent nothing.
     *
     * @return void
     */
    public function testAFormPhpEmptiedIsA413RatherThanBlank(): void
    {
        $limit = ini_parse_quantity((string) ini_get('post_max_size'));

        if ($limit <= 0) {
            self::markTestSkipped('this PHP sets no post_max_size');
        }

        $request = TestRequest::to(HttpMethod::Post, '/')
            ->withField(UploadFieldFixture::Caption, 'hi')
            ->withServer(ServerVariable::ContentLength, (string) ($limit + 1))
            ->request();

        self::assertSame(HttpStatusCode::ContentTooLarge, self::answered($request)->status());

        $this->expectException(TooLargeException::class);

        (void) $request->upload(UploadFieldFixture::File);
    }

    // ───────────────────────── keeping it ─────────────────────────

    /**
     * A kept file is moved into place with the mode asked for, and is no longer where PHP put it.
     *
     * @return void
     */
    public function testAKeptFileIsMovedIntoPlace(): void
    {
        $sent   = $this->temporary('bytes');
        $upload = new Upload('a.txt', $sent, 5);
        $target = new File("$this->scratch/kept");

        self::assertTrue($upload->keepAs($target, 0o600));
        self::assertSame('bytes', $target->read());
        self::assertSame(0o600, fileperms($target->path) & 0o777);
        self::assertFalse($sent->exists());
        self::assertNull($upload->contents());
        self::assertFalse($upload->keepAs(new File("$this->scratch/again")), 'a file is kept once');
        self::assertSame([], glob("$this->scratch/*.tmp") ?: []);
    }

    /**
     * Keeping replaces what was there, and keeps the mode PHP gave the file where none is asked for.
     *
     * @return void
     */
    public function testAKeptFileReplacesWhatWasThere(): void
    {
        $sent = $this->temporary('new');
        chmod($sent->path, 0o640);
        $target = new File("$this->scratch/kept");
        file_put_contents($target->path, 'old');

        self::assertTrue(new Upload('a.txt', $sent, 3)->keepAs($target));
        self::assertSame('new', $target->read());
        self::assertSame(0o640, fileperms($target->path) & 0o777);
    }

    /**
     * A file that cannot be kept is left where it was — for the page to keep elsewhere, or for PHP
     * to delete — and nothing is left beside the target.
     *
     * @param string $target Where it is asked to go, under the scratch directory.
     * @return void
     */
    #[DataProvider('unkeepableProvider')]
    public function testAFileThatCannotBeKeptStaysWhereItWas(string $target): void
    {
        mkdir("$this->scratch/a-directory");
        $sent = $this->temporary('bytes');

        self::assertFalse(new Upload('a.txt', $sent, 5)->keepAs(new File("$this->scratch/$target")));
        self::assertSame('bytes', $sent->read());
        self::assertSame([], glob("$this->scratch/*.tmp") ?: []);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unkeepableProvider(): iterable
    {
        yield 'a directory that is not there' => ['nowhere/kept'];
        yield 'a directory in the way'        => ['a-directory'];
    }

    // ───────────────────────── the host ─────────────────────────

    /**
     * What a site that takes uploads lists: the three settings that each decide whether a file
     * arrives, every floor the size it takes.
     *
     * @return void
     */
    public function testTheHostRequirementsAreTheThreeThatDecideWhetherAFileArrives(): void
    {
        $requirements = Upload::requirements(1000)->toValues();

        self::assertSame(
            [PhpSetting::FileUploads->value, PhpSetting::UploadMaxFilesize->value, PhpSetting::PostMaxSize->value],
            new Collection(Requirement::class)->with(...$requirements)
                ->map(static fn(Requirement $requirement): string => $requirement->name())
                ->toValues(),
        );

        [$switch, $file, $form] = $requirements;

        self::assertInstanceOf(SettingRequirement::class, $switch);
        self::assertInstanceOf(SettingRequirement::class, $file);
        self::assertInstanceOf(SettingRequirement::class, $form);
        self::assertSame(Toggle::On, $switch->constraint);
        self::assertEquals(new ByteFloor(1000), $file->constraint);
        self::assertEquals(new ByteFloor(1000, 0), $form->constraint);
    }

    // ───────────────────────── the encoding ─────────────────────────

    /**
     * Each encoding is the type it names, checked as one, with no charset.
     *
     * @return void
     */
    public function testEachEncodingIsTheTypeItNames(): void
    {
        foreach (FormEncoding::cases() as $encoding) {
            self::assertSame($encoding->value, $encoding->mimeType()->essence());
            self::assertSame($encoding->value, $encoding->mimeType()->render());
        }
    }

    /**
     * An essence that does not open with a type this knows is no type.
     *
     * @return void
     */
    public function testAnEssenceOfNoKnownTypeIsRefused(): void
    {
        $this->expectException(MimeTypeException::class);

        (void) MimeType::fromEssence('nonsense/x');
    }

    // ───────────────────────── helpers ─────────────────────────

    /**
     * A file as PHP would have kept one, holding $contents.
     *
     * @param string $contents
     * @return File
     */
    private function temporary(string $contents): File
    {
        $file = new File("$this->scratch/php" . bin2hex(random_bytes(4)));
        file_put_contents($file->path, $contents);

        return $file;
    }

    /**
     * A POST sending $file as the fixture's file field, under $name.
     *
     * @param File   $file
     * @param string $name
     * @param int    $error
     * @return TestRequest
     */
    private function sending(File $file, string $name, int $error = UPLOAD_ERR_OK): TestRequest
    {
        return TestRequest::to(HttpMethod::Post, '/')->withUpload(UploadFieldFixture::File, $file, $name, $error);
    }

    /**
     * What a route whose page reads the form and its file answers $request with.
     *
     * @param Request $request
     * @return \Phpanta\Http\Answer
     */
    private static function answered(Request $request): \Phpanta\Http\Answer
    {
        $router = new Router(new Collection(Route::class)->with(
            new Route(
                ExportFixturePath::Home,
                static fn(): Controller => new class () implements Controller {
                    /**
                     * @param Request $request
                     * @return Response
                     */
                    public function handle(Request $request): Response
                    {
                        (void) $request->form();
                        (void) $request->upload(UploadFieldFixture::File);

                        return new PlainTextResponse(HttpStatusCode::Ok, 'read');
                    }
                },
                MethodSet::of(HttpMethod::Post),
            ),
        ));

        return $router->dispatch($request)->answer($request);
    }
}
