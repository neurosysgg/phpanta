<?php

declare(strict_types=1);

namespace Phpanta\View\Html;

/**
 * The MediaPreload enum. How much of a media file the browser fetches before anyone presses play.
 *
 * An attribute *value* rather than a name, the same arrangement as {@link LinkRel},
 * {@link LinkTarget} and {@link ScriptType}: a fixed vocabulary, so it is a case and not a string.
 * Server-only, like those three — nothing client-side reads it — so it has no TypeScript mirror and
 * wants none.
 *
 * **The one this site writes is {@link self::None}, and on a demo page that is a decision rather
 * than a default.** The attribute's own default is `metadata`, which fetches the beginning of every
 * track on the page whether or not anybody plays one. Here every one of those fetches goes through
 * `DemoAudioController` — a PHP process on shared hosting reading a file
 * off disk — for a page that routinely carries four mixes of the same track. `none` means a demo
 * page costs one request until a listener asks for audio.
 */
enum MediaPreload: string
{
    /** Fetch nothing until play is pressed. What every `<audio>` here says. */
    case None = 'none';

    /** Fetch enough to know the duration. The attribute's default, and not what is wanted here. */
    case Metadata = 'metadata';

    /** Fetch the whole thing straight away. Never used here; named so the vocabulary is complete. */
    case Auto = 'auto';
}
