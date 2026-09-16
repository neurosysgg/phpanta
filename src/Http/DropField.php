<?php

declare(strict_types=1);

namespace Phpanta\Http;

/**
 * The DropField enum. What a post to `/drop` sends: the token from the link, the password where the
 * drop has one, and whether the answer is a page.
 *
 * The page's script fills in the token from the link's `#`, so the name is a fact both languages
 * know — mirrored in `assets/ts/model/DropField.ts`. A post from anything else, `curl -F` say, sends
 * the same names.
 */
enum DropField: string implements Parameter
{
    /** The token after the link's `#`. */
    case Token = 'token';

    /** The drop's password, where it has one. */
    case Password = 'password';

    /**
     * Whether text is answered as a page to read it on, rather than as the text itself. The page's
     * own form says so; anything else gets the bytes.
     */
    case Page = 'page';
}
