<?php

declare(strict_types=1);

namespace Phpanta\Tool\Command;

use Phpanta\Http\Api\ActionField;
use Phpanta\Tool\Cli\Option;

/**
 * The ApiCallOption enum. The flags `api` accepts.
 *
 * Three are ones {@link PushUpdateOption} has for the same reasons — where to go, which key to sign
 * with, and whether a write is only a rehearsal. The rest are an action's own fields, each named by
 * the {@link ActionField} it fills, so the command can refuse a flag the action does not take and ask
 * for one it needs. There is deliberately no flag that changes *what* is asked for: that is the
 * operands, because a service, a version and an action are what the address is.
 */
enum ApiCallOption: string implements Option
{
    /** Which deployment to ask. An origin, not an endpoint. Defaults to the live site. */
    case Url = 'url';

    /** The private key. Defaults to the one the command was given, under `$HOME`. */
    case Key = 'key';

    /**
     * A write's dry run: the server reports what it would do, changes nothing and spends no serial.
     * Refused on a read, which has nothing to rehearse.
     */
    case DryRun = 'dry-run';

    /** An enrolment's code, as the admin's entrance showed it. */
    case Code = 'code';

    /** An enrolment's name for the device. */
    case Name = 'name';

    /** A revocation's credential id. */
    case Passkey = 'passkey';

    /**
     * @return string
     */
    public function flag(): string
    {
        return $this->value;
    }

    /**
     * The action field this flag fills, or null for a flag that is the command's own.
     *
     * @return ActionField|null
     */
    public function field(): ?ActionField
    {
        return match ($this) {
            self::Code    => ActionField::Code,
            self::Name    => ActionField::Name,
            self::Passkey => ActionField::Passkey,
            default       => null,
        };
    }

    /**
     * @return bool
     */
    public function takesValue(): bool
    {
        return $this !== self::DryRun;
    }
}
