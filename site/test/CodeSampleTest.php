<?php

declare(strict_types=1);

namespace PhpantaSite\Test;

use Dom\Element;
use Dom\HTMLDocument;
use Phpanta\Text\Language;
use PhpantaSite\CodeAttribute;
use PhpantaSite\CodeSample;
use PhpantaSite\CodeTag;
use PhpantaSite\Prose;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The samples, as elements: every character still there and in its place, one `<code-line>` per
 * line, and the pieces a reader looks for named for what they are.
 */
final class CodeSampleTest extends TestCase
{
    /**
     * @return iterable<string, array{CodeSample}>
     */
    public static function sampleProvider(): iterable
    {
        foreach (CodeSample::cases() as $sample) {
            yield $sample->value => [$sample];
        }
    }

    /**
     * Highlighting only adds elements. What the reader sees — and copies — is the sample exactly, a
     * newline and every run of spaces included, which is what a line broken across the source by
     * the tree would change.
     *
     * @param CodeSample $sample
     * @return void
     */
    #[DataProvider('sampleProvider')]
    public function testASampleReadsExactlyAsItsText(CodeSample $sample): void
    {
        self::assertSame($sample->text(), self::block($sample)->textContent);
    }

    /**
     * @param CodeSample $sample
     * @return void
     */
    #[DataProvider('sampleProvider')]
    public function testABlockNamesItsLanguageAndHoldsALinePerLine(CodeSample $sample): void
    {
        $block = self::block($sample);

        self::assertSame($sample->language()->value, $block->getAttribute(CodeAttribute::Language->value));
        self::assertSame(
            substr_count($sample->text(), "\n") + 1,
            $block->querySelectorAll(CodeTag::Block->value . ' > ' . CodeTag::Line->value)->length,
        );
        self::assertNotSame(
            0,
            $block->querySelectorAll(CodeTag::Line->value . ' > *')->length,
            'nothing is highlighted',
        );
    }

    /**
     * @param CodeSample $sample
     * @return void
     */
    #[DataProvider('sampleProvider')]
    public function testNoSpanOrPreIsLeft(CodeSample $sample): void
    {
        $html = Prose::sample($sample)->render(0, Language::English);

        self::assertStringNotContainsString('<span', $html);
        self::assertStringNotContainsString('<pre', $html);
    }

    /**
     * @return void
     */
    public function testPhpIsReadByPhpsOwnTokenizer(): void
    {
        $keywords = self::marked(CodeSample::TheApp, CodeTag::Keyword);

        foreach (['final', 'class', 'extends', 'public', 'function', 'return', 'string', 'new', 'fn'] as $keyword) {
            self::assertContains($keyword, $keywords);
        }

        self::assertContains('Site', self::marked(CodeSample::TheApp, CodeTag::Type));
        self::assertContains('Directory', self::marked(CodeSample::TheApp, CodeTag::Type));
        self::assertContains('dirname', self::marked(CodeSample::TheApp, CodeTag::Call));
        self::assertContains("'Acme'", self::marked(CodeSample::TheApp, CodeTag::String));
        self::assertNotContains('Site', $keywords);
    }

    /**
     * @return void
     */
    public function testAShellLineIsACommandItsFlagsAndAComment(): void
    {
        self::assertContains('tsc', self::marked(CodeSample::Building, CodeTag::Command));
        self::assertContains('# assets/ts/ → public/assets/js/', self::marked(CodeSample::Building, CodeTag::Comment));
        self::assertContains('--out', self::marked(CodeSample::Export, CodeTag::Flag));
        self::assertNotContains('build/pages', self::marked(CodeSample::Export, CodeTag::Flag));
    }

    /**
     * @return void
     */
    public function testATreeLineIsItsBranchesAnEntryAndANote(): void
    {
        self::assertContains('├── ', self::marked(CodeSample::Layout, CodeTag::Branch));
        self::assertContains(
            'requires phpanta/autoload.php, maps the site\'s namespace, boots the app',
            self::marked(CodeSample::Layout, CodeTag::Comment),
        );
        self::assertNotContains('autoload.php', self::marked(CodeSample::Layout, CodeTag::Comment));
    }

    /**
     * The sample's `<code-block>`, as a browser parses it.
     *
     * @param CodeSample $sample
     * @return Element
     */
    private static function block(CodeSample $sample): Element
    {
        $html  = Prose::sample($sample)->render(0, Language::English);
        $block = HTMLDocument::createFromString('<!DOCTYPE html>' . $html)->querySelector(CodeTag::Block->value);

        self::assertNotNull($block);

        return $block;
    }

    /**
     * The text of every $tag in $sample.
     *
     * @param CodeSample $sample
     * @param CodeTag    $tag
     * @return list<string>
     */
    private static function marked(CodeSample $sample, CodeTag $tag): array
    {
        $texts = [];

        foreach (self::block($sample)->querySelectorAll($tag->value) as $element) {
            $texts[] = $element->textContent;
        }

        return $texts;
    }
}
