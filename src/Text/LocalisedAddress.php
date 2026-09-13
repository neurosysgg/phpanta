<?php

declare(strict_types=1);

namespace Phpanta\Text;

use Phpanta\App;

/**
 * The LocalisedAddress class. A link to a page that leads to it in the language the link is rendered
 * in.
 *
 * An `href` whose value is this resolves the way translated text does — in the nearest `lang` — so
 * the German page's link to `/rules` is `/rules.de.html` in an app whose languages have addresses of
 * their own, and plainly `/rules` in one whose languages share them, which is right there too: every
 * language is at the one address. A view writes one link and never names a language. Built by
 * {@link \Phpanta\Support\FillsPlaceholders::inEachLanguage()}.
 */
final readonly class LocalisedAddress implements Translatable
{
    /**
     * Constructs an instance of {@link self}.
     *
     * @param string $page The page, as {@link \Phpanta\Support\Path::to()} wrote it.
     */
    public function __construct(private string $page) {}

    /**
     * @param Language $language
     * @return string
     */
    public function in(Language $language): string
    {
        return App::current()->languageAddresses()->address($this->page, $language);
    }
}
