<?php

declare(strict_types=1);

namespace Phpanta\Http;

/**
 * The SealContext enum. What a {@link SessionSeal} seals — bound into every seal as associated data, so
 * bytes sealed as one can never be opened as another under the same key.
 *
 * One key per deployment seals more than sessions. A context per kind is what keeps a session cookie
 * from being pasted where an enrolment code is asked for, and a code from being sent back as a cookie —
 * decided by the cipher, rather than by what the plaintext happens to look like.
 */
enum SealContext: string
{
    /** A visitor's session, in its cookie. */
    case Session = 'phpanta session v1';

    /** A device the admin's entrance registered, on its way to `access v1 enrol`. */
    case Enrolment = 'phpanta enrolment v1';
}
