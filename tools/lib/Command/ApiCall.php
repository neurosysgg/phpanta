<?php

declare(strict_types=1);

namespace Phpanta\Tool\Command;

use Closure;
use Phpanta\Http\Api\ActionField;
use Phpanta\Http\Api\ApiService;
use Phpanta\Http\Api\ApiVersion;
use Phpanta\Http\Api\UpdateAction;
use Phpanta\Http\HttpMethod;
use Phpanta\Http\HttpStatusCode;
use Phpanta\Model\Update\UpdateManifest;
use Phpanta\Support\AdminPath;
use Phpanta\Tool\Api\ApiTarget;
use Phpanta\Tool\Api\ListingReader;
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
use Phpanta\Tool\Http\Request;
use Phpanta\Tool\Http\Transport;
use Phpanta\Tool\Http\TransportException;
use Phpanta\Tool\Http\Url;

/**
 * The ApiCall command. One signed call to the admin, named on the command line — or, given less than
 * a whole address, what the admin offers there.
 *
 * `php tools/api.php update v1 version` is the shape of a call — `health v1 report`,
 * `capability v1 extensions` and `update v1 rollback` are the same command. `php tools/api.php`
 * alone lists the services the *server* offers, `php tools/api.php update` that service's versions,
 * and `php tools/api.php update v1` its actions: the admin discovers itself, so a server newer than
 * this checkout says what it has rather than this command guessing. It is the client for every
 * action that carries **no body** — which is every action but `patch`, and that one has
 * {@link PushUpdate} because building the tree it sends is most of what that command does.
 *
 * **A write is signed with `apply`**, the one field every write action reads — false under
 * `--dry-run`, so the server reports what it would do, changes nothing and spends no serial. A
 * read, and a listing, refuse `--dry-run` rather than ignoring it: a flag that does nothing on one
 * address and everything on another is a flag somebody will one day trust on the wrong one.
 *
 * **Every other field an action reads is a flag of its own** — `--code` and `--name` for
 * `access v1 enrol`, `--passkey` for `access v1 revoke` — and the action's own
 * {@link \Phpanta\Http\Api\ApiAction::fields()} decides which it needs: a missing one is asked for,
 * and one it does not take is refused rather than signed and ignored.
 *
 * **The exit code is the answer's**: 0 for a 2xx and 1 for anything else, so a failed `health`
 * check — a 503 with the report in its body — can end a script. A `401` is explained as the refusal
 * it is, and an answer that is not the admin's at all as a server older than it.
 *
 * **A whole address is refused before anything is sent when this checkout does not know it**, since
 * the method a call is signed for comes from the action's own enum; a listing of the address's
 * parent says what the server offers instead.
 *
 * **And it refuses an action that takes a body**, for the honest reason: this command has no way to
 * produce one. A `--body-file` would be the beginning of a general-purpose HTTP client, which is
 * not what this is — an action with a body is an action whose payload somebody has to build, and
 * that is a command of its own.
 *
 * Which deployment it calls, and with which key, is {@link ApiTarget}'s to decide.
 */
final readonly class ApiCall implements Command
{
    /**
     * Constructs an instance of {@link self}.
     *
     * @param string         $origin    Which deployment a call goes to unless `--url` says otherwise — an
     *                                   origin, not an endpoint. The site's own entry script says which.
     * @param string         $keyPath   That deployment's private key, relative to `$HOME` — outside the
     *                                   repository entirely. Any other origin signs with its own.
     * @param Transport|null $transport A test seam; production sends over curl.
     */
    public function __construct(
        private string     $origin,
        private string     $keyPath,
        private ?Transport $transport = null,
    ) {}

    /**
     * @return string
     */
    public function name(): string
    {
        return 'api';
    }

    /**
     * @return string
     */
    public function usage(): string
    {
        return '[<service> [<version> [<action>]]] [--dry-run] [--code <code>] [--name <name>]'
            . ' [--passkey <id>] [--url <origin>] [--key <file>]';
    }

    /**
     * @return string
     */
    public function description(): string
    {
        return 'Make one signed call to the admin, or list what it offers.';
    }

    /**
     * @return list<Option>
     */
    public function options(): array
    {
        return ApiCallOption::cases();
    }

    /**
     * @return Arity
     */
    public function operands(): Arity
    {
        return Arity::between(0, 3);
    }

    /**
     * @param Input $input
     * @param Output $output
     * @return ExitCode
     */
    public function run(Input $input, Output $output): ExitCode
    {
        $service = $input->operand(0);
        $version = $input->operand(1);
        $action  = $input->operand(2);

        if ($action === null) {
            return $this->list($input, $output, $service, $version);
        }

        $known   = ApiService::tryFrom($service ?? '');
        $revised = ApiVersion::tryFrom($version ?? '');
        $named   = $revised === null ? null : $known?->action($revised, $action);

        if ($known === null || $revised === null || $named === null) {
            $output->error(sprintf(
                "%s: no such action here — %s/%s/%s. Leave the action off to see what the server offers.\n",
                $this->name(),
                $service ?? '',
                $version ?? '',
                $action,
            ));

            return ExitCode::Usage;
        }

        if ($named === UpdateAction::Patch) {
            $output->error(sprintf("%s: %s carries a body, so it has a command of its own.\n", $this->name(), $action));

            return ExitCode::Usage;
        }

        $write  = $named->method() !== HttpMethod::Get;
        $dryRun = $input->has(ApiCallOption::DryRun);

        if ($dryRun && !$write) {
            $output->error(sprintf(
                "%s: %s is a read, which changes nothing, so it has no dry run.\n",
                $this->name(),
                $action,
            ));

            return ExitCode::Usage;
        }

        // The flag is negative and the field positive, as in PushUpdate: `--dry-run` means
        // `apply: false`. The key is the server's own constant, so the two cannot drift.
        $fields = $write ? [UpdateManifest::APPLY => !$dryRun] : [];

        // Every other field the action takes comes from its flag — asked for where it is missing,
        // and a flag the action does not take is refused rather than signed and ignored.
        foreach (ApiCallOption::cases() as $option) {
            $field = $option->field();
            $given = $input->value($option);

            if ($field === null) {
                continue;
            }

            $takes = $named->fields()->first(static fn(ActionField $each): bool => $each === $field) !== null;

            if ($takes !== ($given !== null)) {
                $output->error(sprintf(
                    $takes ? "%s: %s needs --%s.\n" : "%s: %s takes no --%s.\n",
                    $this->name(),
                    $action,
                    $option->flag(),
                ));

                return ExitCode::Usage;
            }

            if ($given !== null) {
                $fields[$field->value] = $given;
            }
        }

        return $this->send($input, $output, static fn(Url $origin, PrivateKey $key): Request
            => SignedRequest::build($origin, $known, $revised, $named, '', $fields, $key));
    }

    /**
     * What the admin offers at the address the operands name so far.
     *
     * @param Input $input
     * @param Output $output
     * @param string|null $service
     * @param string|null $version
     * @return ExitCode
     */
    private function list(Input $input, Output $output, ?string $service, ?string $version): ExitCode
    {
        if ($input->has(ApiCallOption::DryRun)) {
            $output->error(sprintf("%s: a listing only reads, so it has no dry run.\n", $this->name()));

            return ExitCode::Usage;
        }

        foreach (ApiCallOption::cases() as $option) {
            if ($option->field() !== null && $input->value($option) !== null) {
                $output->error(sprintf("%s: a listing takes no --%s.\n", $this->name(), $option->flag()));

                return ExitCode::Usage;
            }
        }

        $path = match (true) {
            $service === null => AdminPath::Index->to(),
            $version === null => AdminPath::Service->to($service),
            default           => AdminPath::Version->to($service, $version),
        };

        return $this->send($input, $output, static fn(Url $origin, PrivateKey $key): Request
            => SignedRequest::signed($origin, $path, HttpMethod::Get, '', [], $key));
    }

    /**
     * Signs the request $build makes, sends it, and says what came back.
     *
     * @param Input $input
     * @param Output $output
     * @param Closure(Url, PrivateKey): Request $build
     * @return ExitCode
     */
    private function send(Input $input, Output $output, Closure $build): ExitCode
    {
        try {
            $target = ApiTarget::resolve(
                $input->value(ApiCallOption::Url),
                $input->value(ApiCallOption::Key),
                $this->origin,
                $this->keyPath,
                ApiTarget::home(),
            );

            $response = ($this->transport ?? new CurlTransport())->send(
                $build($target->origin, PrivateKey::fromFile($target->key)),
            );
        } catch (UsageException | TransportException $exception) {
            $output->error($this->name() . ': ' . $exception->getMessage() . "\n");

            return $exception instanceof UsageException ? ExitCode::Usage : ExitCode::Failure;
        }

        // The answer's own text where it is a result or a listing. An answer that is not the
        // admin's at all — a site's own page — says nothing worth printing, and the lines below
        // say what it means instead.
        $text = ResultReader::read($response->body)?->text() ?? ListingReader::text($response->body);

        if ($text !== null || $response->isOk()) {
            $output->out($text ?? $response->body);
        }

        if ($response->isOk()) {
            return ExitCode::Success;
        }

        $output->error(match (true) {
            // Anything the gate will not verify is one answer, which does not say which check
            // failed, by design. The two causes are the ones a signing machine can check.
            $response->code() === HttpStatusCode::Unauthorized => sprintf("\nrefused with %d.\n", $response->status)
                . "  That is what the admin answers a request it cannot verify — it does not say which"
                . " check failed, by design. In order of likelihood:\n"
                . "    1. data/update.pub on the server does not match this private key\n"
                . "    2. this machine's clock is more than five minutes from the server's\n",
            $text === null => sprintf("\nanswered %d, and not the way the admin answers.\n", $response->status)
                . "  The server has no /admin: it is older than this command, and a full deploy updates it.\n",
            // Anything else was answered by the admin to a verified caller, whose body above
            // already says what went wrong — a failed health check is a 503 carrying the whole
            // report, a rollback with nothing to roll back is a 422 saying so.
            default => sprintf("\nanswered %d.\n", $response->status),
        });

        return ExitCode::Failure;
    }
}
