<?php

declare(strict_types=1);

namespace Phpanta\Http;

use BackedEnum;

/**
 * The Parameter interface. A name a request may send a value under, as a case — the way
 * {@link ServerVariable} names what the server hands over.
 *
 * ```php
 * enum SearchParameter: string implements Parameter
 * {
 *     case Term = 'q';
 *     case Page = 'page';
 * }
 *
 * $page = $request->query()->int(SearchParameter::Page) ?? 1;
 * ```
 *
 * A case rather than a string for the reason every name here is one: a misspelled key is not an
 * error but an absent value, so `$_GET['pgae']` is a page that always shows the first page. The
 * case's value is the name on the wire; what the value must be is asked by the reader — see
 * {@link Input}.
 */
interface Parameter extends BackedEnum
{
}
