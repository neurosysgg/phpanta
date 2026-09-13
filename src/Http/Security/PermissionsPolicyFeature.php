<?php

declare(strict_types=1);

namespace Phpanta\Http\Security;

/**
 * The PermissionsPolicyFeature enum. The browser features `Permissions-Policy` can gate.
 *
 * Only the ones worth naming on a content site: the hardware and payment surfaces a page of
 * reading and listening has no business touching.
 *
 * **Every case here gets denied**, because {@link PermissionsPolicy::denyAll()} is what
 * {@link \Phpanta\App::permissionsPolicy()} sends unless a site says otherwise. So this is not a
 * list of features that exist — it is the list of features a site refuses by default. Adding
 * `autoplay` or `encrypted-media` would switch off an embedded player that asks for both in its
 * iframe's `allow` attribute, as the common audio and video embeds do.
 *
 * **A case is a feature browsers still recognise.** One they do not is not a stricter policy but
 * a console error on every page — which is why `interest-cohort`, the opt-out from Chrome's FLoC,
 * is not here any more: FLoC was withdrawn in 2022.
 */
enum PermissionsPolicyFeature: string
{
    case Geolocation = 'geolocation';
    case Camera = 'camera';
    case Microphone = 'microphone';
    case Payment = 'payment';
    case Usb = 'usb';
    case Midi = 'midi';

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
