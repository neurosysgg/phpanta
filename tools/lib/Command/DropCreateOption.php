<?php

declare(strict_types=1);

namespace Phpanta\Tool\Command;

use Phpanta\Tool\Cli\Option;

/**
 * The DropCreateOption enum. The flags `drop` accepts: what to keep, how it is kept, and — like
 * {@link ApiCallOption} — where to go, which key to sign with, and whether it is only a rehearsal.
 */
enum DropCreateOption: string implements Option
{
    /** Text to keep, given on the command line — instead of a file or standard input. */
    case Text = 'text';

    /** The name the drop is saved under: a file's own where it is not given, none for text. */
    case Name = 'name';

    /** How long it is kept: `90`, `30m`, `12h`, `7d`. A day, or the deployment's most, where not given. */
    case Lifetime = 'lifetime';

    /** Gone once it has been read. */
    case Once = 'once';

    /**
     * A file whose first line is the drop's password — never the password itself, which the shell's
     * history and every process listing would keep.
     */
    case PasswordFile = 'password-file';

    /** The server reports what it would keep, keeps nothing and spends no serial. */
    case DryRun = 'dry-run';

    /** Which deployment to ask. An origin, not an endpoint. Defaults to the site's own. */
    case Url = 'url';

    /** The private key. Defaults to the one the command was given, under `$HOME`. */
    case Key = 'key';

    /**
     * @return string
     */
    public function flag(): string
    {
        return $this->value;
    }

    /**
     * @return bool
     */
    public function takesValue(): bool
    {
        return $this !== self::Once && $this !== self::DryRun;
    }
}
