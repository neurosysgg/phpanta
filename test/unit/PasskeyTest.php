<?php

declare(strict_types=1);

namespace Phpanta\Test\Unit;

use OpenSSLAsymmetricKey;
use Phpanta\Http\Origin;
use Phpanta\Http\SessionSeal;
use Phpanta\Model\Passkey\AuthenticatorData;
use Phpanta\Model\Passkey\CeremonyType;
use Phpanta\Model\Passkey\Challenge;
use Phpanta\Model\Passkey\ChallengePurpose;
use Phpanta\Model\Passkey\ClientData;
use Phpanta\Model\Passkey\ClientDataKey;
use Phpanta\Model\Passkey\EnrolmentCode;
use Phpanta\Model\Passkey\Passkey;
use Phpanta\Model\Passkey\PasskeyField;
use Phpanta\Service\Passkey\PasskeyRegistry;
use Phpanta\Service\Passkey\PasskeyVerifier;
use Phpanta\Support\Base64Url;
use Phpanta\Support\Directory;
use Phpanta\Support\File;
use Phpanta\Support\PublicKey;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Passkeys: the WebAuthn answers the admin accepts, the challenges it hands out, the codes it seals, and
 * the list of devices it keeps.
 *
 * **Real keys, real signatures.** Every assertion here is signed by a P-256 key generated for the test,
 * over authenticator data and client data built the way a browser builds them, so what is proved is the
 * whole check — and every way an answer must be refused is a row of its own, since each is a way a
 * browser, a phishing page or a cloned key could hand back something that almost fits.
 */
#[CoversClass(Base64Url::class)]
#[CoversClass(Origin::class)]
#[CoversClass(AuthenticatorData::class)]
#[CoversClass(ClientData::class)]
#[CoversClass(ClientDataKey::class)]
#[CoversClass(CeremonyType::class)]
#[CoversClass(Challenge::class)]
#[CoversClass(ChallengePurpose::class)]
#[CoversClass(EnrolmentCode::class)]
#[CoversClass(Passkey::class)]
#[CoversClass(PasskeyField::class)]
#[CoversClass(PasskeyRegistry::class)]
#[CoversClass(PasskeyVerifier::class)]
#[CoversClass(SessionSeal::class)]
#[CoversClass(PublicKey::class)]
final class PasskeyTest extends TestCase
{
    private const string ORIGIN    = 'https://example.test';
    private const string CHALLENGE = 'the-challenge';

    private OpenSSLAsymmetricKey $private;
    private string $key = '';
    private string $sandbox = '';

    /**
     * A device's key, and a directory for a store.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->private = self::p256();
        $this->key     = self::der($this->private);
        $this->sandbox = sys_get_temp_dir() . '/phpanta-passkey-' . bin2hex(random_bytes(6));
        new Directory($this->sandbox)->create();
    }

    /**
     * @return void
     */
    protected function tearDown(): void
    {
        UpdateFixture::removeTree($this->sandbox);
    }

    // ───────────────────────────── base64url and the origin ─────────────────────────────

    /**
     * Bytes round-trip, the alphabet is the URL-safe one, and what no encoder writes is nothing.
     *
     * @return void
     */
    public function testBase64UrlRoundTripsAndRefusesWhatNoEncoderWrites(): void
    {
        foreach (['', "\0", "\xfb\xff", random_bytes(33)] as $bytes) {
            self::assertSame($bytes, Base64Url::decode(Base64Url::encode($bytes)));
        }

        self::assertSame('-_8', Base64Url::encode("\xfb\xff"));

        foreach (['a+b', 'ab==', 'abcde', "ab\n", 'a b'] as $text) {
            self::assertNull(Base64Url::decode($text), $text);
        }
    }

    /**
     * The relying party is the origin's host: no scheme, no port.
     *
     * @return void
     */
    public function testTheRelyingPartyIsTheHostAlone(): void
    {
        self::assertSame('example.test', Origin::of(self::ORIGIN)->host());
        self::assertSame('localhost', Origin::of('http://localhost:8080')->host());
    }

    // ───────────────────────────── an assertion ─────────────────────────────

    /**
     * An enrolled key's assertion over the challenge, on this origin, by a verified person, is the
     * count it reported — and a key that does not count is accepted at zero.
     *
     * @return void
     */
    public function testAnAssertionByAnEnrolledKeyVerifies(): void
    {
        $verifier = new PasskeyVerifier(Origin::of(self::ORIGIN));
        $client   = self::client(CeremonyType::Get);
        $counted  = self::authenticator(count: 7);
        $uncount  = self::authenticator();

        $signed = $this->sign($counted, $client);
        $plain  = $this->sign($uncount, $client);
        $rising = $verifier->asserts($this->passkey(3), $counted, $client, $signed, self::CHALLENGE);
        $never  = $verifier->asserts($this->passkey(), $uncount, $client, $plain, self::CHALLENGE);

        self::assertSame(7, $rising);
        self::assertSame(0, $never);
    }

    /**
     * @param string $case
     * @return void
     */
    #[DataProvider('refusedAssertionProvider')]
    public function testAnAssertionThatAlmostFitsIsRefused(string $case): void
    {
        $flags  = match ($case) {
            'nobody present'  => 0x04,
            'nobody verified' => 0x01,
            default           => 0x05,
        };
        $stored = match ($case) {
            'a count that went back' => 3,
            'a count that stopped'   => 5,
            default                  => 0,
        };
        $relying = $case === 'another relying party' ? 'evil.test' : 'example.test';
        $data    = $case === 'bytes too short' ? 'short' : self::authenticator($flags, $stored === 3 ? 3 : 0, $relying);

        $client = match ($case) {
            'another challenge'       => self::client(CeremonyType::Get, 'another'),
            'another origin'          => self::client(CeremonyType::Get, self::CHALLENGE, 'https://evil.test'),
            'a registration'          => self::client(CeremonyType::Create),
            'in another frame'        => self::client(CeremonyType::Get, self::CHALLENGE, self::ORIGIN, true),
            'client data that is not' => '{',
            default                   => self::client(CeremonyType::Get),
        };

        $signature = $case === 'signed by another key'
            ? self::signWith(self::p256(), $data, $client)
            : $this->sign($data, $client);

        $passkey  = $case === 'a key that is not one'
            ? new Passkey('id', 'phone', 'not a key')
            : $this->passkey($stored);
        $verifier = new PasskeyVerifier(Origin::of(self::ORIGIN));

        self::assertNull($verifier->asserts($passkey, $data, $client, $signature, self::CHALLENGE));
    }

    /**
     * Every way an answer can almost fit: another ceremony, challenge, origin, frame or relying party;
     * nobody there; a cloned key's count; another key; bytes that are not what they claim.
     *
     * @return iterable<string, array{string}>
     */
    public static function refusedAssertionProvider(): iterable
    {
        $cases = [
            'another challenge',
            'another origin',
            'a registration',
            'in another frame',
            'another relying party',
            'nobody present',
            'nobody verified',
            'a count that went back',
            'a count that stopped',
            'signed by another key',
            'bytes too short',
            'client data that is not',
            'a key that is not one',
        ];

        foreach ($cases as $case) {
            yield $case => [$case];
        }
    }

    // ───────────────────────────── a registration ─────────────────────────────

    /**
     * A registration of a P-256 key, attested, over the challenge, is accepted; anything else is not.
     *
     * @return void
     */
    public function testARegistrationOfAP256KeyIsAcceptedAndNothingElse(): void
    {
        $verifier = new PasskeyVerifier(Origin::of(self::ORIGIN));
        $data     = self::authenticator(0x45);
        $client   = self::client(CeremonyType::Create);
        $rsa      = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]);

        self::assertNotFalse($rsa);
        self::assertTrue($verifier->registers($data, $client, $this->key, self::CHALLENGE));
        $unlock = self::client(CeremonyType::Get);

        self::assertFalse($verifier->registers(self::authenticator(), $client, $this->key, self::CHALLENGE), 'no key');
        self::assertFalse($verifier->registers($data, $unlock, $this->key, self::CHALLENGE), 'an unlock');
        self::assertFalse($verifier->registers($data, $client, self::der($rsa), self::CHALLENGE), 'an RSA key');
        self::assertFalse($verifier->registers('short', $client, $this->key, self::CHALLENGE), 'too short');
    }

    // ───────────────────────────── client data ─────────────────────────────

    /**
     * Client data reads its four members; anything that is not a client data object is nothing.
     *
     * @return void
     */
    public function testClientDataIsReadOrIsNothing(): void
    {
        $read = ClientData::parse(self::client(CeremonyType::Get));

        self::assertNotNull($read);
        self::assertSame([CeremonyType::Get->value, self::CHALLENGE, self::ORIGIN, false], [
            $read->type,
            $read->challenge,
            $read->origin,
            $read->crossOrigin,
        ]);
        self::assertFalse(ClientData::parse('{"type":"t","challenge":"c","origin":"o"}')?->crossOrigin);

        $notClientData = [
            '{',
            '[]',
            '{"type":"t","challenge":"c"}',
            '{"type":"t","challenge":"c","origin":"o","crossOrigin":"no"}',
        ];

        foreach ($notClientData as $json) {
            self::assertNull(ClientData::parse($json), $json);
        }
    }

    // ───────────────────────────── a challenge ─────────────────────────────

    /**
     * A challenge is kept whole, answerable for its purpose and binding until it expires, and nothing
     * a session did not keep reads as one.
     *
     * @return void
     */
    public function testAChallengeIsKeptAndAnswerableOnlyForWhatItWasMintedFor(): void
    {
        $bound     = 'POST /admin/update/v1/rollback';
        $challenge = Challenge::mint(ChallengePurpose::Write, 1000, $bound);
        $kept      = Challenge::fromStored($challenge->stored());

        self::assertEquals($challenge, $kept);
        self::assertSame(43, strlen($challenge->value));
        self::assertNotSame($challenge->value, Challenge::mint(ChallengePurpose::Write, 1000, $bound)->value);
        self::assertTrue($kept?->expects(ChallengePurpose::Write, 1000 + Challenge::LIFETIME, $bound));
        self::assertFalse($kept?->expects(ChallengePurpose::Write, 1001 + Challenge::LIFETIME, $bound), 'expired');
        self::assertFalse($kept?->expects(ChallengePurpose::Entrance, 1000, $bound), 'another purpose');
        self::assertFalse($kept?->expects(ChallengePurpose::Write, 1000, 'POST /admin/update/v1/probe'), 'elsewhere');

        foreach (['', 'unlock|1|x', 'nope|1|x|', 'unlock|soon|x|', "unlock|1\n|x|"] as $stored) {
            self::assertNull(Challenge::fromStored($stored), $stored);
        }
    }

    // ───────────────────────────── a stored passkey ─────────────────────────────

    /**
     * A passkey is kept as data and read back the same, shows a fingerprint, and a key that is not a
     * P-256 public key is no key.
     *
     * @return void
     */
    public function testAPasskeyIsKeptAsDataAndReadBack(): void
    {
        $passkey = new Passkey('id', 'phone', $this->key, 4, '2026-09-14T12:00:00+00:00');
        $read    = Passkey::fromData(json_decode((string) json_encode($passkey), false));

        self::assertEquals($passkey, $read);
        self::assertSame(9, $passkey->counted(9)->count);
        self::assertSame([$passkey->id, $passkey->key], [$passkey->counted(9)->id, $passkey->counted(9)->key]);
        self::assertMatchesRegularExpression('/\A[0-9a-f]{4}( [0-9a-f]{4}){3}\z/', $passkey->fingerprint());
        self::assertInstanceOf(PublicKey::class, $passkey->publicKey());
        self::assertNull(new Passkey('id', 'phone', 'not a key')->publicKey());

        $notPasskeys = [
            '[]',
            '{}',
            '{"id":"i","name":"n","key":"a+b","count":0,"added":""}',
            '{"id":"i","name":"n","key":"","count":"0","added":""}',
        ];

        foreach ($notPasskeys as $json) {
            self::assertNull(Passkey::fromData(json_decode($json, false)), $json);
        }
    }

    /**
     * The store keeps, finds, replaces and forgets devices; absent, unreadable or not a list, it holds
     * none; and a store that cannot be written says so.
     *
     * @return void
     */
    public function testTheStoreKeepsFindsAndForgetsDevices(): void
    {
        $file     = new File($this->sandbox . '/admin-passkeys.json');
        $registry = new PasskeyRegistry($file);

        self::assertTrue($registry->all()->isEmpty(), 'an absent store holds a device');
        self::assertTrue($registry->keep(new Passkey('one', 'phone', $this->key)));
        self::assertTrue($registry->keep(new Passkey('two', 'laptop', $this->key)));
        self::assertTrue($registry->keep(new Passkey('one', 'phone', $this->key, 5)));

        self::assertSame(['two', 'one'], $registry->all()->map(static fn(Passkey $p): string => $p->id)->toValues());
        self::assertSame(5, $registry->find('one')?->count);
        self::assertNull($registry->find('three'));
        self::assertTrue($registry->forget('two'));
        self::assertSame(['one'], $registry->all()->map(static fn(Passkey $p): string => $p->id)->toValues());

        foreach (['not json', '{"one":1}', '[{"id":1}]'] as $text) {
            self::assertTrue($file->write($text));
            self::assertTrue($registry->all()->isEmpty(), $text);
        }

        $unwritable = new PasskeyRegistry(new File($this->sandbox . '/nowhere/admin-passkeys.json'));

        self::assertFalse($unwritable->keep(new Passkey('one', 'phone', $this->key)));
    }

    // ───────────────────────────── an enrolment code ─────────────────────────────

    /**
     * A code opens under the key that sealed it for ten minutes, and nothing else sealed under that key
     * — a session above all — reads as one.
     *
     * @return void
     */
    public function testAnEnrolmentCodeOpensOnlyWhereAndWhileItShould(): void
    {
        $seal = SessionSeal::fromKey(random_bytes(32));
        $code = new EnrolmentCode('credential', $this->key, 1000);
        $kept = $code->seal($seal);

        self::assertEquals($code, EnrolmentCode::open($seal, "  $kept\n", 1000 + EnrolmentCode::LIFETIME));
        self::assertSame(Passkey::fingerprintOf($this->key), $code->fingerprint());
        self::assertNull(EnrolmentCode::open($seal, $kept, 1001 + EnrolmentCode::LIFETIME), 'too old');
        self::assertNull(EnrolmentCode::open($seal, $kept, 999), 'from the future');
        $elsewhere = SessionSeal::fromKey(random_bytes(32));
        $badKey    = $seal->seal('{"enrolment":true,"id":"c","key":"a+b","added":1000}');

        self::assertNull(EnrolmentCode::open($elsewhere, $kept, 1000), 'another deployment');
        self::assertNull(EnrolmentCode::open($seal, 'not a code', 1000));
        self::assertNull(EnrolmentCode::open($seal, $seal->seal('not json'), 1000));
        self::assertNull(EnrolmentCode::open($seal, $seal->seal('{"e":2000,"v":{},"m":[]}'), 1000), 'a session');
        self::assertNull(EnrolmentCode::open($seal, $badKey, 1000), 'a key that is not base64url');
    }

    // ───────────────────────────── the fixtures ─────────────────────────────

    /**
     * Authenticator data as an authenticator writes it: the relying party's hash, the flags, the count.
     *
     * @param int    $flags   UP is 0x01, UV 0x04, AT 0x40.
     * @param int    $count
     * @param string $relying
     * @return string
     */
    private static function authenticator(int $flags = 0x05, int $count = 0, string $relying = 'example.test'): string
    {
        return hash('sha256', $relying, true) . chr($flags) . pack('N', $count);
    }

    /**
     * Client data as a browser writes it.
     *
     * @param CeremonyType $type
     * @param string       $challenge
     * @param string       $origin
     * @param bool         $crossOrigin
     * @return string
     */
    private static function client(
        CeremonyType $type,
        string $challenge = self::CHALLENGE,
        string $origin = self::ORIGIN,
        bool $crossOrigin = false,
    ): string {
        return (string) json_encode([
            'type'        => $type->value,
            'challenge'   => $challenge,
            'origin'      => $origin,
            'crossOrigin' => $crossOrigin,
        ], JSON_UNESCAPED_SLASHES);
    }

    /**
     * The device's signature over $data and a digest of $client.
     *
     * @param string $data
     * @param string $client
     * @return string
     */
    private function sign(string $data, string $client): string
    {
        return self::signWith($this->private, $data, $client);
    }

    /**
     * @param OpenSSLAsymmetricKey $key
     * @param string $data
     * @param string $client
     * @return string
     */
    private static function signWith(OpenSSLAsymmetricKey $key, string $data, string $client): string
    {
        self::assertTrue(openssl_sign($data . hash('sha256', $client, true), $signature, $key, OPENSSL_ALGO_SHA256));

        return (string) $signature;
    }

    /**
     * The device's passkey, having reported $count.
     *
     * @param int $count
     * @return Passkey
     */
    private function passkey(int $count = 0): Passkey
    {
        return new Passkey('id', 'phone', $this->key, $count);
    }

    /**
     * @return OpenSSLAsymmetricKey
     */
    private static function p256(): OpenSSLAsymmetricKey
    {
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        self::assertNotFalse($key, 'this host cannot generate an EC key, so nothing here is meaningful');

        return $key;
    }

    /**
     * $key's public half, as the SPKI DER a browser's `getPublicKey()` hands over.
     *
     * @param OpenSSLAsymmetricKey $key
     * @return string
     */
    private static function der(OpenSSLAsymmetricKey $key): string
    {
        $details = openssl_pkey_get_details($key);
        self::assertIsArray($details);

        $body = (string) preg_replace('/-----[^-]+-----|\s+/', '', (string) $details['key']);

        return (string) base64_decode($body, true);
    }
}
