<?php

declare(strict_types=1);

namespace Phpanta\Http\Security;

/**
 * The CspKeyword enum. The quoted keyword sources a CSP directive accepts.
 *
 * The quotes are part of the token, not string syntax — `self` without them is read as a host
 * named "self" and silently matches nothing. Baking them into the case value is the point of
 * this enum.
 */
enum CspKeyword: string implements CspSource
{
    /** The document's own origin. */
    case SelfOrigin = "'self'";

    /** Nothing at all. Only meaningful as the sole entry in a list. */
    case None = "'none'";

    /** Permits inline `<style>`/`style=` (or `<script>`), i.e. gives up most of the directive. */
    case UnsafeInline = "'unsafe-inline'";

    /** Permits `eval()` and friends. */
    case UnsafeEval = "'unsafe-eval'";

    /**
     * @return string
     */
    public function source(): string
    {
        return $this->value;
    }
}
