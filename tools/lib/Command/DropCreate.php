<?php

declare(strict_types=1);

namespace Phpanta\Tool\Command;

use Phpanta\Http\Api\ActionField;
use Phpanta\Http\Api\ApiService;
use Phpanta\Http\Api\ApiVersion;
use Phpanta\Http\Api\DropAction;
use Phpanta\Http\HttpStatusCode;
use Phpanta\Model\Update\UpdateManifest;
use Phpanta\Service\ApiGate;
use Phpanta\Support\DropPath;
use Phpanta\Support\File;
use Phpanta\Tool\Api\ApiTarget;
use Phpanta\Tool\Api\PrivateKey;
use Phpanta\Tool\Api\ResultReader;
use Phpanta\Tool\Api\SignedRequest;
use Phpanta\Tool\Cli\Arity;
use Phpanta\Tool\Cli\Command;
use Phpanta\Tool\Cli\ExitCode;
use Phpanta\Tool\Cli\Input;
use Phpanta\Tool\Cli\Option;
use Phpanta\Tool\Cli\Output;
use Phpanta\Tool\Cli\UsageException;
use Phpanta\Tool\Http\CurlTransport;
use Phpanta\Tool\Http\Transport;
use Phpanta\Tool\Http\TransportException;

/**
 * The DropCreate command. A text or a file, kept on a deployment as a drop — sealed there behind the
 * link this prints, which the deployment keeps nowhere.
 *
 * ```
 * php tools/drop.php notes.pdf                      # a file, saved under its own name
 * php tools/drop.php notes.pdf --name q3.pdf --once # under another, gone once it has been read
 * some-command | php tools/drop.php -               # standard input: text, unless --name says a file
 * php tools/drop.php --text 'the wifi password' --lifetime 30m --password-file ~/.drop-pass
 * ```
 *
 * **It is `drop v1 create`, signed, with the bytes as the body** — the one action {@link ApiCall}
 * refuses besides a push, since it has no body to send. Its size is the most a signed call carries,
 * {@link ApiGate::MAX_BODY}, refused here before anything is sent; a deployment may keep less.
 *
 * **The link is this command's to write whole**, from the origin it called and the address the admin
 * answered: the deployment names no origin of its own for it, since the `Host` a request carries is
 * the caller's to say. Fetch it with a browser, or with `curl -F token=<token> <origin>/drop`.
 *
 * **A password comes from a file**, never the command line, where the shell's history and every
 * process listing would keep it. Its first line is the password, the line's end left off.
 */
final readonly class DropCreate implements Command
{
    /**
     * Constructs an instance of {@link self}.
     *
     * @param string         $origin    Which deployment a drop goes to unless `--url` says otherwise.
     * @param string         $keyPath   That deployment's private key, relative to `$HOME`.
     * @param Transport|null $transport A test seam; production sends over curl.
     * @param mixed          $stdin     A test seam: what `-` reads; null for the process's own.
     */
    public function __construct(
        private string     $origin,
        private string     $keyPath,
        private ?Transport $transport = null,
        private mixed      $stdin = null,
    ) {}

    /**
     * @return string
     */
    public function name(): string
    {
        return 'drop';
    }

    /**
     * @return string
     */
    public function usage(): string
    {
        return '[<file> | -] [--text <text>] [--name <name>] [--lifetime <30m|12h|7d>] [--once]'
            . ' [--password-file <file>] [--dry-run] [--url <origin>] [--key <file>]';
    }

    /**
     * @return string
     */
    public function description(): string
    {
        return 'Keep a text or a file as a drop, and print the link that opens it.';
    }

    /**
     * @return list<Option>
     */
    public function options(): array
    {
        return DropCreateOption::cases();
    }

    /**
     * @return Arity
     */
    public function operands(): Arity
    {
        return Arity::between(0, 1);
    }

    /**
     * @param Input  $input
     * @param Output $output
     * @return ExitCode
     */
    public function run(Input $input, Output $output): ExitCode
    {
        try {
            $payload = $this->payload($input);
            $fields  = $this->fields($input);
            $target  = ApiTarget::resolve(
                $input->value(DropCreateOption::Url),
                $input->value(DropCreateOption::Key),
                $this->origin,
                $this->keyPath,
                ApiTarget::home(),
            );
            $request = SignedRequest::build(
                $target->origin,
                ApiService::Drop,
                ApiVersion::V1,
                DropAction::Create,
                $payload,
                $fields,
                PrivateKey::fromFile($target->key),
            );
            $response = ($this->transport ?? new CurlTransport())->send($request);
        } catch (UsageException $exception) {
            $output->error($this->name() . ': ' . $exception->getMessage() . "\n");

            return ExitCode::Usage;
        } catch (TransportException $exception) {
            $output->error($this->name() . ': ' . $exception->getMessage() . "\n");

            return ExitCode::Failure;
        }

        $text = ResultReader::read($response->body)?->text();

        if ($text !== null) {
            $output->out($text);
        }

        if (!$response->isOk()) {
            $output->error(match (true) {
                $response->code() === HttpStatusCode::Unauthorized => sprintf(
                    "\nrefused with %d: this key is not the deployment's, or this clock is not its clock.\n",
                    $response->status,
                ),
                $text === null => sprintf(
                    "\nanswered %d, and not the way the admin answers: is the drop service switched on there?\n",
                    $response->status,
                ),
                default => sprintf("\nanswered %d.\n", $response->status),
            });

            return ExitCode::Failure;
        }

        $link = '~^' . preg_quote(DropPath::Index->to(), '~') . '#[A-Za-z0-9_-]+$~m';

        if (preg_match($link, (string) $text, $found) === 1) {
            $output->out("\n" . rtrim($target->origin->render(), '/') . $found[0] . "\n");
        }

        return ExitCode::Success;
    }

    /**
     * What is kept: the text given, standard input, or the file named.
     *
     * @param Input $input
     * @return string
     * @throws UsageException if none is given or more than one, or what is given cannot be read or is
     *                        more than a signed call carries.
     */
    private function payload(Input $input): string
    {
        $path = $input->operand(0);
        $text = $input->value(DropCreateOption::Text);

        if (($path === null) === ($text === null)) {
            throw new UsageException('keep a file, - for standard input, or --text — one of them');
        }

        $payload = match (true) {
            $text !== null => $text,
            $path === '-'  => stream_get_contents($this->stdin ?? STDIN),
            default        => self::read((string) $path),
        };

        if (!is_string($payload)) {
            throw new UsageException(sprintf('%s could not be read', $path));
        }

        if (strlen($payload) > ApiGate::MAX_BODY) {
            throw new UsageException(sprintf('a drop sent this way is %d bytes at most', ApiGate::MAX_BODY));
        }

        return $payload;
    }

    /**
     * The manifest's own fields: whether to keep it, and how.
     *
     * @param Input $input
     * @return array<string, bool|string>
     * @throws UsageException if the password's file cannot be read, or its first line is empty.
     */
    private function fields(Input $input): array
    {
        $path   = $input->operand(0);
        $name   = $input->value(DropCreateOption::Name) ?? ($path === null || $path === '-' ? null : basename($path));
        $fields = [
            UpdateManifest::APPLY    => !$input->has(DropCreateOption::DryRun),
            ActionField::Once->value => $input->has(DropCreateOption::Once),
        ];

        if ($name !== null) {
            $fields[ActionField::Filename->value] = $name;
        }

        $lifetime = $input->value(DropCreateOption::Lifetime);

        if ($lifetime !== null) {
            $fields[ActionField::Lifetime->value] = $lifetime;
        }

        $secret = $input->value(DropCreateOption::PasswordFile);

        if ($secret !== null) {
            $password = strtok((string) self::read($secret), "\r\n");

            if (!is_string($password) || $password === '') {
                throw new UsageException(sprintf('%s holds no password on its first line', $secret));
            }

            $fields[ActionField::Password->value] = $password;
        }

        return $fields;
    }

    /**
     * What the file at $path holds — resolved against where the command was run, as a path typed on a
     * command line is — or null where it cannot be read.
     *
     * @param string $path
     * @return string|null
     */
    private static function read(string $path): ?string
    {
        $real = realpath($path);

        return $real === false ? null : new File($real)->read();
    }
}
