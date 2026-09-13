<?php

declare(strict_types=1);

namespace Phpanta\Test\Unit;

use Phpanta\Http\Api\ApiResult;
use Phpanta\Http\HttpStatusCode;
use Phpanta\Http\RequestHeader;
use Phpanta\Model\Health\HealthFact;
use Phpanta\Model\Health\HealthSection;
use Phpanta\Model\Health\Verdict;
use Phpanta\Support\Collection;
use Phpanta\Text\Language;
use Phpanta\View\ApiResultView;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * One admin answer, written three ways: the text a terminal reads, the data a script reads, and the
 * page a browser shows — from the same sections, so none of the three is a parse of another.
 */
#[CoversClass(ApiResult::class)]
#[CoversClass(HealthSection::class)]
#[CoversClass(HealthFact::class)]
#[CoversClass(Verdict::class)]
#[CoversClass(ApiResultView::class)]
final class ApiResultTest extends TestCase
{
    /**
     * The text is the report as it has always been written: a captioned section of facts, verdicts
     * first after the name, a captioned section of lines, and an untitled block flush left.
     *
     * @return void
     */
    public function testTheTextIsTheReportATerminalReads(): void
    {
        self::assertSame(
            "runtime\n"
            . sprintf("  %-20s %s\n", 'php', 'pass  8.5.9  (>= 8.5)')
            . sprintf("  %-20s %s\n", 'opcache', 'FAIL  -')
            . sprintf("  %-20s %s\n", 'sapi', 'cli')
            . "\n"
            . "log\n  one\n  two\n"
            . "\n"
            . "1 pass, 0 warn, 1 fail\n",
            self::sample()->text(),
        );
    }

    /**
     * An untitled section of facts is a paragraph: no caption, no indent, and a column exactly as
     * wide as its longest name — which is how `update v1 version` has always read.
     *
     * @return void
     */
    public function testAnUntitledBlockOfFactsIsFlushLeftAndTight(): void
    {
        $section = HealthSection::facts(null, new Collection(HealthFact::class)->with(
            new HealthFact('serial', '1'),
            new HealthFact('php', '8.5'),
        ));

        self::assertSame("serial 1\nphp    8.5", $section->render());
    }

    /**
     * The data says which kind each section is by which key it carries, names a verdict only where
     * there is one, and writes an empty value as empty.
     *
     * @return void
     */
    public function testTheDataSaysWhichKindEachSectionIs(): void
    {
        self::assertSame(
            '{"status":503,"sections":['
            . '{"caption":"runtime","facts":['
            . '{"name":"php","value":"8.5.9  (>= 8.5)","verdict":"pass"},'
            . '{"name":"opcache","value":"","verdict":"fail"},'
            . '{"name":"sapi","value":"cli"}]},'
            . '{"caption":"log","lines":["one","two"]},'
            . '{"caption":null,"lines":["1 pass, 0 warn, 1 fail"]}]}',
            json_encode(self::sample(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
        );
    }

    /**
     * The page is a table of facts — a verdict in a column of its own where a fact has one — and a
     * list of lines, each section under its caption where it has one.
     *
     * @return void
     */
    public function testThePageHoldsATableOfFactsAndAListOfLines(): void
    {
        $html = self::flat(self::sample()->node()->render(0, Language::English));

        self::assertStringContainsString(
            '<section><h2>runtime</h2><table>'
            . '<tr><td>php</td><td>pass</td><td>8.5.9  (&gt;= 8.5)</td></tr>'
            . '<tr><td>opcache</td><td>FAIL</td><td>-</td></tr>'
            . '<tr><td>sapi</td><td>cli</td></tr>'
            . '</table></section>',
            $html,
        );
        self::assertStringContainsString('<section><h2>log</h2><ul><li>one</li><li>two</li></ul></section>', $html);
        self::assertStringContainsString('<section><ul><li>1 pass, 0 warn, 1 fail</li></ul></section>', $html);
    }

    /**
     * A refusal is one sentence, flush left, under the status it is for.
     *
     * @return void
     */
    public function testARefusalIsOneSentence(): void
    {
        $refusal = ApiResult::refusal(HttpStatusCode::NotFound, 'no such thing');

        self::assertSame(HttpStatusCode::NotFound, $refusal->status);
        self::assertSame("no such thing\n", $refusal->text());
    }

    /**
     * The page is the address over the result, titled by the address, and says it varies on
     * `Accept` — the one header that chose it.
     *
     * @return void
     */
    public function testTheViewIsTheAddressOverTheResult(): void
    {
        $view = new ApiResultView(self::sample(), 'health/v1/report');
        $html = self::flat($view->content()->render(0, Language::English));

        self::assertSame([RequestHeader::Accept], $view->varyOn());
        self::assertStringStartsWith('health/v1/report', $view->pageTitle()->in(Language::English));
        self::assertStringStartsWith('<section><h1>health/v1/report</h1><section><h2>runtime</h2>', $html);
    }

    /**
     * A result holding every shape a section takes.
     *
     * @return ApiResult
     */
    private static function sample(): ApiResult
    {
        return ApiResult::of(
            HttpStatusCode::ServiceUnavailable,
            HealthSection::facts('runtime', new Collection(HealthFact::class)->with(
                new HealthFact('php', '8.5.9  (>= 8.5)', Verdict::Pass),
                new HealthFact('opcache', '', Verdict::Fail),
                new HealthFact('sapi', 'cli'),
            )),
            HealthSection::lines('log', 'one', 'two'),
            HealthSection::lines(null, '1 pass, 0 warn, 1 fail'),
        );
    }

    /**
     * $html with the indentation between tags taken out, so an assertion reads the structure.
     *
     * @param string $html
     * @return string
     */
    private static function flat(string $html): string
    {
        return (string) preg_replace('/>\s+</', '><', $html);
    }
}
