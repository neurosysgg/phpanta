<?php

declare(strict_types=1);

namespace Phpanta\Test\Unit;

use Phpanta\Support\Directory;
use Phpanta\Support\File;
use Phpanta\Tool\Api\ApiTarget;
use Phpanta\Tool\Cli\UsageException;
use PHPUnit\Framework\TestCase;

/**
 * Which deployment a signed call goes to, and which key signs for it.
 *
 * The rule under test is the one that keeps two deployments from sharing credentials: the default
 * origin signs with the default key, every other origin with a key of its own, and the default key is
 * refused for any other origin however it is named.
 *
 * No `#[CoversClass]`, like every other test of the tooling.
 */
final class ApiTargetTest extends TestCase
{
    private const string ORIGIN = 'https://example.test';
    private const string KEY_PATH = '.config/phpanta/update.key';

    private string $home = '';

    /**
     * A home directory holding the default key.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->home = sys_get_temp_dir() . '/phpanta-target-' . bin2hex(random_bytes(6));
        self::assertTrue(new Directory($this->home . '/.config/phpanta')->create());
        self::assertTrue($this->defaultKey()->write("the default key\n", 0o600));
    }

    /**
     * @return void
     */
    protected function tearDown(): void
    {
        if ($this->home !== '') {
            UpdateFixture::removeTree($this->home);
        }
    }

    /**
     * The default origin signs with the default key.
     *
     * @return void
     */
    public function testTheDefaultOriginSignsWithTheDefaultKey(): void
    {
        $target = $this->resolve(null, null);

        self::assertSame(self::ORIGIN, $target->origin->render());
        self::assertSame($this->defaultKey()->path, $target->key->path);
    }

    /**
     * Another spelling of the default origin is the default origin.
     *
     * @return void
     */
    public function testAnotherSpellingOfTheDefaultOriginIsTheDefaultOrigin(): void
    {
        self::assertSame($this->defaultKey()->path, $this->resolve('https://EXAMPLE.test/', null)->key->path);
    }

    /**
     * Any other origin signs with the sibling key named for its host, and its port where it has one.
     *
     * @return void
     */
    public function testAnotherOriginSignsWithTheKeyNamedForIt(): void
    {
        $directory = $this->defaultKey()->directory()->path;

        self::assertSame(
            $directory . '/update-staging.example.test.key',
            $this->resolve('https://staging.example.test', null)->key->path,
        );
        self::assertSame(
            $directory . '/update-localhost-8443.key',
            $this->resolve('https://localhost:8443', null)->key->path,
        );
    }

    /**
     * A key of its own, named with `--key`, is taken.
     *
     * @return void
     */
    public function testAnotherOriginTakesAKeyOfItsOwn(): void
    {
        $own = new File($this->home . '/own.key');
        self::assertTrue($own->write("another key\n", 0o600));

        self::assertSame($own->path, $this->resolve('https://staging.example.test', $own->path)->key->path);
    }

    /**
     * The default key is refused for another origin when named with `--key`, and the refusal says how
     * to mint the right one.
     *
     * @return void
     */
    public function testTheDefaultKeyIsRefusedForAnotherOrigin(): void
    {
        try {
            $this->resolve('https://staging.example.test', $this->defaultKey()->path);
            self::fail('the default key was taken for another deployment');
        } catch (UsageException $exception) {
            self::assertStringContainsString('needs a key of its own', $exception->getMessage());
            self::assertStringContainsString('update-staging.example.test.key', $exception->getMessage());
            self::assertStringContainsString('openssl genpkey', $exception->getMessage());
        }
    }

    /**
     * A copy of the default key under another name is the default key.
     *
     * @return void
     */
    public function testACopyOfTheDefaultKeyIsRefusedForAnotherOrigin(): void
    {
        $copy = new File($this->home . '/copy.key');
        self::assertTrue($copy->write((string) $this->defaultKey()->read(), 0o600));

        $this->expectException(UsageException::class);
        $this->expectExceptionMessage('needs a key of its own');

        $this->resolve('https://staging.example.test', $copy->path);
    }

    /**
     * An origin with a path, a query or a user part is refused as a usage error, not an uncaught one.
     *
     * @param string $url
     * @return void
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('notAnOriginProvider')]
    public function testSomethingThatIsNotAnOriginIsRefused(string $url): void
    {
        $this->expectException(UsageException::class);
        $this->expectExceptionMessage('An origin is https://');

        $this->resolve($url, null);
    }

    /**
     * @return iterable
     */
    public static function notAnOriginProvider(): iterable
    {
        yield 'a path'      => ['https://example.test/sub'];
        yield 'a query'     => ['https://example.test/?x=1'];
        yield 'a fragment'  => ['https://example.test/#x'];
        yield 'a user part' => ['https://user@example.test'];
        yield 'plain http'  => ['http://example.test'];
        yield 'no host'     => ['https://'];
    }

    /**
     * Without `HOME` and without `--key`, there is nothing to sign with, and it says so.
     *
     * @return void
     */
    public function testWithoutHomeAKeyMustBeNamed(): void
    {
        $this->expectException(UsageException::class);
        $this->expectExceptionMessage('HOME is not set');

        ApiTarget::resolve('https://staging.example.test', null, self::ORIGIN, self::KEY_PATH, null);
    }

    /**
     * Without `HOME`, a named key is taken — there is no default key to compare it with.
     *
     * @return void
     */
    public function testWithoutHomeANamedKeyIsTaken(): void
    {
        $target = ApiTarget::resolve(null, $this->defaultKey()->path, self::ORIGIN, self::KEY_PATH, null);

        self::assertSame($this->defaultKey()->path, $target->key->path);
    }

    /**
     * @param string|null $url
     * @param string|null $key
     * @return ApiTarget
     */
    private function resolve(?string $url, ?string $key): ApiTarget
    {
        return ApiTarget::resolve($url, $key, self::ORIGIN, self::KEY_PATH, $this->home);
    }

    /**
     * @return File
     */
    private function defaultKey(): File
    {
        return new File($this->home . '/' . self::KEY_PATH);
    }
}
