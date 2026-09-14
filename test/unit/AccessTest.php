<?php

declare(strict_types=1);

namespace Phpanta\Test\Unit;

use Phpanta\Exception\ApiException;
use Phpanta\Http\Api\AccessAction;
use Phpanta\Http\Api\ApiResult;
use Phpanta\Http\HttpMethod;
use Phpanta\Http\HttpStatusCode;
use Phpanta\Http\SessionSeal;
use Phpanta\Model\Api\ApiEnvelope;
use Phpanta\Model\Api\VerifiedRequest;
use Phpanta\Model\Passkey\AccessManifest;
use Phpanta\Model\Passkey\EnrolmentCode;
use Phpanta\Model\Passkey\Passkey;
use Phpanta\Service\Api\AccessEnrol;
use Phpanta\Service\Api\AccessPasskeys;
use Phpanta\Service\Api\AccessRevoke;
use Phpanta\Service\Passkey\PasskeyRegistry;
use Phpanta\Support\Directory;
use Phpanta\Support\File;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * `access`: enrolling a device from the code the entrance showed it, listing the devices, and revoking
 * one — the three signed calls that decide who may open the admin in a browser.
 *
 * The handlers are built directly over a store in a sandbox, as the update handlers are over a sandbox
 * deployment, since the signed half — that `/admin/access/v1/enrol` reaches this at all — is the gate's,
 * and {@link ApiTest}'s.
 */
#[CoversClass(AccessAction::class)]
#[CoversClass(AccessManifest::class)]
#[CoversClass(AccessEnrol::class)]
#[CoversClass(AccessPasskeys::class)]
#[CoversClass(AccessRevoke::class)]
#[CoversClass(PasskeyRegistry::class)]
#[CoversClass(Passkey::class)]
final class AccessTest extends TestCase
{
    private string $sandbox = '';
    private PasskeyRegistry $registry;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        $this->sandbox  = sys_get_temp_dir() . '/phpanta-access-' . bin2hex(random_bytes(6));
        new Directory($this->sandbox)->create();
        $this->registry = new PasskeyRegistry(new File($this->sandbox . '/admin-passkeys.json'));
    }

    /**
     * @return void
     */
    protected function tearDown(): void
    {
        UpdateFixture::removeTree($this->sandbox);
    }

    /**
     * A code the deployment made becomes an enrolled device — named, counting from zero — and a dry run
     * says what it would enrol and writes nothing.
     *
     * @return void
     */
    public function testAnEnrolmentCodeBecomesADeviceAndADryRunWritesNothing(): void
    {
        $seal = SessionSeal::fromKey(random_bytes(32));
        $code = new EnrolmentCode('credential', 'key bytes', time())->seal($seal);

        $dry  = AccessEnrol::open(self::enrolment($code, 'phone', false), $seal, time(), $this->registry);
        $real = AccessEnrol::open(self::enrolment($code, 'phone', true), $seal, time(), $this->registry);

        self::assertFalse($dry->isWrite());
        self::assertStringContainsString('would enrol phone', $dry->handle()->text());
        self::assertTrue($this->registry->all()->isEmpty(), 'a dry run enrolled a device');

        self::assertTrue($real->isWrite());
        self::assertStringStartsWith('enrolled phone ', $real->handle()->text());
        self::assertSame(['credential', 'phone', 0], [
            $this->registry->find('credential')?->id,
            $this->registry->find('credential')?->name,
            $this->registry->find('credential')?->count,
        ]);
    }

    /**
     * A code this deployment did not make, or made too long ago, enrols nothing; and a store that cannot
     * be written is a 500 that says nothing was enrolled.
     *
     * @return void
     */
    public function testACodeThatIsNotOneEnrolsNothing(): void
    {
        $seal = SessionSeal::fromKey(random_bytes(32));
        $old  = new EnrolmentCode('credential', 'key bytes', time() - EnrolmentCode::LIFETIME - 1)->seal($seal);

        foreach (['not a code', $old] as $code) {
            try {
                (void) AccessEnrol::open(self::enrolment($code, 'phone', true), $seal, time(), $this->registry);
                self::fail('a code that is not one was opened');
            } catch (ApiException $refusal) {
                self::assertStringContainsString('not an enrolment code', $refusal->getMessage());
            }
        }

        $fresh = new EnrolmentCode('credential', 'key bytes', time())->seal($seal);
        $stuck = new PasskeyRegistry(new File($this->sandbox . '/nowhere/admin-passkeys.json'));
        $said  = AccessEnrol::open(self::enrolment($fresh, 'phone', true), $seal, time(), $stuck)->handle();

        self::assertSame(HttpStatusCode::InternalServerError, $said->status);
    }

    /**
     * Without a session key a deployment has made no code, and says so as a refusal rather than a fault.
     *
     * @return void
     */
    public function testWithNoSessionKeyAnEnrolmentIsRefused(): void
    {
        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('no session key');

        (void) AccessAction::Enrol->handler(self::verified('/admin/access/v1/enrol', HttpMethod::Post, [
            'code'  => 'anything',
            'name'  => 'phone',
            'apply' => true,
        ]));
    }

    /**
     * @param string $json
     * @param bool   $revocation
     * @return void
     */
    #[DataProvider('refusedManifestProvider')]
    public function testAManifestThatDoesNotCarryWhatTheActionTakesIsRefused(string $json, bool $revocation): void
    {
        $this->expectException(ApiException::class);

        (void) ($revocation ? AccessManifest::revocation($json, 'revoke') : AccessManifest::enrolment($json, 'enrol'));
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function refusedManifestProvider(): iterable
    {
        yield 'not JSON'                  => ['{', false];
        yield 'not an object'             => ['[]', false];
        yield 'no code'                   => ['{"name":"phone","apply":true}', false];
        yield 'no name'                   => ['{"code":"c","apply":true}', false];
        yield 'a name of nothing'         => ['{"code":"c","name":"  ","apply":true}', false];
        yield 'a name with a newline'     => ['{"code":"c","name":"ph\none","apply":true}', false];
        yield 'no apply'                  => ['{"code":"c","name":"phone"}', false];
        yield 'no passkey'                => ['{"apply":true}', true];
        yield 'a passkey that is not one' => ['{"passkey":"a+b","apply":true}', true];
    }

    /**
     * The devices are listed by name with their fingerprint and id; one can be revoked, a dry run first;
     * one that is not enrolled cannot; and a store that cannot be written is a 500.
     *
     * @return void
     */
    public function testTheDevicesAreListedAndOneCanBeRevoked(): void
    {
        self::assertSame("no device is enrolled\n", new AccessPasskeys($this->registry)->handle()->text());

        self::assertTrue($this->registry->keep(new Passkey('dev1', 'phone', 'key one', 0, '2026-09-14')));
        self::assertTrue($this->registry->keep(new Passkey('dev2', 'laptop', 'key two', 0, '2026-09-14')));

        $listed = new AccessPasskeys($this->registry);

        self::assertFalse($listed->isWrite());
        self::assertMatchesRegularExpression(
            '/^  phone +[0-9a-f ]{19}  dev1  2026-09-14$/m',
            $listed->handle()->text(),
        );

        $dry  = new AccessRevoke(self::revocation('dev1', false), $this->registry);
        $real = new AccessRevoke(self::revocation('dev1', true), $this->registry);

        self::assertFalse($dry->isWrite());
        self::assertStringContainsString('would revoke phone', $dry->handle()->text());
        self::assertNotNull($this->registry->find('dev1'));
        self::assertTrue($real->isWrite());
        self::assertStringStartsWith('revoked phone', $real->handle()->text());
        self::assertNull($this->registry->find('dev1'));

        try {
            (void) $real->handle();
            self::fail('a device that is not enrolled was revoked');
        } catch (ApiException $refusal) {
            self::assertStringContainsString('no enrolled device', $refusal->getMessage());
        }
    }

    /**
     * A store that can be read and not written is a 500 that says nothing was revoked — the store is
     * written through a sibling, so a directory that takes no new file is enough.
     *
     * @return void
     */
    public function testARevocationTheStoreCannotTakeIsA500(): void
    {
        $locked = $this->sandbox . '/locked';
        new Directory($locked)->create();
        $store = new PasskeyRegistry(new File($locked . '/admin-passkeys.json'));

        self::assertTrue($store->keep(new Passkey('dev1', 'phone', 'key one')));
        self::assertTrue(chmod($locked, 0o500));

        try {
            $said = new AccessRevoke(self::revocation('dev1', true), $store)->handle();
        } finally {
            chmod($locked, 0o700);
        }

        self::assertSame(HttpStatusCode::InternalServerError, $said->status);
        self::assertNotNull($store->find('dev1'));
    }

    /**
     * Each action answers its own handler.
     *
     * @return void
     */
    public function testEachActionAnswersItsOwnHandler(): void
    {
        self::assertInstanceOf(AccessPasskeys::class, AccessAction::Passkeys->handler(self::verified(
            '/admin/access/v1/passkeys',
            HttpMethod::Get,
            [],
        )));
        self::assertInstanceOf(AccessRevoke::class, AccessAction::Revoke->handler(self::verified(
            '/admin/access/v1/revoke',
            HttpMethod::Post,
            ['passkey' => 'dev1', 'apply' => false],
        )));
        self::assertInstanceOf(ApiResult::class, new AccessPasskeys($this->registry)->handle());
    }

    /**
     * An enrolment's manifest.
     *
     * @param string $code
     * @param string $name
     * @param bool   $apply
     * @return AccessManifest
     */
    private static function enrolment(string $code, string $name, bool $apply): AccessManifest
    {
        $json = (string) json_encode(['code' => $code, 'name' => $name, 'apply' => $apply]);

        return AccessManifest::enrolment($json, 'enrol');
    }

    /**
     * A revocation's manifest.
     *
     * @param string $passkey
     * @param bool   $apply
     * @return AccessManifest
     */
    private static function revocation(string $passkey, bool $apply): AccessManifest
    {
        return AccessManifest::revocation((string) json_encode(['passkey' => $passkey, 'apply' => $apply]), 'revoke');
    }

    /**
     * A request the gate has verified, carrying $fields.
     *
     * @param string              $path
     * @param HttpMethod          $method
     * @param array<string, mixed> $fields
     * @return VerifiedRequest
     */
    private static function verified(string $path, HttpMethod $method, array $fields): VerifiedRequest
    {
        $manifest = (string) json_encode([
            'serial' => time(),
            'method' => $method->value,
            'path'   => $path,
            'digest' => hash('sha256', ''),
            'size'   => 0,
            ...$fields,
        ]);

        return new VerifiedRequest(ApiEnvelope::parse($manifest), $manifest, '');
    }
}
