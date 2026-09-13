<?php

declare(strict_types=1);

namespace Phpanta\Text;

use Phpanta\Support\Collection;

/**
 * The LanguageAddresses enum. Whether a page in each language has an address of its own.
 *
 * **Shared**, the default: every language is at the same address, and the request chooses — the
 * `lang` cookie, then `Accept-Language`, then the app's default; see {@link \Phpanta\Http\Request::language()}.
 * Right for a site served by PHP, which can answer one address differently for each visitor, and
 * say so with `Vary`.
 *
 * **Suffixed**: each language also has an address that names it — `/rules.de.html`, `/index.en.html`
 * — as a {@link LanguageAddress} spells it. The request's path is the page without the suffix, so no
 * route and no controller knows the difference, and the language the address names outranks every
 * other choice. Right for a site that is also exported to a static host, which cannot choose: the
 * export writes each page once more at each language's address, and a link built with
 * {@link \Phpanta\Support\FillsPlaceholders::inEachLanguage()} leads to the page in the language it
 * was rendered in.
 *
 * A site chooses with {@link \Phpanta\App::languageAddresses()}.
 */
enum LanguageAddresses: string
{
    /** Every language at the page's one address. */
    case Shared = 'shared';

    /** Each language at an address of its own, as well as the page's plain one. */
    case Suffixed = 'suffixed';

    /**
     * The page and language a request's path names, or null where it names no language.
     *
     * @param string $address
     * @param Languages $languages
     * @return LanguageAddress|null
     */
    public function read(string $address, Languages $languages): ?LanguageAddress
    {
        return $this === self::Suffixed ? LanguageAddress::read($address, $languages) : null;
    }

    /**
     * Where $page is in $language: its own address when languages are suffixed, the page when shared.
     *
     * @param string $page
     * @param Language $language
     * @return string
     */
    public function address(string $page, Language $language): string
    {
        return $this === self::Suffixed ? LanguageAddress::of($page, $language)->address : $page;
    }

    /**
     * Every address a static copy of $page is written at: the page's own in the default language,
     * then, when languages are suffixed, each offered language's.
     *
     * @param string $page
     * @param Languages $languages
     * @return Collection<LanguageAddress>
     */
    public function exported(string $page, Languages $languages): Collection
    {
        $addresses = new Collection(LanguageAddress::class)
            ->with(new LanguageAddress($page, $page, $languages->default()));

        if ($this === self::Shared) {
            return $addresses;
        }

        foreach ($languages->offered() as $language) {
            $addresses = $addresses->with(LanguageAddress::of($page, $language));
        }

        return $addresses;
    }
}
