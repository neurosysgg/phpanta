<?php

declare(strict_types=1);

namespace Phpanta\Http\Security;

/**
 * The PermissionsPolicyFeature enum. The browser features `Permissions-Policy` can gate.
 *
 * Only the ones worth naming on a site like this: the hardware and payment surfaces a music
 * page has no business touching, plus the tracking opt-out.
 *
 * **Every case here gets denied**, because {@link PermissionsPolicy::denyAll()} is what
 * {@link \Phpanta\Http\SecurityHeaders} sends. So this is not a list of features that exist —
 * it is the list of features the site refuses. Adding `autoplay` or `encrypted-media` would
 * switch off the SoundCloud player, which asks for both in its iframe's `allow` attribute; a
 * test asserts that never happens.
 */
enum PermissionsPolicyFeature: string
{
    case Geolocation = 'geolocation';
    case Camera = 'camera';
    case Microphone = 'microphone';
    case Payment = 'payment';
    case Usb = 'usb';
    case Midi = 'midi';

    /** Chrome's FLoC cohort. Opting out is the documented way to say "don't profile my visitors". */
    case InterestCohort = 'interest-cohort';

    /**
     * Renders this feature as denied to everyone: `geolocation=()`.
     *
     * @return string
     */
    public function denied(): string
    {
        return $this->value . '=()';
    }
}
