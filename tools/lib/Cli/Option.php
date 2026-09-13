<?php

declare(strict_types=1);

namespace Phpanta\Tool\Cli;

/**
 * The Option interface. One command-line flag.
 *
 * Implemented by a backed enum per command, which is the arrangement this codebase already uses
 * twice: `CspSource` with `CspKeyword`/`CspScheme`/`CspHost` behind it, and `HeaderName` with
 * `SecurityHeader` and `ResponseHeader`. The point is the same in all three — the vocabulary is
 * closed and each member is a value you can pass around, rather than a string compared at a call
 * site where a typo reads as "absent".
 */
interface Option
{
    /**
     * The flag's name, without the leading dashes — `check` for `--check`.
     *
     * @return string
     */
    public function flag(): string;

    /**
     * Whether the flag is followed by a value (`--clover file`) or stands alone (`--check`).
     *
     * @return bool
     */
    public function takesValue(): bool;
}
