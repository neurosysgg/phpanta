<?php

declare(strict_types=1);

namespace Phpanta\Test\Unit;

use LogicException;
use Phpanta\Http\Origin;
use Phpanta\Http\ResponseHeader;
use Phpanta\Http\ViewResponse;
use Phpanta\Model\Passkey\Passkey;
use Phpanta\Service\Passkey\PasskeyVerifier;
use Phpanta\Support\Base64Url;
use Phpanta\Support\Collection;
use Phpanta\Support\File;
use Phpanta\Test\TestRequest;
use Phpanta\Text\Translatable;
use Phpanta\Tool\Cli\ExitCode;
use Phpanta\Tool\Cli\Output;
use Phpanta\Tool\Cli\Runner;
use Phpanta\Tool\Cli\UsageException;
use Phpanta\Tool\Command\Authenticator;
use Phpanta\Tool\Http\CookieJar;
use Phpanta\Tool\Http\FormField;
use Phpanta\Tool\Http\Request;
use Phpanta\Tool\Http\Response;
use Phpanta\Tool\Http\Transport;
use Phpanta\Tool\Passkey\AdminPage;
use Phpanta\Tool\Passkey\SoftwareDevice;
use Phpanta\View\AdminEnrolmentView;
use Phpanta\View\AdminEntranceView;
use PHPUnit\Framework\TestCase;

/**
 * The software authenticator's parts, each against the server's own half: a device's answers are
 * put to the real {@link PasskeyVerifier}, and the page reader reads the admin's real views.
 *
 * The walk itself is proved over real HTTP, against a local copy — what it is for. No
 * `#[CoversClass]`, like every other test of `tools/`: that layer is outside the coverage source.
 */
final class AuthenticatorTest extends TestCase
{
    private const string ORIGIN = 'https://app.localhost';

    private const string CHALLENGE = 'Y2hhbGxlbmdl';

    private string $sandbox = '';

    /**
     * @return void
     */
    protected function setUp(): void
    {
        $this->sandbox = sys_get_temp_dir() . '/phpanta-device-' . bin2hex(random_bytes(6));
    }

    /**
     * @return void
     */
    protected function tearDown(): void
    {
        UpdateFixture::removeTree($this->sandbox);
    }

    /**
     * A device registers and answers the way the admin's own verifier accepts, and only on its origin.
     *
     * @return void
     */
    public function testADevicesAnswersAreOnesTheVerifierAccepts(): void
    {
        $device   = SoftwareDevice::mint();
        $verifier = new PasskeyVerifier(Origin::of(self::ORIGIN));
        $created  = self::sent($device->registration(self::CHALLENGE, Origin::of(self::ORIGIN)));
        $asserted = self::sent($device->assertion(self::CHALLENGE, Origin::of(self::ORIGIN)));
        $elsewhere = self::sent($device->assertion(self::CHALLENGE, Origin::of('https://other.localhost')));
        $passkey  = new Passkey($device->credential, 'test', $device->publicKey());

        self::assertSame($device->credential, $created['credential']);
        self::assertTrue($verifier->registers(
            $created['authenticator-data'],
            $created['client-data'],
            $created['key'],
            self::CHALLENGE,
        ));
        self::assertSame(0, $verifier->asserts(
            $passkey,
            $asserted['authenticator-data'],
            $asserted['client-data'],
            $asserted['signature'],
            self::CHALLENGE,
        ));
        self::assertNull($verifier->asserts(
            $passkey,
            $elsewhere['authenticator-data'],
            $elsewhere['client-data'],
            $elsewhere['signature'],
            self::CHALLENGE,
        ));
        self::assertMatchesRegularExpression('/\A[0-9a-f]{4}(?: [0-9a-f]{4}){3}\z/', $device->fingerprint());
    }

    /**
     * A device kept in a file comes back as the same device, readable by its owner alone; a file that
     * is not one is refused, and no file is no device.
     *
     * @return void
     */
    public function testADeviceIsKeptAndReadBack(): void
    {
        $file   = new File($this->sandbox . '/devices/device.json');
        $device = SoftwareDevice::mint();

        self::assertNull(SoftwareDevice::fromFile($file));
        self::assertTrue($device->keepIn($file));
        self::assertSame(0o600, $file->permissions());

        $again = SoftwareDevice::fromFile($file);

        self::assertSame($device->credential, $again?->credential);
        self::assertSame($device->publicKey(), $again->publicKey());

        self::assertTrue($file->write('{"credential": "not base64url!", "key": ""}'));
        $this->expectException(UsageException::class);
        (void) SoftwareDevice::fromFile($file);
    }

    /**
     * The jar keeps what a server set, replaces a cookie set again, forgets one that ended, and sends
     * the rest back as one `Cookie` header.
     *
     * @return void
     */
    public function testTheJarKeepsWhatWasSetAndSendsItBack(): void
    {
        $empty = CookieJar::empty();
        $set   = $empty->keeping(self::answer('Set-Cookie: a=1; Path=/', 'set-cookie: b=2', 'Content-Type: text/html'));
        $moved = $set->keeping(self::answer('Set-Cookie: a=; Max-Age=0; Path=/', 'Set-Cookie: b=3; Max-Age=60'));

        self::assertTrue($empty->isEmpty());
        self::assertSame('a=1; b=2', $set->render());
        self::assertSame('Cookie: a=1; b=2', $set->header()->line());
        self::assertSame('b=3', $moved->render());
        self::assertSame('a=1; b=2', $set->render(), 'keeping() changed the jar it was called on');
    }

    /**
     * An answer's headers are asked by name, in any case, every value of the name in order.
     *
     * @return void
     */
    public function testAnAnswersHeadersAreAskedByName(): void
    {
        $answer = self::answer('Set-Cookie:  one ', 'Location: /admin', 'SET-COOKIE: two');

        self::assertSame(['one', 'two'], $answer->values(ResponseHeader::SetCookie)->toValues());
        self::assertSame(['/admin'], $answer->values(ResponseHeader::Location)->toValues());
        self::assertTrue(new Response(200, '')->values(ResponseHeader::Location)->isEmpty());
    }

    /**
     * The page reader finds what the admin's own views write — the token and challenge on the
     * entrance, the code and the fingerprint on the enrolment page — and nothing on a page without them.
     *
     * @return void
     */
    public function testThePageReaderReadsTheAdminsOwnViews(): void
    {
        $entrance  = AdminPage::of(self::rendered(new ViewResponse(
            new AdminEntranceView(new Collection(Translatable::class), 'the-token', self::CHALLENGE),
        )));
        $enrolment = AdminPage::of(self::rendered(new ViewResponse(new AdminEnrolmentView('the.code', 'ab12 cd34'))));
        $nothing   = AdminPage::of('');

        self::assertSame('the-token', $entrance->token());
        self::assertSame(self::CHALLENGE, $entrance->challenge());
        self::assertNull($entrance->enrolmentCode());
        self::assertSame('the.code', $enrolment->enrolmentCode());
        self::assertStringContainsString('ab12 cd34', $enrolment->said());
        self::assertNotSame('', $enrolment->text());
        self::assertNull($nothing->token());
        self::assertNull($nothing->challenge());
    }

    /**
     * The command plays a device against a local copy and nothing else, and needs to be told which.
     *
     * @return void
     */
    public function testTheCommandRefusesAnyOriginButALocalOne(): void
    {
        $never = new class () implements Transport {
            /**
             * @param Request $request
             * @return never
             */
            public function send(Request $request): never
            {
                throw new LogicException('the command sent a request to an origin it should have refused');
            }
        };

        foreach (
            [
                [['--url', 'https://example.test'], 'not a local copy'],
                [['--url', 'https://localhost.example.test'], 'not a local copy'],
                [[], 'needs --url'],
            ] as [$arguments, $said]
        ) {
            $error = fopen('php://memory', 'rw+');
            self::assertIsResource($error);

            $code = Runner::execute(
                new Authenticator('https://example.test', 'update.key', $never),
                $arguments,
                new Output(fopen('php://memory', 'rw+'), $error),
            );

            rewind($error);

            self::assertSame(ExitCode::Usage, $code);
            self::assertStringContainsString($said, (string) stream_get_contents($error));
        }
    }

    /**
     * What a posted form sends, by field name, each value decoded where it is base64url.
     *
     * @param Collection<FormField> $fields
     * @return array<string, string>
     */
    private static function sent(Collection $fields): array
    {
        $sent = [];

        foreach ($fields as $field) {
            $value = (string) $field->value;

            $sent[$field->name] = $field->name === 'credential' ? $value : (string) Base64Url::decode($value);
        }

        return $sent;
    }

    /**
     * An answer carrying $lines as its headers.
     *
     * @param string ...$lines
     * @return Response
     */
    private static function answer(string ...$lines): Response
    {
        return new Response(200, '', new Collection('string')->with(...$lines));
    }

    /**
     * $response's body, as the booted app answers it.
     *
     * @param ViewResponse $response
     * @return string
     */
    private static function rendered(ViewResponse $response): string
    {
        return $response->answer(TestRequest::get('/admin')->request())->body();
    }
}
