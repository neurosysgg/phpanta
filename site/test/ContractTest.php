<?php

declare(strict_types=1);

namespace PhpantaSite\Test;

use PhpantaSite\CodeTag;
use PhpantaSite\SampleLanguage;
use PHPUnit\Framework\TestCase;

/**
 * The names three places must agree on — the server's enums, the client's mirror, the stylesheet —
 * where a disagreement is silent: a tag the client never registers, a rule no element matches, a
 * language drawn in no colour.
 */
final class ContractTest extends TestCase
{
    /** A custom element in a selector: `code-` and a word, not part of a longer name or a property. */
    private const string CODE_TAG = '/(?<![\w-])(code-[a-z]+)(?![\w-])/';

    /** A language in an attribute selector. */
    private const string LANGUAGE = '/\[language="([^"]*)"\]/';

    /** An enum member in the TypeScript mirror: `Name = 'value',`. */
    private const string MIRRORED = "/^\\s*(\\w+)\\s*=\\s*'([^']*)',?\\s*$/m";

    /**
     * @return void
     */
    public function testTheClientMirrorsEveryCodeTag(): void
    {
        $source = file_get_contents(dirname(__DIR__) . '/assets/ts/model/CodeTag.ts');

        self::assertIsString($source);
        preg_match_all(self::MIRRORED, $source, $members);

        $cases = [];
        foreach (CodeTag::cases() as $tag) {
            $cases[$tag->name] = $tag->value;
        }

        self::assertSame($cases, array_combine($members[1], $members[2]));
    }

    /**
     * Every tag but the line is styled, and nothing styled is not a tag. A line has no look of its
     * own: it is the sample's text, in the block's.
     *
     * @return void
     */
    public function testEveryCodeTagButTheLineIsStyledAndNothingElseIs(): void
    {
        $tags = [];
        foreach (CodeTag::cases() as $tag) {
            if ($tag !== CodeTag::Line) {
                $tags[] = $tag->value;
            }
        }
        sort($tags);

        self::assertSame($tags, self::selected(self::CODE_TAG));
    }

    /**
     * @return void
     */
    public function testEveryLanguageIsStyledAndNothingElseIs(): void
    {
        $languages = [];
        foreach (SampleLanguage::cases() as $language) {
            $languages[] = $language->value;
        }
        sort($languages);

        self::assertSame($languages, self::selected(self::LANGUAGE));
    }

    /**
     * What $pattern captures across the stylesheet's parts, each once, sorted. Comments are dropped
     * first: prose naming a tag is not a rule for it.
     *
     * @param string $pattern
     * @return list<string>
     */
    private static function selected(string $pattern): array
    {
        $css = '';
        foreach (glob(dirname(__DIR__) . '/assets/css/*.css') ?: [] as $part) {
            $css .= file_get_contents($part);
        }

        preg_match_all($pattern, (string) preg_replace('#/\*.*?\*/#s', '', $css), $matches);

        $found = array_values(array_unique($matches[1]));
        sort($found);

        self::assertNotSame([], $found, 'found nothing at all — the scan is broken');

        return $found;
    }
}
