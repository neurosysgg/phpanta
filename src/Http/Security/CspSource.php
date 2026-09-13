<?php

declare(strict_types=1);

namespace Phpanta\Http\Security;

/**
 * The CspSource interface. One entry in a Content-Security-Policy directive's source list.
 *
 * A source list mixes three unrelated kinds of thing — keywords (`'self'`), schemes (`data:`)
 * and hosts (`https://my.hidrive.com`). Each owns its own wire format behind this interface, so
 * {@link ContentSecurityPolicy} composes them without knowing which is which, the same way
 * {@link \NeuroSYS\Model\Link\FileLink} lets a release name a file without knowing its host.
 *
 * Deliberately not {@link \Stringable}, for the reason {@link \NeuroSYS\Model\Link\FileLink}
 * gives: every use site should be visible as a call.
 */
interface CspSource
{
    /**
     * Returns the token exactly as it appears in the header.
     *
     * @return string
     */
    public function source(): string;
}
