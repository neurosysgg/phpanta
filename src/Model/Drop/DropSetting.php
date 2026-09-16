<?php

declare(strict_types=1);

namespace Phpanta\Model\Drop;

/**
 * The DropSetting enum. The keys `data/drop.json` is written with.
 *
 * ```json
 * { "maxBytes": 8388608, "maxLifetime": 604800 }
 * ```
 *
 * See {@link DropConfig} for what each means and what an absent or mistyped one reads as.
 */
enum DropSetting: string
{
    /** The largest drop kept, in bytes. {@link DropConfig::MAX_BYTES} where absent. */
    case MaxBytes = 'maxBytes';

    /** The longest a drop may be kept, in seconds. {@link DropConfig::MAX_LIFETIME} where absent. */
    case MaxLifetime = 'maxLifetime';
}
