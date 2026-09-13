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
 * **{@link self::None} is usually the one to write, and on a page of several tracks it is a
 * decision rather than a default.** The attribute's own default is `metadata`, which fetches the
 * beginning of every track on the page whether or not anybody plays one. Where the audio is served
 * by PHP rather than as a static file — behind a password, say — every one of those fetches is a
 * PHP process on shared hosting reading a file off disk. `none` means the page costs one request
 * until a listener asks for audio.
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
