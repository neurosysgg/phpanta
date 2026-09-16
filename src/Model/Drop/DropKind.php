<?php

declare(strict_types=1);

namespace Phpanta\Model\Drop;

/**
 * The DropKind enum. What a drop holds: text, shown on the page that reveals it, or a file, saved
 * under its name.
 */
enum DropKind: string
{
    /** Text, UTF-8: shown where it is revealed, and sent as plain text to anything else. */
    case Text = 'text';

    /** A file: always saved, as bytes, under its name — never shown, whatever it claims to be. */
    case File = 'file';
}
