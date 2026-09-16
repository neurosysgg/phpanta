<?php

declare(strict_types=1);

namespace Phpanta\Test\Unit;

use Phpanta\App;
use Phpanta\CredentialFile;
use Phpanta\Exception\ApiException;
use Phpanta\Exception\FilesystemException;
use Phpanta\Exception\InvalidValueException;
use Phpanta\Http\Api\DropAction;
use Phpanta\Model\Drop\DropConfig;
use Phpanta\Model\Drop\DropHeader;
use Phpanta\Model\Drop\DropKeys;
use Phpanta\Model\Drop\DropKind;
use Phpanta\Model\Drop\DropLifetime;
use Phpanta\Model\Drop\DropManifest;
use Phpanta\Model\Drop\DropMeta;
use Phpanta\Model\Drop\DropRefusal;
use Phpanta\Model\Drop\DropSummary;
use Phpanta\Model\Drop\DropTerms;
use Phpanta\Model\Drop\DropToken;
use Phpanta\Model\Drop\DropUnit;
use Phpanta\Model\Machine\Measure;
use Phpanta\Service\Drop\DropCipher;
use Phpanta\Service\Drop\DropStore;
use Phpanta\Service\Drop\OpenedDrop;
use Phpanta\Support\Base64Url;
use Phpanta\Support\Directory;
use Phpanta\Support\File;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A drop at rest: its token, its terms, its header and description, the cipher it is sealed with, and
 * the store that keeps it — made, opened, claimed once, swept, and refused however it was tampered with.
 *
 * The store runs in a sandbox of its own, under a key made here, with a password stretched a thousand
 * times rather than six hundred thousand: what is asserted is that the rounds are the header's to say,
 * not how slow they are.
 */
#[CoversClass(DropConfig::class)]
#[CoversClass(DropHeader::class)]
#[CoversClass(DropKeys::class)]
#[CoversClass(DropKind::class)]
#[CoversClass(DropLifetime::class)]
#[CoversClass(DropManifest::class)]
#[CoversClass(DropMeta::class)]
#[CoversClass(DropRefusal::class)]
#[CoversClass(DropSummary::class)]
#[CoversClass(DropTerms::class)]
#[CoversClass(DropToken::class)]
#[CoversClass(DropUnit::class)]
#[CoversClass(DropCipher::class)]
#[CoversClass(DropStore::class)]
#[CoversClass(OpenedDrop::class)]
final class DropTest extends TestCase
{
    /** The moment every drop here is made at. */
    private const int NOW = 1_800_000_000;

    /** The PBKDF2 rounds a password is stretched with here. */
    private const int ROUNDS = 1_000;

    /** Where the sandbox is, or `''` before one was made. */
    private string $sandbox = '';

    private DropCipher $cipher;

    private DropStore $store;

    /**
     * A sandbox, a key, and a store in the one over the other.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->sandbox = (string) realpath(Directory::temporary('phpanta-drop-')->path);
        $this->cipher  = DropCipher::fromKey(random_bytes(DropCipher::KEY_BYTES));
        $this->store   = new DropStore($this->drops(), $this->cipher, self::ROUNDS);
    }

    /**
     * @return void
     */
    protected function tearDown(): void
    {
        if ($this->sandbox !== '') {
            if (is_dir($this->drops()->path)) {
                chmod($this->drops()->path, 0o700);
            }

            UpdateFixture::removeTree($this->sandbox);
        }
    }

    // ───────────────────────── the token ─────────────────────────

    /**
     * A token is thirty-two random bytes, written as forty-three characters of base64url, and reads back
     * as itself.
     *
     * @return void
     */
    public function testATokenIsThirtyTwoRandomBytesWrittenAsBase64url(): void
    {
        $token = DropToken::mint();

        self::assertSame(43, strlen($token->text()));
        self::assertSame(DropToken::BYTES, strlen($token->bytes()));
        self::assertSame($token->bytes(), DropToken::read($token->text())?->bytes());
        self::assertNotSame($token->text(), DropToken::mint()->text());
    }

    /**
     * @param string $text
     * @return void
     */
    #[DataProvider('notATokenProvider')]
    public function testAnythingElseIsNoToken(string $text): void
    {
        self::assertNull(DropToken::read($text));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function notATokenProvider(): iterable
    {
        $text = Base64Url::encode(str_repeat(chr(1), DropToken::BYTES));

        yield 'nothing'           => [''];
        yield 'too short'         => [substr($text, 0, 42)];
        yield 'too long'          => [$text . 'AAAA'];
        yield 'padded'            => [$text . '='];
        yield 'a trailing newline' => [$text . "\n"];
        yield 'not base64url'     => ['!' . substr($text, 1)];
        yield 'plain base64'      => [base64_encode(str_repeat(chr(0xfb), DropToken::BYTES))];
    }

    // ───────────────────────── the terms ─────────────────────────

    /**
     * A lifetime is a number and a unit, seconds where it names none, and at least a minute.
     *
     * @param string $text
     * @param int    $seconds
     * @return void
     */
    #[DataProvider('lifetimeProvider')]
    public function testALifetimeIsANumberAndAUnit(string $text, int $seconds): void
    {
        self::assertSame($seconds, DropLifetime::parse($text));
    }

    /**
     * @return iterable<string, array{string, int}>
     */
    public static function lifetimeProvider(): iterable
    {
        yield 'a minute in seconds' => ['60', 60];
        yield 'seconds, said so'    => ['90s', 90];
        yield 'minutes'             => ['30m', 1_800];
        yield 'hours'               => ['12h', 43_200];
        yield 'days'                => ['7d', 604_800];
    }

    /**
     * @param string $text
     * @return void
     */
    #[DataProvider('notALifetimeProvider')]
    public function testAnythingElseIsNoLifetime(string $text): void
    {
        self::assertNull(DropLifetime::parse($text));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function notALifetimeProvider(): iterable
    {
        yield 'nothing'             => [''];
        yield 'under a minute'      => ['59'];
        yield 'zero'                => ['0'];
        yield 'a leading zero'      => ['07d'];
        yield 'a fraction'          => ['1.5h'];
        yield 'a unit alone'        => ['m'];
        yield 'weeks'               => ['1w'];
        yield 'a trailing newline'  => ["1d\n"];
        yield 'a sign'              => ['-5m'];
        yield 'nine digits'         => ['123456789'];
        yield 'a leading space'     => [' 1d'];
    }

    /**
     * How a drop's terms are said, once for both of the admin's answers that say them.
     *
     * @return void
     */
    public function testTermsAreSaidOneWay(): void
    {
        self::assertSame('gone at ' . Measure::moment(self::NOW), DropTerms::gone(self::NOW));
        self::assertNotSame(DropTerms::opens(true), DropTerms::opens(false));
        self::assertNotSame(DropTerms::needs(true), DropTerms::needs(false));
    }

    // ───────────────────────── the manifest ─────────────────────────

    /**
     * A manifest that is not a JSON object carrying `apply` is refused, in a sentence saying which.
     *
     * @param string $json
     * @param string $said
     * @return void
     */
    #[DataProvider('unreadManifestProvider')]
    public function testAManifestThatDoesNotReadIsRefused(string $json, string $said): void
    {
        $this->expectException(ApiException::class);
        $this->expectExceptionMessage($said);

        (void) DropManifest::parse($json, DropAction::Create);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function unreadManifestProvider(): iterable
    {
        yield 'not JSON'            => ['nope', 'what drop create was sent is not JSON'];
        yield 'no object'           => ['[]', 'what drop create was sent is no JSON object'];
        yield 'apply that is no bool' => ['{"apply": 1}', 'drop create needs apply, true or false'];
    }

    /**
     * Making a drop reads its terms out of the manifest — a lifetime trimmed and counted — and taking
     * one away reads `apply` and nothing else.
     *
     * @return void
     */
    public function testOnlyMakingOneReadsMoreThanApply(): void
    {
        $revoke = DropManifest::parse('{"apply": false, "text": 5}', DropAction::Revoke);

        self::assertFalse($revoke->apply);
        self::assertSame('', $revoke->text);

        $made = DropManifest::parse(
            '{"apply": true, "text": "t", "filename": "f.txt", "lifetime": " 2h ", "once": true, "password": "p"}',
            DropAction::Create,
        );

        self::assertTrue($made->apply);
        self::assertSame('t', $made->text);
        self::assertSame('f.txt', $made->filename);
        self::assertSame(7_200, $made->lifetime);
        self::assertTrue($made->once);
        self::assertSame('p', $made->password);
        self::assertNull(DropManifest::parse('{"apply": true}', DropAction::Create)->lifetime);
    }

    // ───────────────────────── the switch ─────────────────────────

    /**
     * `data/drop.json` switches the service on with what it says, or its defaults — and is off where it
     * says anything that does not read.
     *
     * @return void
     */
    public function testTheSwitchFileIsOffUnlessItReads(): void
    {
        $defaults = DropConfig::parse('{}');

        self::assertSame(DropConfig::MAX_BYTES, $defaults?->maxBytes);
        self::assertSame(DropConfig::MAX_LIFETIME, $defaults?->maxLifetime);
        self::assertSame(DropConfig::LIFETIME, $defaults?->defaultLifetime());

        $short = DropConfig::parse('{"maxBytes": 10, "maxLifetime": 3600}');

        self::assertSame(10, $short?->maxBytes);
        self::assertSame(3_600, $short?->defaultLifetime(), 'never more than the most');

        $off = [
            '',
            'nope',
            '[]',
            '"{}"',
            '{"maxBytes": "8"}',
            '{"maxBytes": 0}',
            '{"maxBytes": 1.5}',
            '{"maxLifetime": 59}',
            '{"a": {"b": 1}}',
        ];

        foreach ($off as $json) {
            self::assertNull(DropConfig::parse($json), $json);
        }

        $file = new File($this->sandbox . '/drop.json');

        self::assertNull(DropConfig::current($file));
        self::assertTrue($file->write('{}'));
        self::assertNotNull(DropConfig::current($file));
    }

    /**
     * The deployment's store is there only where both its files are: the switch, and the key.
     *
     * @return void
     */
    public function testTheDeploymentsStoreIsThereOnlyWithBothFiles(): void
    {
        $data   = App::current()->data();
        $made   = !$data->exists() && $data->create();
        $config = App::current()->dataFile(CredentialFile::Drop);
        $key    = App::current()->dataFile(CredentialFile::DropKey);

        try {
            self::assertNull(DropStore::current());
            self::assertTrue($config->write('{}'));
            self::assertNull(DropStore::current(), 'a switch with no key opens nothing');
            self::assertTrue($key->write(base64_encode(random_bytes(DropCipher::KEY_BYTES)) . "\n"));
            self::assertNotNull(DropStore::current());
            self::assertTrue($config->delete());
            self::assertNull(DropStore::current(), 'a key with no switch is off');
        } finally {
            (void) $config->delete();
            (void) $key->delete();

            if ($made) {
                rmdir($data->path);
            }
        }
    }

    // ───────────────────────── the header and the description ─────────────────────────

    /**
     * A header is forty-eight bytes, reads back as itself, and says when it is gone.
     *
     * @return void
     */
    public function testAHeaderReadsBackWhatItWrote(): void
    {
        foreach ([self::header(), self::header(once: true, locked: true)] as $header) {
            self::assertSame(DropHeader::LENGTH, strlen($header->bytes()));
            self::assertEquals($header, DropHeader::read($header->bytes()));
        }

        self::assertFalse(self::header()->isExpired(self::NOW + 59));
        self::assertTrue(self::header()->isExpired(self::NOW + 60));
    }

    /**
     * @param string $bytes
     * @return void
     */
    #[DataProvider('mangledHeaderProvider')]
    public function testAHeaderThisDidNotWriteReadsAsNone(string $bytes): void
    {
        self::assertNull(DropHeader::read($bytes));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function mangledHeaderProvider(): iterable
    {
        $bytes = self::header()->bytes();

        yield 'another magic'           => [substr_replace($bytes, 'XDRP', 0, 4)];
        yield 'another version'         => [substr_replace($bytes, chr(2), 4, 1)];
        yield 'a flag not known'        => [substr_replace($bytes, chr(0b100), 5, 1)];
        yield 'a spare not zero'        => [substr_replace($bytes, chr(1), 6, 1)];
        yield 'a byte short'            => [substr($bytes, 0, -1)];
        yield 'a byte long'             => [$bytes . chr(0)];
        yield 'gone before it was made' => [self::header(expires: self::NOW - 1)->bytes()];
        yield 'locked, with no rounds'  => [self::header(locked: true, iterations: 0)->bytes()];
        yield 'open, with rounds'       => [self::header(iterations: 5)->bytes()];
        yield 'rounds past the most'    => [
            self::header(locked: true, iterations: DropHeader::MAX_ITERATIONS + 1)->bytes(),
        ];
        yield 'a description too short' => [self::header(metaLength: DropHeader::TAG)->bytes()];
        yield 'a description too long'  => [self::header(metaLength: 1_025)->bytes()];
    }

    /**
     * A description reads back as itself, counts its chunks, and holds a name to one segment.
     *
     * @return void
     */
    public function testADescriptionReadsBackAndCountsItsChunks(): void
    {
        foreach ([new DropMeta(DropKind::Text, null, 5), new DropMeta(DropKind::File, 'ü b.pdf', 9)] as $meta) {
            self::assertEquals($meta, DropMeta::read($meta->json()));
        }

        $chunk = DropHeader::CHUNK;

        self::assertSame(1, new DropMeta(DropKind::Text, null, 0)->chunks(), 'nothing is one chunk, marked last');
        self::assertSame(0, new DropMeta(DropKind::Text, null, 0)->chunkLength(0));
        self::assertSame(1, new DropMeta(DropKind::Text, null, $chunk)->chunks());
        self::assertSame(2, new DropMeta(DropKind::Text, null, $chunk + 1)->chunks());
        self::assertSame($chunk, new DropMeta(DropKind::Text, null, $chunk + 1)->chunkLength(0));
        self::assertSame(1, new DropMeta(DropKind::Text, null, $chunk + 1)->chunkLength(1));

        $unread = [
            'nope',
            '[]',
            '{"kind": 1}',
            '{"kind": "blob", "name": null, "size": 1}',
            '{"kind": "text", "name": "x", "size": 1}',
            '{"kind": "file", "name": null, "size": 1}',
            '{"kind": "file", "name": "a/b", "size": 1}',
            '{"kind": "text", "name": null, "size": -1}',
            '{"kind": "text", "name": null, "size": "1"}',
            '{"kind": "text", "name": null, "size": {"a": 1}}',
        ];

        foreach ($unread as $json) {
            self::assertNull(DropMeta::read($json), $json);
        }
    }

    /**
     * @param string $name
     * @param bool   $is
     * @return void
     */
    #[DataProvider('nameProvider')]
    public function testANameIsOneSegment(string $name, bool $is): void
    {
        self::assertSame($is, DropMeta::isName($name));
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function nameProvider(): iterable
    {
        yield 'a name'            => ['notes.txt', true];
        yield 'UTF-8 and a space' => ['Übergabe 1.pdf', true];
        yield 'a dotfile'         => ['.env', true];
        yield 'the longest'       => [str_repeat('a', 255), true];
        yield 'nothing'           => ['', false];
        yield 'a dot'             => ['.', false];
        yield 'two dots'          => ['..', false];
        yield 'a slash'           => ['a/b', false];
        yield 'a backslash'       => ['a\\b', false];
        yield 'a NUL'             => ["a\0b", false];
        yield 'a newline'         => ["a\nb", false];
        yield 'too long'          => [str_repeat('a', 256), false];
        yield 'not UTF-8'         => ["\xff.bin", false];
    }

    // ───────────────────────── the cipher ─────────────────────────

    /**
     * The cipher is the deployment's key and nothing else: thirty-two bytes, from a file that holds
     * them as base64 — and the admin says how to mint one.
     *
     * @return void
     */
    public function testTheCipherIsTheDeploymentsKey(): void
    {
        $file = new File($this->sandbox . '/drop.key');

        self::assertNull(DropCipher::current($file), 'no file');

        foreach (['nope', base64_encode(random_bytes(31)), ''] as $held) {
            self::assertTrue($file->write($held));
            self::assertNull(DropCipher::current($file), $held);
        }

        self::assertTrue($file->write("  " . base64_encode(random_bytes(DropCipher::KEY_BYTES)) . "\n"));
        self::assertNotNull(DropCipher::current($file));
        self::assertStringContainsString($file->path, DropCipher::minting($file));
        self::assertStringContainsString('random_bytes(32)', DropCipher::minting($file));

        $this->expectException(InvalidValueException::class);

        (void) DropCipher::fromKey('short');
    }

    /**
     * A token names one drop, by a keyed hash that says nothing of it — another deployment names it
     * otherwise.
     *
     * @return void
     */
    public function testADropsNameIsAKeyedHashOfItsToken(): void
    {
        $token = DropToken::mint();
        $id    = $this->cipher->idOf($token);

        self::assertTrue(DropStore::isId($id));
        self::assertSame($id, $this->cipher->idOf(DropToken::read($token->text()) ?? DropToken::mint()));
        self::assertNotSame($id, DropCipher::fromKey(random_bytes(DropCipher::KEY_BYTES))->idOf($token));
        self::assertNotSame($id, $this->cipher->idOf(DropToken::mint()));
        self::assertStringNotContainsString($id, bin2hex($token->bytes()));
    }

    /**
     * What is sealed opens under the same key, as the same index, with the same data — and under
     * nothing else.
     *
     * @return void
     */
    public function testASealOpensAsItWasSealedAndNoOtherWay(): void
    {
        $key    = random_bytes(DropCipher::KEY_BYTES);
        $sealed = $this->cipher->seal('the bytes', $key, 3, 'bound');

        self::assertSame(strlen('the bytes') + DropHeader::TAG, strlen($sealed));
        self::assertSame('the bytes', $this->cipher->open($sealed, $key, 3, 'bound'));
        self::assertSame('', $this->cipher->open($this->cipher->seal('', $key, 0, ''), $key, 0, ''));
        self::assertNull($this->cipher->open($sealed, $key, 4, 'bound'), 'another index');
        self::assertNull($this->cipher->open($sealed, $key, 3, 'other'), 'other data');
        self::assertNull($this->cipher->open($sealed, random_bytes(DropCipher::KEY_BYTES), 3, 'bound'), 'another key');
        self::assertNull($this->cipher->open(substr($sealed, 1), $key, 3, 'bound'), 'cut short');
        self::assertNull($this->cipher->open('short', $key, 3, 'bound'), 'shorter than a tag');
    }

    /**
     * A drop's keys are drawn from its token and, where its header says it needs one, its password —
     * which is ignored where it does not.
     *
     * @return void
     */
    public function testKeysAreDrawnFromTheTokenAndThePasswordWhereOneIsNeeded(): void
    {
        $token  = DropToken::mint();
        $open   = self::header();
        $locked = self::header(locked: true);

        self::assertEquals($this->cipher->keys($token, 'a', $open), $this->cipher->keys($token, 'b', $open));
        self::assertNotEquals($this->cipher->keys($token, 'a', $locked), $this->cipher->keys($token, 'b', $locked));
        self::assertNotEquals(
            $this->cipher->keys($token, '', $open),
            $this->cipher->keys(DropToken::mint(), '', $open),
        );

        $keys = $this->cipher->keys($token, '', $open);

        self::assertSame(DropCipher::KEY_BYTES, strlen($keys->meta));
        self::assertSame(DropCipher::KEY_BYTES, strlen($keys->data));
        self::assertNotSame($keys->meta, $keys->data);
    }

    // ───────────────────────── the store ─────────────────────────

    /**
     * Text opens with its link, as often as it is asked, and a password given where none is needed is
     * no refusal.
     *
     * @return void
     */
    public function testTextOpensWithItsLinkAsOftenAsItIsAsked(): void
    {
        $token  = $this->store->create('hello, drop', null, false, '', 3_600, self::NOW);
        $opened = $this->store->open($token, '', self::NOW);

        self::assertInstanceOf(OpenedDrop::class, $opened);
        self::assertSame(DropKind::Text, $opened->meta->kind);
        self::assertNull($opened->meta->name);
        self::assertSame('hello, drop', $opened->text());
        self::assertSame('hello, drop', self::read($this->store->open($token, 'not needed', self::NOW)));
    }

    /**
     * A file opens whole whatever its size, a chunk at a time — and its file on disk is exactly as long
     * as its header says.
     *
     * @param int $size
     * @return void
     */
    #[DataProvider('sizeProvider')]
    public function testAFileOpensWholeWhateverItsSize(int $size): void
    {
        $payload = $size === 0 ? '' : random_bytes($size);
        $token   = $this->store->create($payload, 'blob.bin', false, '', 3_600, self::NOW);
        $opened  = $this->store->open($token, '', self::NOW);

        self::assertInstanceOf(OpenedDrop::class, $opened);
        self::assertSame(DropKind::File, $opened->meta->kind);
        self::assertSame('blob.bin', $opened->meta->name);
        self::assertSame($size, $opened->meta->size);
        self::assertSame($opened->header->fileSize($opened->meta), $this->only()->size());
        self::assertSame($payload, self::read($opened));
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function sizeProvider(): iterable
    {
        yield 'nothing'                    => [0];
        yield 'a byte'                     => [1];
        yield 'a chunk less a byte'        => [DropHeader::CHUNK - 1];
        yield 'a chunk'                    => [DropHeader::CHUNK];
        yield 'a chunk and a byte'         => [DropHeader::CHUNK + 1];
        yield 'three chunks and a little'  => [3 * DropHeader::CHUNK + 5];
    }

    /**
     * A drop to be read once is claimed by the first request alone: the next finds nothing, and
     * nothing is left on disk — while the first still reads it whole.
     *
     * @return void
     */
    public function testADropReadOnceIsClaimedByTheFirstReadAlone(): void
    {
        $token  = $this->store->create('once only', null, true, '', 3_600, self::NOW);
        $first  = $this->store->open($token, '', self::NOW);
        $second = $this->store->open($token, '', self::NOW);

        self::assertInstanceOf(OpenedDrop::class, $first);
        self::assertSame(DropRefusal::Absent, $second);
        self::assertSame([], self::names($this->drops()));
        self::assertSame('once only', $first->text());
    }

    /**
     * A password is asked for where none was given, and a wrong one is refused — and neither burns a
     * drop meant to be read once. The rounds it was stretched with are the header's.
     *
     * @return void
     */
    public function testAPasswordIsAskedForAndAWrongOneBurnsNothing(): void
    {
        $token = $this->store->create('behind a password', 'locked.txt', true, 'hunter2', 3_600, self::NOW);

        self::assertSame(self::ROUNDS, $this->store->summaries(self::NOW)->first()?->header->iterations);
        self::assertSame(DropRefusal::Locked, $this->store->open($token, '', self::NOW));
        self::assertSame(DropRefusal::Locked, $this->store->open($token, 'hunter3', self::NOW));
        self::assertSame('behind a password', self::read($this->store->open($token, 'hunter2', self::NOW)));
        self::assertSame(DropRefusal::Absent, $this->store->open($token, 'hunter2', self::NOW));
    }

    /**
     * What has expired opens nothing and is swept on the way, and so is a file here that is no drop's
     * and any claimed name left behind — but nothing the store does not name.
     *
     * @return void
     */
    public function testWhatHasExpiredOpensNothingAndIsSwept(): void
    {
        $token = $this->store->create('soon gone', null, false, '', 60, self::NOW);

        self::assertSame('soon gone', self::read($this->store->open($token, '', self::NOW + 59)));
        self::assertSame(DropRefusal::Absent, $this->store->open($token, '', self::NOW + 60));
        self::assertSame([], self::names($this->drops()));

        (void) $this->store->create('kept', null, false, '', 3_600, self::NOW);
        self::assertTrue($this->drops()->file('junk.drop')->write('not a drop'));
        self::assertTrue($this->drops()->file('someone.claimed')->write('left behind'));
        self::assertTrue($this->drops()->file('notes.txt')->write('not the store\'s'));

        self::assertSame(2, $this->store->sweep(self::NOW));
        self::assertCount(2, self::names($this->drops()));
        self::assertSame(1, $this->store->sweep(self::NOW + 3_600));
        self::assertSame(['notes.txt'], self::names($this->drops()));
    }

    /**
     * A token of no drop opens nothing, and the same as one expired: every kind of nothing is one.
     *
     * @return void
     */
    public function testATokenOfNoDropOpensNothing(): void
    {
        (void) $this->store->create('something', null, false, '', 3_600, self::NOW);

        self::assertSame(DropRefusal::Absent, $this->store->open(DropToken::mint(), '', self::NOW));
        self::assertSame(DropRefusal::Absent, new DropStore(new Directory($this->sandbox . '/none'), $this->cipher)
            ->open(DropToken::mint(), '', self::NOW), 'a store never made');
    }

    /**
     * A byte changed anywhere in a drop's file — its header, its description, a chunk, a tag — and it
     * does not open, or ends before it is whole.
     *
     * @param string $where
     * @param bool   $opens Whether its description still opens, so it fails a chunk at a time.
     * @return void
     */
    #[DataProvider('tamperedProvider')]
    public function testAByteChangedAnywhereOpensNothing(string $where, bool $opens): void
    {
        $token = $this->store->create(str_repeat('x', DropHeader::CHUNK + 10), null, false, '', 3_600, self::NOW);
        $file  = $this->only();
        $bytes = (string) $file->read();
        $meta  = DropHeader::read(substr($bytes, 0, DropHeader::LENGTH))?->metaLength ?? 0;
        $at    = match ($where) {
            'header'      => 20,
            'description' => DropHeader::LENGTH + 1,
            'first chunk' => DropHeader::LENGTH + $meta + 5,
            default       => strlen($bytes) - 1,
        };

        $bytes[$at] = chr(ord($bytes[$at]) ^ 0x01);
        self::assertTrue($file->write($bytes));

        $opened = $this->store->open($token, '', self::NOW);

        if (!$opens) {
            self::assertSame(DropRefusal::Absent, $opened);

            return;
        }

        self::assertInstanceOf(OpenedDrop::class, $opened);
        self::assertNull($opened->text());
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function tamperedProvider(): iterable
    {
        yield 'its expiry'        => ['header', false];
        yield 'its description'   => ['description', false];
        yield 'its first chunk'   => ['first chunk', true];
        yield 'its last tag'      => ['last tag', true];
    }

    /**
     * A file cut short, or grown, is not the drop its header describes, and opens nothing.
     *
     * @return void
     */
    public function testAFileCutShortOrGrownOpensNothing(): void
    {
        $token = $this->store->create('whole', null, false, '', 3_600, self::NOW);
        $file  = $this->only();
        $bytes = (string) $file->read();

        self::assertTrue($file->write(substr($bytes, 0, -1)));
        self::assertSame(DropRefusal::Absent, $this->store->open($token, '', self::NOW));

        self::assertTrue($file->write($bytes . 'x'));
        self::assertSame(DropRefusal::Absent, $this->store->open($token, '', self::NOW));

        self::assertTrue($file->write(substr($bytes, 0, 10)));
        self::assertSame(DropRefusal::Absent, $this->store->open($token, '', self::NOW), 'no whole header');
    }

    /**
     * Once its chunks have been read, a drop's file is closed, and reading them again yields nothing.
     *
     * @return void
     */
    public function testChunksReadOnceCloseTheirFile(): void
    {
        $opened = $this->store->open($this->store->create('twice?', null, false, '', 3_600, self::NOW), '', self::NOW);

        self::assertInstanceOf(OpenedDrop::class, $opened);
        self::assertSame('twice?', $opened->text());
        self::assertNull($opened->text());
    }

    /**
     * The listing says what can be said of each drop without its link, oldest first — and a drop taken
     * away is gone.
     *
     * @return void
     */
    public function testTheListingSaysWhatCanBeSaidWithoutALink(): void
    {
        $plain  = $this->store->create('a', null, false, '', 3_600, self::NOW);
        $locked = $this->store->create('b', 'b.txt', true, 'pw', 7_200, self::NOW - 10);
        $kept   = $this->store->summaries(self::NOW)->toValues();

        self::assertCount(2, $kept);
        self::assertSame($this->cipher->idOf($locked), $kept[0]->id);
        self::assertTrue($kept[0]->header->once);
        self::assertTrue($kept[0]->header->locked);
        self::assertSame(self::NOW + 7_190, $kept[0]->header->expires);
        self::assertSame($this->cipher->idOf($plain), $kept[1]->id);
        self::assertFalse($kept[1]->header->once);
        self::assertGreaterThan(DropHeader::LENGTH, $kept[1]->bytes);

        self::assertTrue($this->store->has($kept[0]->id));
        self::assertFalse($this->store->has('nope'));
        self::assertFalse($this->store->has(str_repeat('0', 32)));
        self::assertTrue($this->store->revoke($kept[0]->id));
        self::assertFalse($this->store->has($kept[0]->id));
        self::assertTrue($this->store->revoke($kept[0]->id), 'none there is a success');
        self::assertTrue($this->store->revoke('../escape'), 'no drop is named so');
        self::assertSame(DropRefusal::Absent, $this->store->open($locked, 'pw', self::NOW));
    }

    /**
     * The store and each drop in it are their owner's alone.
     *
     * @return void
     */
    public function testTheStoreIsItsOwnersAlone(): void
    {
        (void) $this->store->create('mine', null, false, '', 3_600, self::NOW);

        self::assertSame(0o700, fileperms($this->drops()->path) & 0o777);
        self::assertSame(0o600, fileperms($this->only()->path) & 0o777);
    }

    /**
     * A store that cannot be made, or written into, says so rather than answering a token that opens
     * nothing.
     *
     * @return void
     */
    public function testAStoreThatCannotBeWrittenSaysSo(): void
    {
        self::assertTrue(new File($this->sandbox . '/blocker')->write('a file'));

        try {
            (void) new DropStore(new Directory($this->sandbox . '/blocker/drops'), $this->cipher)
                ->create('x', null, false, '', 3_600, self::NOW);
            self::fail('a store under a file was made');
        } catch (FilesystemException $e) {
            self::assertStringContainsString('could not be made', $e->getMessage());
        }

        self::assertTrue($this->drops()->create(0o700));
        chmod($this->drops()->path, 0o500);

        $this->expectException(FilesystemException::class);
        $this->expectExceptionMessage('could not be written');

        (void) $this->store->create('x', null, false, '', 3_600, self::NOW);
    }

    /**
     * A store that cannot be written reveals nothing it could not claim or sweep: a drop meant to be
     * read once stays unread, and one expired stays shut, though neither can be taken away — and once
     * the store can be written again, the first opens.
     *
     * @return void
     */
    public function testAStoreThatCannotBeWrittenRevealsNothingItCouldNotClaimOrSweep(): void
    {
        $once    = $this->store->create('read me once', null, true, '', 3_600, self::NOW);
        $expired = $this->store->create('long gone', null, false, '', 60, self::NOW);

        chmod($this->drops()->path, 0o500);

        self::assertSame(DropRefusal::Absent, $this->store->open($once, '', self::NOW + 61), 'not claimed');
        self::assertSame(DropRefusal::Absent, $this->store->open($expired, '', self::NOW + 61), 'not swept');
        self::assertCount(2, self::names($this->drops()));

        chmod($this->drops()->path, 0o700);

        self::assertSame('read me once', self::read($this->store->open($once, '', self::NOW + 61)));
        self::assertSame([], self::names($this->drops()));
    }

    // ───────────────────────── helpers ─────────────────────────

    /**
     * The sandbox's store directory.
     *
     * @return Directory
     */
    private function drops(): Directory
    {
        return new Directory($this->sandbox . '/drops');
    }

    /**
     * The one drop's file in the store.
     *
     * @return File
     */
    private function only(): File
    {
        $files = $this->drops()->files('*.drop')->toValues();

        self::assertCount(1, $files);

        return $files[0];
    }

    /**
     * What $opened holds, whole — or a failure where it did not open.
     *
     * @param OpenedDrop|DropRefusal $opened
     * @return string
     */
    private static function read(OpenedDrop|DropRefusal $opened): string
    {
        self::assertInstanceOf(OpenedDrop::class, $opened);

        $bytes = '';

        foreach ($opened->chunks() as $chunk) {
            $bytes .= $chunk;
        }

        return $bytes;
    }

    /**
     * The names in $directory, sorted, `.` and `..` left out.
     *
     * @param Directory $directory
     * @return list<string>
     */
    private static function names(Directory $directory): array
    {
        return array_values(array_diff(scandir($directory->path) ?: [], ['.', '..']));
    }

    /**
     * A header, as a test needs one.
     *
     * @param bool     $once
     * @param bool     $locked
     * @param int      $expires
     * @param int|null $iterations Null for what $locked implies.
     * @param int      $metaLength
     * @return DropHeader
     */
    private static function header(
        bool $once = false,
        bool $locked = false,
        int $expires = self::NOW + 60,
        ?int $iterations = null,
        int $metaLength = 64,
    ): DropHeader {
        return new DropHeader(
            $once,
            $locked,
            self::NOW,
            $expires,
            $iterations ?? ($locked ? self::ROUNDS : 0),
            str_repeat(chr(7), DropHeader::SALT_BYTES),
            $metaLength,
        );
    }
}
