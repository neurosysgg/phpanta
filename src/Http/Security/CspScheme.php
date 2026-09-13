<?php

declare(strict_types=1);

namespace Phpanta\Http\Security;

/**
 * The CspScheme enum. Scheme sources — a whole URL scheme allowed regardless of host.
 *
 * The trailing colon is part of the token. `data` without it is parsed as a host name.
 */
enum CspScheme: string implements CspSource
{
    /**
     * Inline `data:` URIs.
     *
     * Nothing the framework emits is one, and `img-src` deliberately does not allow it — see
     * {@link \Phpanta\Http\SecurityHeaders::contentSecurityPolicy()}. The case stays because
     * this enum is the vocabulary a policy may be written in, not a list of what is switched on.
     */
    case Data = 'data:';

    /** Any origin, as long as it is HTTPS. Broad — prefer a named host. */
    case Https = 'https:';

    /** Object URLs created by `URL.createObjectURL`. */
    case Blob = 'blob:';

    /**
     * @return string
     */
    public function source(): string
    {
        return $this->value;
    }
}
