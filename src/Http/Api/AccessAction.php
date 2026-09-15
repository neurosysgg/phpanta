<?php

declare(strict_types=1);

namespace Phpanta\Http\Api;

use Phpanta\App;
use Phpanta\Exception\ApiException;
use Phpanta\Exception\SessionException;
use Phpanta\Http\HttpMethod;
use Phpanta\Http\SessionSeal;
use Phpanta\Model\Api\VerifiedRequest;
use Phpanta\Model\Passkey\AccessManifest;
use Phpanta\Service\Api\AccessEnrol;
use Phpanta\Service\Api\AccessPasskeys;
use Phpanta\Service\Api\AccessRevoke;
use Phpanta\Support\Collection;
use Phpanta\Text\AdminText;
use Phpanta\Text\Translatable;

/**
 * The AccessAction enum. What the `access` service can be asked to do: which devices may open the admin
 * in a browser.
 *
 * **Enrolment is where trust starts, so only the signing key can do it.** A browser registers itself at
 * the entrance and is shown a code; `enrol`, a signed write, is what turns that code into a device the
 * admin lets in. A browser that could enrol would be a browser vouching for itself.
 */
enum AccessAction: string implements ApiAction
{
    /** Turn an enrolment code into an enrolled device. */
    case Enrol = 'enrol';

    /** The enrolled devices, each with its fingerprint. */
    case Passkeys = 'passkeys';

    /** Take one device's passkey away. */
    case Revoke = 'revoke';

    /**
     * @return HttpMethod
     */
    public function method(): HttpMethod
    {
        return $this === self::Passkeys ? HttpMethod::Get : HttpMethod::Post;
    }

    /**
     * @return Translatable
     */
    public function describe(): Translatable
    {
        return match ($this) {
            self::Enrol    => AdminText::AccessEnrol,
            self::Passkeys => AdminText::AccessPasskeys,
            self::Revoke   => AdminText::AccessRevoke,
        };
    }

    /**
     * @return Collection<ActionField>
     */
    public function fields(): Collection
    {
        return new Collection(ActionField::class)->with(...match ($this) {
            self::Enrol    => [ActionField::Code, ActionField::Name, ActionField::Apply],
            self::Passkeys => [],
            self::Revoke   => [ActionField::Passkey, ActionField::Apply],
        });
    }

    /**
     * Everything but an enrolment, which is the signing key's alone.
     *
     * @return bool
     */
    public function fromBrowser(): bool
    {
        return $this !== self::Enrol;
    }

    /**
     * None: a device is named by its credential id, which is a field.
     *
     * @return bool
     */
    public function takesPath(): bool
    {
        return false;
    }

    /**
     * @param VerifiedRequest $verified
     * @param string|null     $path     Never one; see {@link self::takesPath()}.
     * @return ApiHandler
     * @throws ApiException if the manifest does not carry what the action takes, or an enrolment's code
     *                      is not one this deployment made in the last ten minutes.
     */
    public function handler(VerifiedRequest $verified, ?string $path = null): ApiHandler
    {
        return match ($this) {
            self::Enrol    => AccessEnrol::open(
                AccessManifest::enrolment($verified->manifest, $this->value),
                self::seal(),
                time(),
            ),
            self::Passkeys => new AccessPasskeys(),
            self::Revoke   => new AccessRevoke(AccessManifest::revocation($verified->manifest, $this->value)),
        };
    }

    /**
     * The seal an enrolment code was made under — the deployment's session key — refused as a sentence
     * rather than a fault where there is none, since then there is no code it could have made.
     *
     * @return SessionSeal
     * @throws ApiException
     */
    private static function seal(): SessionSeal
    {
        try {
            return App::current()->sessionSeal();
        } catch (SessionException $cause) {
            throw new ApiException(
                'this deployment has no session key, so it has made no enrolment code — mint data/session.key first',
                previous: $cause,
            );
        }
    }
}
