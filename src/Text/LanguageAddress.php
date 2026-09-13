<?php

declare(strict_types=1);

namespace Phpanta\Text;

/**
 * The LanguageAddress class. One page in one language, at the address that names the language:
 * `/rules.de.html` is `/rules`, in German.
 *
 * **The address a static host can serve.** A host with no PHP cannot choose a language from a cookie
 * or from `Accept-Language`, so a page it serves in two languages has to be two files — and a file
 * name is the one thing it reads. `.de.html` is that name: the page's path, the language's tag, and
 * the extension any static host already maps to a page. The root, which has no name of its own, is
 * `index`, the name a static host already gives it.
 *
 * **A tag counts only when the app offers it.** `/rules.fr.html` on a site in English and German
 * is no language address at all, just a path nobody routes — the 404 it would have been — rather
 * than a German page under a French name or an English one under any name at all.
 *
 * Read and written in one place, so the two cannot disagree about the grammar.
 */
final readonly class LanguageAddress
{
    /** A page's path, a language tag, and `.html`: the grammar of an address that names its language. */
    private const string GRAMMAR = '#\A(/.+)\.([a-z]+)\.html\z#';

    /** What the root is called when it needs a name, as a static host already calls it. */
    private const string ROOT = '/index';

    /**
     * Constructs an instance of {@link self}.
     *
     * @param string $address Where it is: `/rules.de.html`.
     * @param string $page The page it is: `/rules`.
     * @param Language $language The language it is in.
     */
    public function __construct(public string $address, public string $page, public Language $language) {}

    /**
     * $page in $language, at the address that names the language.
     *
     * @param string $page A path, as {@link \Phpanta\Support\Path::to()} writes one.
     * @param Language $language
     * @return self
     */
    public static function of(string $page, Language $language): self
    {
        return new self(
            ($page === '/' ? self::ROOT : $page) . '.' . $language->value . '.html',
            $page,
            $language,
        );
    }

    /**
     * The page and language $address names, or null where it names none $languages offers.
     *
     * @param string $address A request's raw path.
     * @param Languages $languages
     * @return self|null
     */
    public static function read(string $address, Languages $languages): ?self
    {
        if (preg_match(self::GRAMMAR, $address, $parts) !== 1) {
            return null;
        }

        $language = $languages->tryFrom($parts[2]);

        if ($language === null) {
            return null;
        }

        return new self($address, $parts[1] === self::ROOT ? '/' : $parts[1], $language);
    }
}
