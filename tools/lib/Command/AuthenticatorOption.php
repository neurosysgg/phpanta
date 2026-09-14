<?php

declare(strict_types=1);

namespace Phpanta\Tool\Command;

use Phpanta\Tool\Cli\Option;

/**
 * The AuthenticatorOption enum. The flags {@link Authenticator} takes.
 */
enum AuthenticatorOption: string implements Option
{
    /** The local copy to play a device against — a `*.localhost` origin, and nothing else. */
    case Url = 'url';

    /** The file the device is kept in; one per origin under `~/.config/phpanta/` by default. */
    case Device = 'device';

    /** The name the device is enrolled under, where it is enrolled. */
    case Name = 'name';

    /** The signing key that enrols it, where it is not the origin's own. */
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
        return true;
    }
}
