<?php

declare(strict_types=1);

namespace Phpanta;

use Phpanta\Http\Request;

/**
 * The Environment enum. Whether this deployment may tell a visitor how it broke.
 *
 * **Production unless a server says otherwise, and only a server can.** The one way to development
 * is a server variable — `SetEnv PHPANTA_ENVIRONMENT development` in a vhost, the environment of a
 * `php -S` — which no request can set and nothing that ships sets. A value that is missing, empty,
 * misspelled or capitalised is production: the safe answer is the one a mistake lands on.
 *
 * **And development alone is not enough.** A trace goes only to a request from this machine — see
 * {@link self::showsFaultsTo()} — so a production host that somehow reported development would still
 * answer every visitor with a bare 500. Both halves have to be wrong at once for a trace to leave the
 * machine it was made on, and the health report warns about the first.
 */
enum Environment: string
{
    /** Every visitor gets a bare 500, and the fault goes to the log. */
    case Production = 'production';

    /** A request from this machine gets the fault, its chain and its trace. */
    case Development = 'development';

    /**
     * True if a fault may be shown to $request: this is development, and the request came from
     * loopback.
     *
     * Loopback is asked of the peer's address as the server saw it. Behind a reverse proxy on the
     * same machine every request is from loopback — one more reason development is never set on a
     * host that serves anybody but its developer.
     *
     * @param Request $request
     * @return bool
     */
    public function showsFaultsTo(Request $request): bool
    {
        return $this === self::Development && $request->isFromLoopback();
    }
}
