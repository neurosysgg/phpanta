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

    /** The text a drop keeps — shown where it is revealed, unless it is given a filename. */
    case Text = 'text';

    /**
     * The one file a drop keeps — a file a browser sends beside the form, like {@link self::Files}; a
     * signed call's is its body.
     */
    case File = 'file';

    /** The name a drop's bytes are saved under — a file's own, where left empty. */
    case Filename = 'filename';

    /** How long a drop is kept: `30m`, `12h`, `7d`. */
    case Lifetime = 'lifetime';

    /** Whether a drop is gone once it has been read. */
    case Once = 'once';

    /** A password a drop needs besides its link. */
    case Password = 'password';

    /**
     * Whether the field is a yes or a no, rather than a line of text.
     *
     * @return bool
     */
    public function isFlag(): bool
    {
        return $this === self::Apply || $this === self::Mirror || $this === self::Once;
    }

    /**
     * Whether the field is files a browser sends, rather than a value in the manifest.
     *
     * @return bool
     */
    public function isUpload(): bool
    {
        return $this === self::Files || $this === self::File;
    }

    /**
     * Whether an action taking the field may be sent without it — every field a drop takes, since it
     * is sent either text or a file, and has a default for the rest.
     *
     * @return bool
     */
    public function isOptional(): bool
    {
        return match ($this) {
            self::Text, self::File, self::Filename, self::Lifetime, self::Once, self::Password => true,
            default                                                                            => false,
        };
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
            self::Target   => AdminText::FieldTarget,
            self::Command  => AdminText::FieldCommand,
            self::Text     => AdminText::FieldText,
            self::File     => AdminText::FieldFile,
            self::Filename => AdminText::FieldFilename,
            self::Lifetime => AdminText::FieldLifetime,
            self::Once     => AdminText::FieldOnce,
            self::Password => AdminText::FieldPassword,
        };
    }
}
