<?php

declare(strict_types=1);

namespace Phpanta\Test\Unit;

use Phpanta\Support\File;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * The README quotes the example rather than describing it, and this holds each quote to its file.
 *
 * Code in a document is the first thing to go stale and the last thing anybody runs. So every file
 * the README shows is marked with the path it comes from, and every marked block has to be that
 * file, byte for byte: the example's own suite proves the files run, and this proves the page shows
 * those files.
 */
#[CoversNothing]
final class ReadmeTest extends TestCase
{
    /** A quoted file: a comment naming it, then the fenced block holding it. */
    private const string QUOTE = '/^<!-- (examples\/\S+) -->\n```php\n(.*?)^```$/ms';

    /**
     * Every file the README quotes is the file it names.
     *
     * @return void
     */
    public function testEveryFileTheReadmeQuotesIsThatFile(): void
    {
        $readme = (string) new File(PHPANTA_ROOT . '/README.md')->read();

        preg_match_all(self::QUOTE, $readme, $quotes, PREG_SET_ORDER);

        self::assertNotEmpty($quotes, 'The README quotes no file of the example.');

        foreach ($quotes as [, $path, $block]) {
            self::assertSame(
                new File(PHPANTA_ROOT . '/' . $path)->read(),
                $block,
                $path . ' is not what the README shows. Change both, or neither.',
            );
        }
    }
}
