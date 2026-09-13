<?php

declare(strict_types=1);

namespace Phpanta\Http\Security;

use Phpanta\Http\HeaderValue;

/**
 * The CrossOriginResourcePolicy enum. Which origins may load a response this app answers as a
 * resource — an image, a script, an audio file — without asking.
 *
 * `same-origin`, by default. It covers what PHP answers: pages, and the files a gate stands in front
 * of — a demo's audio, say — which no other site has any business embedding, and which a
 * side-channel attack would otherwise be free to load into its own page to measure. It does not
 * cover what the web server answers straight from the webroot, which never reaches PHP; a site that
 * wants its public assets embeddable elsewhere is untouched by it. A site that serves something meant
 * to be embedded says `cross-origin` in {@link \Phpanta\App::crossOriginResourcePolicy()}.
 */
enum CrossOriginResourcePolicy: string implements HeaderValue
{
    /** Only this origin may load it. */
    case SameOrigin = 'same-origin';

    /** Any origin on the same site — the registrable domain — may load it. */
    case SameSite = 'same-site';

    /** Anyone may load it. */
    case CrossOrigin = 'cross-origin';

    /**
     * @return string
     */
    public function render(): string
    {
        return $this->value;
    }
}
