<?php

declare(strict_types=1);

namespace Phpanta\Http\Api;

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
enum ActionField: string
{
    /** Carry the action out; without it, a dry run that changes nothing and spends no serial. */
    case Apply = 'apply';

    /** Remove what a pushed tree leaves out. */
    case Mirror = 'mirror';

    /**
     * What the field does, in the caller's language.
     *
     * @return Translatable
     */
    public function describe(): Translatable
    {
        return match ($this) {
            self::Apply  => AdminText::FieldApply,
            self::Mirror => AdminText::FieldMirror,
        };
    }
}
