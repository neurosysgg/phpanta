<?php

declare(strict_types=1);

namespace Phpanta\Test\Unit;

use Dom\HTMLDocument;
use PHPUnit\Framework\TestCase;

/**
 * The framework's own site, `site/`: every subheading on its pages carries a hand-written anchor and
 * is a link to it.
 *
 * Hand-written, so that rewording a heading does not move the address every link to it names; and
 * the heading is the link, so a reader can take the address from it. The export fails on a link to
 * an anchor a page lacks; this holds that every heading has one to land on. No `#[CoversClass]`:
 * it reads the pages, not a class.
 */
final class SitePagesTest extends TestCase
{
    /** An anchor as the pages spell one: lower-case words, joined by hyphens. */
    private const string ID = '/^[a-z0-9]+(?:-[a-z0-9]+)*\z/';

    /**
     * @return void
     */
    public function testEverySubheadingLinksToAnAnchorOfItsOwn(): void
    {
        $pages    = glob(PHPANTA_ROOT . '/site/data/*.html') ?: [];
        $headings = 0;

        self::assertNotEmpty($pages, 'site/data/ holds no pages to read.');

        foreach ($pages as $page) {
            $name     = basename($page);
            $document = HTMLDocument::createFromString((string) file_get_contents($page), LIBXML_NOERROR);
            $seen     = [];

            foreach ($document->querySelectorAll('h2, h3') as $heading) {
                $id    = (string) $heading->getAttribute('id');
                $label = trim((string) $heading->textContent);
                $link  = $heading->firstElementChild;

                self::assertMatchesRegularExpression(self::ID, $id, "$name: \"$label\" has no anchor.");
                self::assertNotContains($id, $seen, "$name: #$id is on two headings.");
                self::assertSame(1, $heading->childElementCount, "$name: \"$label\" holds more than its link.");
                self::assertSame('a', $link?->localName, "$name: \"$label\" is not a link.");
                self::assertSame("#$id", $link->getAttribute('href'), "$name: \"$label\" links elsewhere.");

                $seen[] = $id;
                $headings++;
            }
        }

        self::assertGreaterThan(0, $headings, 'No page in site/data/ has a subheading to check.');
    }
}
