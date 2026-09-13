<?php

declare(strict_types=1);

namespace Phpanta\View\Html;

/**
 * The ViewportContent class. The `content` of the viewport meta tag.
 *
 * A class rather than an enum for the reason {@link \Phpanta\Http\Security\StrictTransportSecurity}
 * and {@link \Phpanta\Http\MimeType} are: the value carries **parameters**, and a case cannot hold
 * one. `width=device-width, initial-scale=1.0` is a comma-separated descriptor list — a grammar,
 * which as a string would be assembled inside the `->attr(…)` call in `Layout::head()`,
 * precisely where a grammar cannot be checked. A grammar is what earns a type here, not what
 * excuses one — the same argument {@link \Phpanta\Http\HeaderValue} makes on the header side.
 *
 * What it buys is that both halves are checked. The width is a {@link ViewportWidth} case, so
 * `device-widht` is a compile-time error rather than a page that lays out at 980px on every phone
 * with nothing in any console; and the scale is a `float`, so `initial-scale=1,0` cannot be written
 * at all.
 */
final readonly class ViewportContent implements AttributeValue
{
    /**
     * Constructs an instance of {@link self}.
     *
     * Both parameters carry the site's own answer as their default, so the one call site names them
     * for legibility rather than out of necessity — the same way a `Release`
     * spells out `title:` and `bpm:`.
     *
     * @param ViewportWidth $width        How wide to pretend the screen is.
     * @param float         $initialScale The zoom level the page opens at. `1.0` is life size.
     */
    public function __construct(
        private ViewportWidth $width = ViewportWidth::Device,
        private float $initialScale = 1.0,
    ) {}

    /**
     * Returns the descriptor list: `width=device-width, initial-scale=1.0`.
     *
     * @return string
     */
    public function render(): string
    {
        return 'width=' . $this->width->value . ', initial-scale=' . $this->scale();
    }

    /**
     * The scale, written the way a viewport meta tag writes one.
     *
     * **Not `(string) $this->initialScale`**, which renders `1.0` as `1`. That is a legal viewport
     * scale and would have changed bytes this site has always emitted for no reason at all — the
     * same instinct that kept {@link \Phpanta\Support\Charset} carrying two spellings of one
     * encoding. So a whole number keeps one decimal place, and anything finer keeps exactly the
     * digits it has: `0.5` and `1.25` come back as themselves rather than rounded to a fixed width.
     *
     * `%F` and not `%f`, because `%f` is locale-aware: under a German locale it writes `1,0`, and a
     * comma is the descriptor separator in this grammar — so the one page-wide layout instruction
     * on the site would silently become two malformed ones.
     *
     * @return string
     */
    private function scale(): string
    {
        $rendered = rtrim(rtrim(sprintf('%.4F', $this->initialScale), '0'), '.');

        return str_contains($rendered, '.') ? $rendered : $rendered . '.0';
    }
}
