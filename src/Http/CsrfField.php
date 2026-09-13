<?php

declare(strict_types=1);

namespace Phpanta\Http;

/**
 * The CsrfField enum. The one field every form that writes sends back: the visitor's form token —
 * see {@link \Phpanta\Service\Layer\CsrfGuard}.
 *
 * Its name begins with `_` so it cannot be mistaken for a field of the form itself.
 */
enum CsrfField: string implements Parameter
{
    case Token = '_csrf';
}
