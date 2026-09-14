<?php

declare(strict_types=1);

namespace PhpantaSite\Test;

use Dom\HTMLDocument;
use Phpanta\Text\Language;
use PhpantaSite\CodeSample;
use PhpantaSite\Prose;
use PhpantaSite\Token;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The samples, highlighted: every character still there and in its place, and the pieces a reader
 * looks for marked as what they are.
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
     * Highlighting only adds spans. What the reader sees — and copies — is the sample exactly, a
     * newline and every run of spaces included, which is what a `<pre>` rendered on more than one
     * line would break.
     *
     * @param CodeSample $sample
     * @return void
     */
    #[DataProvider('sampleProvider')]
    public function testASampleReadsExactlyAsItsText(CodeSample $sample): void
    {
        self::assertSame($sample->text(), self::pre($sample)->textContent);
    }

    /**
     * @param CodeSample $sample
     * @return void
     */
    #[DataProvider('sampleProvider')]
    public function testEverySampleIsHighlighted(CodeSample $sample): void
    {
        self::assertNotSame(0, self::pre($sample)->querySelectorAll('span')->length);
    }

    /**
     * @return void
     */
    public function testPhpIsReadByPhpsOwnTokenizer(): void
    {
        $keywords = self::marked(CodeSample::TheApp, Token::Keyword);

        foreach (['final', 'class', 'extends', 'public', 'function', 'return', 'string', 'new', 'fn'] as $keyword) {
            self::assertContains($keyword, $keywords);
        }

        self::assertContains('Site', self::marked(CodeSample::TheApp, Token::Type));
        self::assertContains('Directory', self::marked(CodeSample::TheApp, Token::Type));
        self::assertContains('dirname', self::marked(CodeSample::TheApp, Token::Call));
        self::assertContains("'Acme'", self::marked(CodeSample::TheApp, Token::String));
        self::assertNotContains('Site', $keywords);
    }

    /**
     * @return void
     */
    public function testAShellLineIsACommandItsFlagsAndAComment(): void
    {
        self::assertContains('tsc', self::marked(CodeSample::Building, Token::Command));
        self::assertContains('# assets/ts/ → public/assets/js/', self::marked(CodeSample::Building, Token::Comment));
        self::assertContains('--out', self::marked(CodeSample::Export, Token::Flag));
        self::assertNotContains('build/pages', self::marked(CodeSample::Export, Token::Flag));
    }

    /**
     * @return void
     */
    public function testATreeLineIsItsBranchesAnEntryAndANote(): void
    {
        self::assertContains('├── ', self::marked(CodeSample::Layout, Token::Branch));
        self::assertContains(
            'requires phpanta/autoload.php, maps the site\'s namespace, boots the app',
            self::marked(CodeSample::Layout, Token::Comment),
        );
        self::assertNotContains('autoload.php', self::marked(CodeSample::Layout, Token::Comment));
    }

    /**
     * The sample's `<pre>`, as a browser parses it.
     *
     * @param CodeSample $sample
     * @return \Dom\Element
     */
    private static function pre(CodeSample $sample): \Dom\Element
    {
        $html = Prose::sample($sample)->render(0, Language::English);
        $pre  = HTMLDocument::createFromString('<!DOCTYPE html>' . $html)->querySelector('pre');

        self::assertNotNull($pre);

        return $pre;
    }

    /**
     * The text of every span in $sample marked as $token.
     *
     * @param CodeSample $sample
     * @param Token $token
     * @return list<string>
     */
    private static function marked(CodeSample $sample, Token $token): array
    {
        $texts = [];

        foreach (self::pre($sample)->querySelectorAll('span.' . $token->value) as $span) {
            $texts[] = $span->textContent;
        }

        return $texts;
    }
}
