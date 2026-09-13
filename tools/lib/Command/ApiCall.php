<?php

declare(strict_types=1);

namespace Phpanta\Tool\Command;

use BackedEnum;
use Phpanta\Http\Api\ApiService;
use Phpanta\Http\Api\ApiVersion;
use Phpanta\Http\HttpMethod;
use Phpanta\Http\HttpStatusCode;
use Phpanta\Tool\Api\ApiTarget;
use Phpanta\Tool\Api\PrivateKey;
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
 * The ApiCall command. One signed call to `/api`, named on the command line.
 *
 * `php tools/api.php update v1 version` is the shape of it — `health v1 report` and
 * `capability v1 extensions` are the same command. It is the client for every action that carries
 * **no body** — which is every action but `patch`, and that one has {@link PushUpdate} because
 * building the tree it sends is most of what that command does.
 *
 * **A new service costs this file nothing**, which is the property worth stating rather than
 * assuming: the vocabulary below is the server's own {@link ApiService} and {@link ApiVersion}, and
 * the address, method and scheme all come off the action, so a service is reachable here the day
 * its enum exists and not a line later.
 *
 * **The exit code is the answer's**: 0 for a 2xx and 1 for anything else, so a failed `health`
 * check — a 503 with the report in its body — can end a script. Only a 404 is explained as a
 * refusal, because only a 404 is one.
 *
 * **It refuses an action it does not recognise before sending anything**, which is not politeness:
 * `/api` answers an unrecognised address exactly as it answers a wrong signature, so a typo here
 * would come back as the same 404 as a bad key and send somebody looking at their key. The
 * vocabulary is the server's own {@link ApiService} and {@link ApiVersion}, so this cannot be wrong
 * about what exists.
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
        return '<service> <version> <action> [--url <origin>] [--key <file>]';
    }

    /**
     * @return string
     */
    public function description(): string
    {
        return 'Make one signed call to the owner-only API.';
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
        return Arity::exactly(3);
    }

    /**
     * @param Input $input
     * @param Output $output
     * @return ExitCode
     */
    public function run(Input $input, Output $output): ExitCode
    {
        $service = ApiService::tryFrom($input->operand(0) ?? '');
        $version = ApiVersion::tryFrom($input->operand(1) ?? '');
        $action  = $service?->action($version ?? ApiVersion::V1, $input->operand(2) ?? '');

        if ($service === null || $version === null || $action === null) {
            $output->error(sprintf(
                "%s: no such action — %s/%s/%s\n",
                $this->name(),
                $input->operand(0) ?? '',
                $input->operand(1) ?? '',
                $input->operand(2) ?? '',
            ));

            return ExitCode::Usage;
        }

        if (!$action instanceof BackedEnum || $action->method() !== HttpMethod::Get) {
            $output->error(sprintf(
                "%s: %s carries a body, so it has a command of its own.\n",
                $this->name(),
                $input->operand(2) ?? '',
            ));

            return ExitCode::Usage;
        }

        try {
            $target = ApiTarget::resolve(
                $input->value(ApiCallOption::Url),
                $input->value(ApiCallOption::Key),
                $this->origin,
                $this->keyPath,
                ApiTarget::home(),
            );

            $request = SignedRequest::build(
                $target->origin,
                $service,
                $version,
                $action,
                '',
                [],
                PrivateKey::fromFile($target->key),
            );

            $response = ($this->transport ?? new CurlTransport())->send($request);
        } catch (UsageException | TransportException $exception) {
            $output->error($this->name() . ': ' . $exception->getMessage() . "\n");

            return $exception instanceof UsageException ? ExitCode::Usage : ExitCode::Failure;
        }

        $output->out($response->body);

        if ($response->isOk()) {
            return ExitCode::Success;
        }

        // A read that is not verified gets the rendered 404 an address that is not there gets,
        // which is a whole HTML page — so say what it means rather than leaving the operator to
        // read markup. Same three causes a refused push has, in the same order.
        //
        // Anything else was answered by a verified handler, whose body above already says what
        // went wrong — a `health` check that failed is a 503 carrying the whole report. Calling
        // that "refused" would send somebody looking at their key.
        $output->error($response->code() === HttpStatusCode::NotFound
            ? "\nrefused with 404.\n"
            . "  That is what /api answers to anything it will not verify — it does not"
            . " say which check failed, by design. In order of likelihood:\n"
            . "    1. data/update.pub on the server does not match this private key\n"
            . "    2. this machine's clock is more than five minutes from the server's\n"
            . "    3. the server is older than /api\n"
            : sprintf("\nanswered %d.\n", $response->status));

        return ExitCode::Failure;
    }
}
