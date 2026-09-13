<?php

declare(strict_types=1);

namespace Phpanta\Tool\Export;

use Dom\HTMLDocument;
use Phpanta\Support\Collection;
use Phpanta\Tool\Cli\UsageException;

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
 *   starts with one slash gets the base in front of it, whatever the attribute is called. A
 *   stylesheet's `url()`s get the same. Scripts are not touched: a string in a script is not known
 *   to be an address, and a script that builds one from the root has to build it from the page's.
 * - **The check** reads what was written with a real HTML parser, and lists every root-absolute
 *   address that still lacks the base — a `srcset` candidate after the first, a `url()` in a
 *   `style` attribute, anything the rewrite's one shape did not cover. The export fails on any.
 */
final readonly class BasePath
{
    /** A path of plain segments, starting and ending with a slash: `/`, `/phpanta/`, `/a/b/`. */
    private const string SHAPE = '#^/(?:[A-Za-z0-9._~-]+/)*\z#';

    /** An attribute whose value starts with exactly one slash, as Element renders one. */
    private const string ATTRIBUTE = '#(\s[A-Za-z][A-Za-z0-9:._-]*=")/(?!/)#';

    /** A stylesheet `url()` whose address starts with exactly one slash, quoted or not. */
    private const string CSS_URL = '#(url\(\s*[\'"]?)/(?!/)#i';

    /**
     * Constructs an instance of {@link self}.
     *
     * @param string $path Where the export is served: `/` for a host's root, `/phpanta/` for a
     *                     project page.
     *
     * @throws UsageException if $path is not a path of plain segments with a slash at each end.
     */
    public function __construct(public string $path)
    {
        if (preg_match(self::SHAPE, $path) !== 1) {
            throw new UsageException(sprintf(
                "--base '%s' is not a path like / or /phpanta/ — plain segments, a slash at each end.",
                $path,
            ));
        }
    }

    /**
     * $markup with the base in front of every attribute value that starts at the root.
     *
     * @param string $markup
     * @return string
     */
    public function html(string $markup): string
    {
        return $this->path === '/' ? $markup : (string) preg_replace(self::ATTRIBUTE, '$1' . $this->path, $markup);
    }

    /**
     * $css with the base in front of every `url()` that starts at the root.
     *
     * @param string $css
     * @return string
     */
    public function css(string $css): string
    {
        return $this->path === '/' ? $css : (string) preg_replace(self::CSS_URL, '$1' . $this->path, $css);
    }

    /**
     * Every root-absolute address $markup's elements carry, as a parser reads them.
     *
     * An attribute value that starts with one slash; each candidate of a `srcset`; each `url()` in a
     * `style` attribute or a `<style>` element. Whether each carries the base, and whether the file
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
     * Every root-absolute `url()` address in $css.
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
     * The root-absolute addresses of $css's `url()`s.
     *
     * @param string $css
     * @return list<string>
     */
    private static function cssAddresses(string $css): array
    {
        preg_match_all('#url\(\s*[\'"]?(/(?!/)[^\'")\s]*)#i', $css, $matches);

        return $matches[1];
    }
}
