<?php

declare(strict_types=1);

namespace Phpanta\Test\Unit;

use Phpanta\Exception\TranslationException;
use Phpanta\Http\AcceptedLanguages;
use Phpanta\Text\Language;
use Phpanta\Text\Languages;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The subset of the framework's languages one app offers. What matters is the narrowing — a
 * language the framework knows but the app does not write is answered as if it were nothing — and
 * that the default is stated once, as the first argument, and wins every tie.
 */
#[CoversClass(Languages::class)]
#[CoversClass(AcceptedLanguages::class)]
final class LanguagesTest extends TestCase
{
    /**
     * @return void
     */
    public function testTheDefaultIsTheFirstLanguageAndLeadsTheOffer(): void
    {
        $languages = new Languages(Language::German, Language::English);

        self::assertSame(Language::German, $languages->default());
        self::assertSame([Language::German, Language::English], $languages->offered()->toValues());
    }

    /**
     * A site written in one language answers a tag for another as it answers a tag for nothing.
     *
     * @return void
     */
    public function testALanguageTheAppDoesNotOfferIsNotRecognised(): void
    {
        $english = new Languages(Language::English);

        self::assertSame(Language::English, $english->tryFrom('en'));
        self::assertNull($english->tryFrom('de'));
        self::assertNull($english->tryFrom('xx'));
        self::assertNull($english->tryFrom(''));
    }

    /**
     * @return void
     */
    public function testTheBrowsersPreferenceIsReadAmongTheOfferedLanguagesOnly(): void
    {
        $both    = new Languages(Language::English, Language::German);
        $english = new Languages(Language::English);
        $german  = AcceptedLanguages::from('de-DE,de;q=0.9');

        self::assertSame(Language::German, $both->preferredBy($german));
        self::assertSame(
            Language::English,
            $english->preferredBy($german),
            'an unoffered preference falls back to the default',
        );
        self::assertSame(Language::English, $both->preferredBy(AcceptedLanguages::from('')));
    }

    /**
     * @return void
     */
    public function testALanguageOfferedTwiceIsRefused(): void
    {
        $this->expectException(TranslationException::class);
        $this->expectExceptionMessage("'en' is offered twice");

        (void) new Languages(Language::English, Language::German, Language::English);
    }
}
