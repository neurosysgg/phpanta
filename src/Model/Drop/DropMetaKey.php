<?php

declare(strict_types=1);

namespace Phpanta\Model\Drop;

/**
 * The DropMetaKey enum. The keys a drop's sealed description is written with — see {@link DropMeta}.
 */
enum DropMetaKey: string
{
    /** Its {@link DropKind}. */
    case Kind = 'kind';

    /** The name its bytes are saved under, or null for text. */
    case Name = 'name';

    /** How many bytes it holds. */
    case Size = 'size';
}
