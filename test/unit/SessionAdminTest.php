<?php

declare(strict_types=1);

namespace Phpanta\Test\Unit;

use Phpanta\Http\RequestHeader;
use Phpanta\Http\Session;
use Phpanta\Http\SessionSeal;
use Phpanta\Model\Passkey\Challenge;
use Phpanta\Model\Passkey\ChallengePurpose;
use Phpanta\Test\TestRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The admin's half of a session: the challenge a page hands out, and the unlock that answers it.
 *
 * An unlock lasts eight hours whatever the cookie does, spends the challenge it answered, and hands out
 * a new form token — so a token and a challenge written before the unlock are worth nothing after it.
 */
#[CoversClass(Session::class)]
#[CoversClass(Challenge::class)]
final class SessionAdminTest extends TestCase
{
    /**
     * A challenge is kept until the unlock that answers it, which lasts eight hours, rotates the form
     * token, and is forgotten with everything else when the admin is locked again.
     *
     * @return void
     */
    public function testAnUnlockSpendsItsChallengeRotatesTheTokenAndLastsEightHours(): void
    {
        $seal      = SessionSeal::fromKey(random_bytes(32));
        $session   = Session::fresh($seal)->withToken();
        $challenge = Challenge::mint(ChallengePurpose::Entrance, 1000);
        $asked     = $session->withChallenge($challenge);
        $unlocked  = $asked->withAdmin('credential', 1000);

        self::assertEquals($challenge, $asked->challenge());
        self::assertNull($asked->withoutChallenge()->challenge());
        self::assertNull($asked->admin(1000), 'a challenge is not an unlock');

        self::assertSame('credential', $unlocked->admin(1000 + Session::ADMIN_LIFETIME));
        self::assertNull($unlocked->admin(1001 + Session::ADMIN_LIFETIME), 'an unlock outlived eight hours');
        self::assertNull($unlocked->challenge(), 'the challenge outlived the unlock that answered it');
        self::assertNotSame($session->token(), $unlocked->token(), 'the form token survived the unlock');
        self::assertNotNull($unlocked->token());

        self::assertNull($unlocked->withoutAdmin()->admin(1000));
        self::assertNull($unlocked->withoutAdmin()->token());
        self::assertNull(Session::fresh($seal)->admin(1000));
        self::assertNull(Session::fresh($seal)->withAdmin('', 1000)->admin(1000), 'an unlock by no passkey');
    }

    /**
     * What a session carries under the admin's keys and is not what the framework writes there is
     * nothing — read, never trusted.
     *
     * @return void
     */
    public function testWhatIsNotAnUnlockOrAChallengeReadsAsNeither(): void
    {
        $seal    = SessionSeal::fromKey(random_bytes(32));
        $sealed  = $seal->seal('{"e":9999999999,"v":{"_admin":"soon credential","_challenge":"nope"},"m":[]}');
        $request = TestRequest::get('/')->with(RequestHeader::Cookie, '__Host-session=' . $sealed)->request();
        $session = Session::of($request, $seal);

        self::assertNull($session->admin(1000));
        self::assertNull($session->challenge());
    }
}
