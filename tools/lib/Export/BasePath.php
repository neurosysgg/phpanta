<?php

declare(strict_types=1);

namespace Phpanta\Tool\Export;

use Dom\HTMLDocument;
use Phpanta\Support\Collection;
use Phpanta\Tool\Cli\UsageException;
use Phpanta\View\Html\Vocabulary;

/**
 * The BasePath class. The path a static export is served under, and the rewrite that puts it there.
 *
 * A site addresses everything from its own root — `/getting-started`, `/assets/js/v-…/main.js` —
 * because that is what a site is at, and nothing under `src/` knows or should know otherwise. A
 * project page on GitHub Pages is served one directory down, at `/phpanta/`, so every one of those
 * addresses has to move. **That happens here, once, to the files an export wrote, and not at
 * runtime**: a base path the running site had to carry would be one more thing every link, every
 * route and every test had to agree on, for a deployment only one kind of host has.
 *
 * Two halves, because a rewrite that can miss a spelling is only half a guarantee:
 *
 * - **The rewrite** is textual and narrow. Every attribute the markup tree writes has the one shape
 *   {@link \Phpanta\View\Html\Element} renders — `name="value"`, the value escaped — so a value that
 *   starts with one slash gets the base in front of it, **if the attribute is one the app's
 *   vocabulary says holds a URL**: a `title` or a `content` that happens to start with a slash is
 *   text, and moving it would change what the page says. A stylesheet's `url()`s, `@import`
 *   strings and `image-set()` strings get the same. Scripts are not touched: a string in a script
 *   is not known to be an address, and a script that builds one from the root has to build it from
 *   the page's.
 * - **The check** reads what was written with a real HTML parser, and lists every root-absolute
 *   address that still lacks the base — a `srcset` candidate, a `url()` in a `style` attribute, an
 *   attribute no vocabulary calls a URL, anything the rewrite did not cover. The export fails on any.
 */
final readonly class BasePath
{
    /** A path of plain segments, starting and ending with a slash: `/`, `/phpanta/`, `/a/b/`. */
    private const string SHAPE = '#^/(?:[A-Za-z0-9._~-]+/)*\z#';

    /** An attribute whose value starts with exactly one slash, as Element renders one; 2 is its name. */
    private const string ATTRIBUTE = '#(\s([A-Za-z][A-Za-z0-9:._-]*)=")/(?!/)#';

    /** A stylesheet `url()` whose address starts with exactly one slash, quoted or not. */
    private const string CSS_URL = '#(url\(\s*[\'"]?)/(?!/)#i';

    /** An `@import` written as a bare string rather than a `url()`. */
    private const string CSS_IMPORT = '#(@import\s+[\'"])/(?!/)#i';

    /** An `image-set()`, prefixed or not; 2 is what is between its parentheses. */
    private const string IMAGE_SET = '#((?:-webkit-)?image-set\()((?:[^()]|\([^()]*\))*)(\))#i';

    /**
     * Inside an `image-set()`: a `url()`, which {@link self::CSS_URL} moves, or a string starting
     * with one slash, which is an address written without one. 1 is set only for the second.
     */
    private const string IMAGE_SET_ENTRY = '#url\([^)]*\)|([\'"])/(?!/)#i';

    /**
     * Constructs an instance of {@link self}.
     *
     * @param string       $path          Where the export is served: `/` for a host's root,
     *                                    `/phpanta/` for a project page.
     * @param list<string> $urlAttributes The attribute names whose values are addresses — see
     *                                    {@link self::urlAttributesOf()}.
     *
     * @throws UsageException if $path is not a path of plain segments with a slash at each end.
     */
    public function __construct(public string $path, private array $urlAttributes)
    {
        if (preg_match(self::SHAPE, $path) !== 1) {
            throw new UsageException(sprintf(
                "--base '%s' is not a path like / or /phpanta/ — plain segments, a slash at each end.",
                $path,
            ));
        }
    }

    /**
     * Every attribute name $vocabulary holds whose value is an address — `href` and `src`, and
     * whatever a site's own elements add, such as a `fallback` image.
     *
     * @param Vocabulary $vocabulary
     * @return list<string>
     */
    public static function urlAttributesOf(Vocabulary $vocabulary): array
    {
        $names = [];

        foreach ($vocabulary->attributes() as $enum) {
            foreach ($enum::cases() as $case) {
                if ($case->isUrl()) {
                    $names[] = $case->attribute();
                }
            }
        }

        return array_values(array_unique($names));
    }

    /**
     * $markup with the base in front of every address attribute that starts at the root.
     *
     * @param string $markup
     * @return string
     */
    public function html(string $markup): string
    {
        if ($this->path === '/') {
            return $markup;
        }

        return (string) preg_replace_callback(
            self::ATTRIBUTE,
            fn(array $match): string => in_array($match[2], $this->urlAttributes, true)
                ? $match[1] . $this->path
                : $match[0],
            $markup,
        );
    }

    /**
     * $css with the base in front of every `url()`, `@import` string and `image-set()` string that
     * starts at the root.
     *
     * @param string $css
     * @return string
     */
    public function css(string $css): string
    {
        if ($this->path === '/') {
            return $css;
        }

        // image-set()'s strings first, leaving its url()s for the pass below — moving one twice would
        // put the base in front of itself.
        $css = (string) preg_replace_callback(
            self::IMAGE_SET,
            fn(array $set): string => $set[1] . preg_replace_callback(
                self::IMAGE_SET_ENTRY,
                fn(array $entry): string => ($entry[1] ?? '') !== '' ? $entry[1] . $this->path : $entry[0],
                $set[2],
            ) . $set[3],
            $css,
        );
        $css = (string) preg_replace(self::CSS_IMPORT, '$1' . $this->path, $css);

        return (string) preg_replace(self::CSS_URL, '$1' . $this->path, $css);
    }

    /**
     * Every root-absolute address $markup's elements carry, as a parser reads them.
     *
     * An attribute value that starts with one slash; each candidate of a `srcset`; each address in
     * a `style` attribute or a `<style>` element. Whether each carries the base, and whether the file
     * it names was written, is the caller's question — see {@link self::lacksBase()}.
     *
     * @param string $markup
     * @return Collection<string>
     */
    public function addresses(string $markup): Collection
    {
        $document  = HTMLDocument::createFromString($markup, LIBXML_NOERROR);
        $addresses = new Collection('string');

        foreach ($document->getElementsByTagName('*') as $element) {
            foreach ($element->attributes as $attribute) {
                $candidates = match ($attribute->name) {
                    'srcset' => self::srcset($attribute->value),
                    'style'  => self::cssAddresses($attribute->value),
                    default  => [$attribute->value],
                };

                foreach ($candidates as $candidate) {
                    if (self::fromRoot($candidate)) {
                        $addresses = $addresses->with($candidate);
                    }
                }
            }

            if ($element->localName === 'style') {
                foreach (self::cssAddresses((string) $element->textContent) as $candidate) {
                    $addresses = $addresses->with($candidate);
                }
            }
        }

        return $addresses;
    }

    /**
     * Every root-absolute address in $css.
     *
     * @param string $css
     * @return Collection<string>
     */
    public function stylesheetAddresses(string $css): Collection
    {
        return new Collection('string')->with(...self::cssAddresses($css));
    }

    /**
     * Whether $address is root-absolute and not under the base — one the rewrite missed.
     *
     * @param string $address
     * @return bool
     */
    public function lacksBase(string $address): bool
    {
        return self::fromRoot($address) && !str_starts_with($address, $this->path);
    }

    /**
     * $address with the base taken off, as a path under the export's root: `/phpanta/x` is `x`.
     *
     * @param string $address An address that carries the base.
     * @return string
     */
    public function withinExport(string $address): string
    {
        return substr($address, strlen($this->path));
    }

    /**
     * Whether $value is an address from the root — one slash, not two (which is another host).
     *
     * @param string $value
     * @return bool
     */
    private static function fromRoot(string $value): bool
    {
        return str_starts_with($value, '/') && !str_starts_with($value, '//');
    }

    /**
     * The addresses of a `srcset`'s candidates.
     *
     * @param string $value
     * @return list<string>
     */
    private static function srcset(string $value): array
    {
        $addresses = [];

        foreach (explode(',', $value) as $candidate) {
            $addresses[] = (string) strtok(trim($candidate), " \t\n");
        }

        return $addresses;
    }

    /**
     * The root-absolute addresses in $css: its `url()`s, its `@import` strings, and the strings of
     * its `image-set()`s — each once, however many of those spell it.
     *
     * @param string $css
     * @return list<string>
     */
    private static function cssAddresses(string $css): array
    {
        preg_match_all('#url\(\s*[\'"]?(/(?!/)[^\'")\s]*)#i', $css, $urls);
        preg_match_all('#@import\s+[\'"](/(?!/)[^\'"]*)#i', $css, $imports);
        preg_match_all(self::IMAGE_SET, $css, $sets);

        $addresses = [...$urls[1], ...$imports[1]];

        foreach ($sets[2] as $set) {
            preg_match_all('#([\'"])(/(?!/)[^\'"]*)\1#', $set, $strings);
            array_push($addresses, ...$strings[2]);
        }

        return array_values(array_unique($addresses));
    }
}
