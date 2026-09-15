<?php

declare(strict_types=1);

namespace Phpanta\Http\Api;

use Phpanta\Http\Parameter;
use Phpanta\Text\AdminText;
use Phpanta\Text\Translatable;

/**
 * The ActionField enum. What an action can be told besides where it is.
 *
 * An action's own fields ride in the signed manifest — {@link \Phpanta\Model\Update\UpdateManifest}
 * and {@link \Phpanta\Model\Update\ApplyManifest} read them — and this is their vocabulary as the
 * admin shows it: a listing names each action's fields, and a page that runs one asks for them.
 * The values are the manifest's own keys, and `DiscoveryTest` holds the two to each other.
 */
enum ActionField: string implements Parameter
{
    /** Carry the action out; without it, a dry run that changes nothing and spends no serial. */
    case Apply = 'apply';

    /** Remove what a pushed tree leaves out. */
    case Mirror = 'mirror';

    /** The enrolment code the admin's entrance showed a registering device. */
    case Code = 'code';

    /** What an enrolled device is called. */
    case Name = 'name';

    /** Which enrolled device, by its credential id. */
    case Passkey = 'passkey';

    /**
     * The files a browser sends to be kept — the one field that is not in the manifest: a file is
     * bytes, not text, so {@link \Phpanta\Service\Passkey\AdminBrowser} hands it over beside it.
     */
    case Files = 'files';

    /** A name to give — a new directory's, or the one an entry is renamed to. */
    case Target = 'target';

    /** A command line, run by the machine's shell. */
    case Command = 'command';

    /**
     * Whether the field is a yes or a no, rather than a line of text.
     *
     * @return bool
     */
    public function isFlag(): bool
    {
        return $this === self::Apply || $this === self::Mirror;
    }

    /**
     * Whether the field is files a browser sends, rather than a value in the manifest.
     *
     * @return bool
     */
    public function isUpload(): bool
    {
        return $this === self::Files;
    }

    /**
     * What the field does, in the caller's language.
     *
     * @return Translatable
     */
    public function describe(): Translatable
    {
        return match ($this) {
            self::Apply   => AdminText::FieldApply,
            self::Mirror  => AdminText::FieldMirror,
            self::Code    => AdminText::FieldCode,
            self::Name    => AdminText::FieldName,
            self::Passkey => AdminText::FieldPasskey,
            self::Files   => AdminText::FieldFiles,
            self::Target  => AdminText::FieldTarget,
            self::Command => AdminText::FieldCommand,
        };
    }
}
