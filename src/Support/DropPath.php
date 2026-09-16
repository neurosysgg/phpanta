<?php

declare(strict_types=1);

namespace Phpanta\Support;

use Phpanta\Model\Drop\DropToken;

/**
 * The DropPath enum. Where a drop is opened: `/drop`, by whoever holds its link.
 *
 * **The framework's one address outside `/admin`**, and like the admin's it is
 * {@link \Phpanta\App::routeTable()}'s to add rather than a site's to register — after the site's own
 * routes, so a site that has a `/drop` of its own keeps it. It answers only where the `drop` service
 * is switched on; everywhere else it answers exactly as an address that is not there — see
 * {@link \Phpanta\Controller\DropController}.
 */
enum DropPath: string implements Path
{
    use FillsPlaceholders;

    /** The page that reveals a drop, and the post that does. */
    case Index = '/drop';

    /**
     * The link that opens the drop $token names: this address, and the token after the `#`, which a
     * browser never sends — the page's script hands it over in the post that reveals the drop.
     *
     * @param DropToken $token
     * @return string
     */
    public function link(DropToken $token): string
    {
        return $this->to() . '#' . $token->text();
    }
}
