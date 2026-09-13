<?php

declare(strict_types=1);

namespace Phpanta\Http;

use BackedEnum;

/**
 * The SessionKey interface. A name a site keeps a value under in the {@link Session}, as a case.
 *
 * ```php
 * enum CartKey: string implements SessionKey
 * {
 *     case Items = 'items';
 * }
 *
 * $session = $request->session()->with(CartKey::Items, '3');
 * ```
 *
 * A case for the reason {@link Parameter} is one: a misspelled key is not an error but a value that
 * is never there. A key may not begin with `_` — the framework keeps its own there, and
 * {@link Session::with()} refuses a site's.
 */
interface SessionKey extends BackedEnum
{
}
