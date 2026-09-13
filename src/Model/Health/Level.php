<?php

declare(strict_types=1);

namespace Phpanta\Model\Health;

/**
 * The Level enum. How much a {@link Requirement} going unmet matters.
 *
 * Two, because a health check has exactly two things to say about a gap: the host cannot do what
 * this installation needs, or it can but not as well as it should. The first is a `503` and the
 * second is a line worth reading in a `200` — see {@link Verdict::of()}, which is the only place the
 * two are told apart.
 *
 * Server-only; no TypeScript mirror is wanted — see {@link Area}.
 */
enum Level: string
{
    /** Unmet, and this installation is broken somewhere: the answer is a `503`. */
    case Required = 'required';

    /** Unmet, and something is slower or quieter than it should be: a `warn` line in a `200`. */
    case Optional = 'optional';
}
