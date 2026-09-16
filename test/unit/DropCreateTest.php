<?php

declare(strict_types=1);

namespace Phpanta\Test\Unit;

use ArrayObject;
use Phpanta\Service\ApiGate;
use Phpanta\Support\Directory;
use Phpanta\Support\File;
use Phpanta\Tool\Cli\ExitCode;
use Phpanta\Tool\Cli\Output;
use Phpanta\Tool\Cli\Runner;
use Phpanta\Tool\Command\DropCreate;
use Phpanta\Tool\Http\OutboundHeader;
use Phpanta\Tool\Http\Request;
use Phpanta\Tool\Http\Response;
use Phpanta\Tool\Http\Transport;
use PHPUnit\Framework\TestCase;

/**
 * `php tools/drop.php`: a text or a file sent as `drop v1 create`, signed with its bytes as the body,
 * and the link the admin answered printed whole — or, where there is nothing to send, too much, or
 * something that cannot be read, nothing sent at all.
 *
 * No `#[CoversClass]`, like every other test of `tools/`: `tools/` is not coverage source.
 */
final class DropCreateTest extends TestCase
{
    /** The token the admin answers with here. */
    private const string TOKEN = 'dGhpcy1pcy1ub3QtYS1yZWFsLXRva2VuLWp1c3QtYS1m';

    private string $sandbox = '';

    private File $keyFile;

    /**
     * A real private key on disk, because the command signs before it sends.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->sandbox = sys_get_temp_dir() . '/phpanta-drop-create-' . bin2hex(random_bytes(6));
        new Directory($this->sandbox)->create();

        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        self::assertNotFalse($key);
        self::assertTrue(openssl_pkey_export($key, $pem));

        $this->keyFile = new File($this->sandbox . '/update.key');
        self::assertTrue($this->keyFile->write((string) $pem, 0o600));
    }

    /**
     * @return void
     */
    protected function tearDown(): void
    {
        if ($this->sandbox !== '') {
            UpdateFixture::removeTree($this->sandbox);
        }
    }

    /**
     * Text given on the command line is the body, signed for `drop v1 create` with how it is to be
     * kept — and the link the admin answered is printed whole, on the origin it was sent to.
     *
     * @return void
     */
    public function testTextIsSentAsTheBodyAndTheLinkPrintedWhole(): void
    {
        /** @var ArrayObject<int, Request> $sent */
        $sent = new ArrayObject();

        $arguments            = ['--text', 'the wifi password', '--once', '--lifetime', '30m'];
        [$code, $out, $error] = $this->drop(201, self::made(), $arguments, $sent);

        self::assertSame(ExitCode::Success, $code, $error);
        self::assertCount(1, $sent);
        self::assertSame('https://example.test/admin/drop/v1/create', $sent[0]->url->render());
        self::assertSame('the wifi password', $sent[0]->body());

        $manifest = self::manifestOf($sent[0]);

        self::assertTrue($manifest['apply']);
        self::assertTrue($manifest['once']);
        self::assertSame('30m', $manifest['lifetime']);
        self::assertArrayNotHasKey('filename', $manifest);
        self::assertArrayNotHasKey('password', $manifest);
        self::assertStringEndsWith("\nhttps://example.test/drop#" . self::TOKEN . "\n", $out);
    }

    /**
     * A file is sent under its own name, or the one `--name` gives it — and a dry run prints what the
     * admin said, with no link to print.
     *
     * @return void
     */
    public function testAFileIsSentUnderItsOwnNameOrAnother(): void
    {
        /** @var ArrayObject<int, Request> $sent */
        $sent = new ArrayObject();
        $file = new File($this->sandbox . '/notes.pdf');

        self::assertTrue($file->write("%PDF\x00bytes"));

        [$code] = $this->drop(201, self::made(), [$file->path], $sent);

        self::assertSame(ExitCode::Success, $code);
        self::assertSame("%PDF\x00bytes", $sent[0]->body());
        self::assertSame('notes.pdf', self::manifestOf($sent[0])['filename']);
        self::assertFalse(self::manifestOf($sent[0])['once']);

        $dry          = self::written(200, 'a dry run: no drop was made');
        [$code, $out] = $this->drop(200, $dry, [$file->path, '--name', 'q3.pdf', '--dry-run'], $sent);

        self::assertSame(ExitCode::Success, $code);
        self::assertSame('q3.pdf', self::manifestOf($sent[1])['filename']);
        self::assertFalse(self::manifestOf($sent[1])['apply']);
        self::assertSame("a dry run: no drop was made\n", $out);
    }

    /**
     * `-` reads standard input, which is text unless `--name` makes it a file.
     *
     * @return void
     */
    public function testStandardInputIsTextUnlessItIsNamed(): void
    {
        /** @var ArrayObject<int, Request> $sent */
        $sent  = new ArrayObject();
        $stdin = fopen('php://memory', 'r+');

        self::assertNotFalse($stdin);
        fwrite($stdin, "piped\n");
        rewind($stdin);

        [$code] = $this->drop(201, self::made(), ['-'], $sent, $stdin);

        self::assertSame(ExitCode::Success, $code);
        self::assertSame("piped\n", $sent[0]->body());
        self::assertArrayNotHasKey('filename', self::manifestOf($sent[0]));
    }

    /**
     * A password is the first line of a file, never the command line — and a file with none on its
     * first line, or no file, sends nothing.
     *
     * @return void
     */
    public function testAPasswordIsReadFromTheFirstLineOfAFile(): void
    {
        /** @var ArrayObject<int, Request> $sent */
        $sent   = new ArrayObject();
        $secret = new File($this->sandbox . '/password');

        self::assertTrue($secret->write("hunter2\r\nnot this\n"));

        [$code] = $this->drop(201, self::made(), ['--text', 'x', '--password-file', $secret->path], $sent);

        self::assertSame(ExitCode::Success, $code);
        self::assertSame('hunter2', self::manifestOf($sent[0])['password']);

        self::assertTrue($secret->write("\n"));

        [$empty, , $said] = $this->drop(201, self::made(), ['--text', 'x', '--password-file', $secret->path], $sent);
        $nowhere          = ['--text', 'x', '--password-file', $this->sandbox . '/nope'];
        [$none]           = $this->drop(201, self::made(), $nowhere, $sent);

        self::assertSame(ExitCode::Usage, $empty);
        self::assertStringContainsString('holds no password on its first line', $said);
        self::assertSame(ExitCode::Usage, $none);
        self::assertCount(1, $sent);
    }

    /**
     * Nothing named, two things named, a file that cannot be read, or more than a signed call carries:
     * each is said, and nothing is sent.
     *
     * @return void
     */
    public function testNothingIsSentWhereThereIsNothingOrTooMuchToSend(): void
    {
        /** @var ArrayObject<int, Request> $sent */
        $sent = new ArrayObject();
        $big  = new File($this->sandbox . '/big.bin');

        self::assertTrue($big->write(str_repeat('x', ApiGate::MAX_BODY + 1)));

        foreach ([[], ['a.txt', '--text', 'x'], [$this->sandbox . '/nope'], [$big->path]] as $arguments) {
            [$code, , $error] = $this->drop(201, self::made(), $arguments, $sent);

            self::assertSame(ExitCode::Usage, $code, implode(' ', $arguments));
            self::assertStringStartsWith('drop: ', $error);
        }

        self::assertCount(0, $sent);
    }

    /**
     * An answer that is not a drop kept says why: a refusal of the key or the clock, an answer that is
     * not the admin's at all, or the admin's own sentence.
     *
     * @return void
     */
    public function testAnAnswerThatIsNotAKeptDropSaysWhy(): void
    {
        $text                     = ['--text', 'too long'];
        [$refused, , $unverified] = $this->drop(401, self::written(401, 'this needs a signed request'), $text);
        [$absent, , $foreign]     = $this->drop(404, '<!DOCTYPE html>', $text);
        [$held, $out, $said]      = $this->drop(413, self::written(413, 'a drop is 8 B at most here'), $text);

        self::assertSame(ExitCode::Failure, $refused);
        self::assertStringContainsString('refused with 401', $unverified);
        self::assertSame(ExitCode::Failure, $absent);
        self::assertStringContainsString('is the drop service switched on there?', $foreign);
        self::assertSame(ExitCode::Failure, $held);
        self::assertSame("a drop is 8 B at most here\n", $out);
        self::assertSame("\nanswered 413.\n", $said);
    }

    // ───────────────────────── helpers ─────────────────────────

    /**
     * Runs the command with $arguments against a transport that answers $status with $body.
     *
     * @param int                            $status
     * @param string                         $body
     * @param list<string>                   $arguments
     * @param ArrayObject<int, Request>|null $sent  Where each request sent is recorded.
     * @param mixed                          $stdin What `-` reads.
     * @return array{ExitCode, string, string}
     */
    private function drop(
        int $status,
        string $body,
        array $arguments,
        ?ArrayObject $sent = null,
        mixed $stdin = null,
    ): array {
        $transport = new readonly class ($status, $body, $sent ?? new ArrayObject()) implements Transport {
            /**
             * @param int                       $status
             * @param string                    $body
             * @param ArrayObject<int, Request> $sent
             */
            public function __construct(private int $status, private string $body, private ArrayObject $sent) {}

            /**
             * @param Request $request
             * @return Response
             */
            public function send(Request $request): Response
            {
                $this->sent->append($request);

                return new Response($this->status, $this->body);
            }
        };

        $out   = fopen('php://memory', 'r+');
        $error = fopen('php://memory', 'r+');

        $code = Runner::execute(
            new DropCreate('https://example.test', '.config/example/update.key', $transport, $stdin),
            ['--key', $this->keyFile->path, ...$arguments],
            new Output($out, $error),
        );

        rewind($out);
        rewind($error);

        return [$code, (string) stream_get_contents($out), (string) stream_get_contents($error)];
    }

    /**
     * What the admin answers a drop kept: what it kept, and the link.
     *
     * @return string
     */
    private static function made(): string
    {
        return (string) json_encode([
            'status'   => 201,
            'sections' => [
                ['caption' => null, 'lines' => ['kept — the link below opens it', 'text — 17 B']],
                ['caption' => null, 'lines' => ['/drop#' . self::TOKEN]],
            ],
        ], JSON_THROW_ON_ERROR);
    }

    /**
     * An admin answer as the server writes one: a status and one line.
     *
     * @param int    $status
     * @param string $line
     * @return string
     */
    private static function written(int $status, string $line): string
    {
        return (string) json_encode(
            ['status' => $status, 'sections' => [['caption' => null, 'lines' => [$line]]]],
            JSON_THROW_ON_ERROR,
        );
    }

    /**
     * The manifest a request was signed with, read back out of its `Authorization` header.
     *
     * @param Request $request
     * @return array<string, mixed>
     */
    private static function manifestOf(Request $request): array
    {
        $line = (string) $request->header(OutboundHeader::Authorization)?->line();
        $blob = (string) base64_decode(substr($line, (int) strpos($line, 'NS1 ') + 4), true);

        /** @var array{1: int} $length */
        $length = unpack('N', substr($blob, 0, 4));

        /** @var array<string, mixed> $manifest */
        $manifest = json_decode(substr($blob, 4, $length[1]), true, 8, JSON_THROW_ON_ERROR);

        return $manifest;
    }
}
