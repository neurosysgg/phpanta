<?php

declare(strict_types=1);

namespace Phpanta\Controller;

use Phpanta\Exception\ApiException;
use Phpanta\Http\Allow;
use Phpanta\Http\Api\ApiAction;
use Phpanta\Http\Api\ApiResult;
use Phpanta\Http\Api\ApiService;
use Phpanta\Http\Api\ApiVersion;
use Phpanta\Http\CacheControl;
use Phpanta\Http\Header;
use Phpanta\Http\HttpStatusCode;
use Phpanta\Http\JsonResponse;
use Phpanta\Http\PlainTextResponse;
use Phpanta\Http\Representation;
use Phpanta\Http\Request;
use Phpanta\Http\RequestHeader;
use Phpanta\Http\Response;
use Phpanta\Http\ResponseHeader;
use Phpanta\Http\Vary;
use Phpanta\Http\ViewResponse;
use Phpanta\Model\Api\SerialRefusal;
use Phpanta\Model\Api\VerifiedRequest;
use Phpanta\Service\ApiGate;
use Phpanta\Support\Collection;
use Phpanta\View\ApiResultView;

/**
 * The ApiController class. Everything under `/api`, and it answers as though none of it is there
 * unless the request carries a signature this deployment can verify.
 *
 * **The refusal is the interesting half.** A 401 would announce the endpoint; so would a 403, and
 * so would a 405 naming POST. What this returns instead is *exactly* what the router returns for a
 * path no route claims — the rendered 404 for a read method, the `text/plain` 405 with
 * `Allow: GET, HEAD` for a write one — so every address under `/api` is indistinguishable from a
 * typo under every verb. That is why {@link \Phpanta\Support\ApiPath::Api} is registered
 * {@link \Phpanta\Support\MethodPolicy::Delegated}: a route accepting POST alone would answer
 * `GET /api/update/v1/patch` with `405 Allow: POST` and give itself away in the one response an
 * idle prober is most likely to make.
 *
 * Both responses come from {@link UnroutedController}, which is the very object
 * {@link \Phpanta\Router} delegates to when no route matches at all. They are not two
 * implementations kept in step by a test — they are one, so "indistinguishable" is a property of
 * the structure rather than something anybody has to remember. A test asserts it anyway, because
 * it is worth failing loudly if the two are ever split again.
 *
 * **It verifies before it resolves, and that order is the whole reason one class can serve every
 * service.** Asking "does this service exist" first would answer an unsigned caller through a
 * different path depending on what they guessed, and two paths that produce the same answer today
 * are two paths free to stop. Verified first, a service that does not exist and a signature that
 * does not verify are the same `null` reaching the same line.
 *
 * Past the gate the posture inverts completely and every failure is reported in full, because the
 * caller has proved it holds the private key. An unknown service, version or action is a real 404
 * with a sentence in it, and a verb that is not the action's is a real 405 naming the one that is.
 * Only the key holder ever sees either. There is nowhere else for that detail to go: a production
 * host has `display_errors` off, and may have an empty `error_log`.
 *
 * **Every answer past the gate is an {@link ApiResult}, written in the form the request asked
 * for** — a page by default, data for `Accept: application/json`, and a `406` for a request that
 * named only types it has neither of. That question is asked straight after the gate and before
 * any action runs, so a write is never carried out for a caller who then cannot be told how it went.
 * No answer past the gate is kept by a cache, and each says it varies on `Accept`.
 */
final readonly class ApiController implements Controller
{
    /**
     * Constructs an instance of {@link self}.
     *
     * @param string $service The first segment, exactly as it was sent. A string rather than an
     *                        {@link ApiService}, because resolving it here would mean a `from()` in
     *                        the route factory — a bare `ValueError`, uncaught, *before* the
     *                        signature is checked, which is both a 500 announcing the endpoint and
     *                        an exception the framework does not own.
     * @param string $version Same, for the second.
     * @param string $action Same, for the fourth.
     * @param ApiGate|null $gate A test seam: null is the real gate, and a test passes its own.
     */
    public function __construct(
        private string   $service,
        private string   $version,
        private string   $action,
        private ?ApiGate $gate = null,
    ) {}

    /**
     * @param Request $request
     * @return Response
     */
    public function handle(Request $request): Response
    {
        $gate     = $this->gate ?? new ApiGate();
        $verified = $gate->accepts($request);

        if ($verified === null) {
            return new UnroutedController()->handle($request);
        }

        $representation = $request->accepted()->preferred(Representation::Html, Representation::Json);

        if ($representation === null) {
            return self::notAcceptable();
        }

        $action = $this->action();

        if ($action === null) {
            return $this->answer($representation, ApiResult::refusal(HttpStatusCode::NotFound, sprintf(
                'no such API action: %s %s',
                $verified->envelope->method,
                $this->address(),
            )));
        }

        // The gate has already checked that the *credential* was minted for this method; this asks
        // whether the method is one the action answers on at all. They are different questions and
        // both are needed — the first stops a read's credential being replayed as a write, and this
        // stops a correctly signed request asking for something that makes no sense.
        if ($action->method() !== $request->method()) {
            return $this->answer(
                $representation,
                ApiResult::refusal(
                    HttpStatusCode::MethodNotAllowed,
                    sprintf('%s answers %s', $this->action, $action->method()->value),
                ),
                new Header(ResponseHeader::Allow, Allow::of($action->method())),
            );
        }

        return $this->answer($representation, $this->run($gate, $verified, $action));
    }

    /**
     * Runs $action for a caller the gate has verified.
     *
     * **One catch around building the handler and around running it**, which is what makes this
     * the only place in the framework that writes the word. Both throws mean the same thing — a
     * verified caller asked for something this deployment will not do — and both happen before
     * anything has been written, since UpdateApplier's own contract is that nothing has when it
     * throws. Two handlers each phrasing that refusal for themselves is two spellings of one
     * sentence, which is what `GuidelineTest`'s two-files clause caught when they were.
     *
     * @param ApiGate $gate
     * @param VerifiedRequest $verified
     * @param ApiAction $action
     * @return ApiResult
     */
    private function run(ApiGate $gate, VerifiedRequest $verified, ApiAction $action): ApiResult
    {
        try {
            $handler = $action->handler($verified);

            // Only an action that changes something spends the serial. A read leaves it alone, and
            // so does a dry run, so the very same credential can then be sent for real.
            if (!$handler->isWrite()) {
                return $handler->handle();
            }

            // **Spent before the action runs, not after**, and under a lock the write then holds.
            // A write that could not arm the replay guard would leave a deployment updated and the
            // credential able to update it again; arming first makes that a refusal with nothing
            // written. It costs a serial on a deployment that cannot record one, which is a
            // deployment that is not going to accept the next push either. See docs/security.md.
            $spent = $gate->spend($verified->envelope->serial);

            if ($spent instanceof SerialRefusal) {
                return ApiResult::refusal($spent->status(), rtrim($spent->message(), "\n"));
            }

            try {
                return $handler->handle();
            } finally {
                $spent->release();
            }
        } catch (ApiException $e) {
            return ApiResult::refusal(HttpStatusCode::UnprocessableContent, 'refused: ' . $e->getMessage());
        }
    }

    /**
     * $result, written as $representation: never kept by a cache, and varying on `Accept`.
     *
     * @param Representation $representation
     * @param ApiResult $result
     * @param Header ...$headers What this one answer adds — a 405's `Allow`.
     * @return Response
     */
    private function answer(Representation $representation, ApiResult $result, Header ...$headers): Response
    {
        $headers = new Collection(Header::class)->with(
            new Header(ResponseHeader::CacheControl, CacheControl::doNotStore()),
            ...$headers,
        );

        // The page's Vary comes from its view, beside the ones every page sends; the data's has to
        // be said here, because a JsonResponse varies on nothing unless told.
        return match ($representation) {
            Representation::Html => new ViewResponse(
                new ApiResultView($result, $this->address()),
                $result->status,
                $headers,
            ),
            Representation::Json => new JsonResponse(
                $result,
                $result->status,
                $headers->with(new Header(ResponseHeader::Vary, Vary::on(RequestHeader::Accept))),
            ),
        };
    }

    /**
     * The `406` for a verified request that named only types this has neither of.
     *
     * Plain text, because it is the one answer here that cannot be written in either form the
     * request refused; it names both, so the caller knows what to ask for instead.
     *
     * @return Response
     */
    private static function notAcceptable(): Response
    {
        return new PlainTextResponse(
            HttpStatusCode::NotAcceptable,
            sprintf("this answers %s or %s\n", Representation::Html->value, Representation::Json->value),
            new Collection(Header::class)->with(
                new Header(ResponseHeader::CacheControl, CacheControl::doNotStore()),
                new Header(ResponseHeader::Vary, Vary::on(RequestHeader::Accept)),
            ),
        );
    }

    /**
     * The three segments as they were sent, as `service/version/action`.
     *
     * @return string
     */
    private function address(): string
    {
        return $this->service . '/' . $this->version . '/' . $this->action;
    }

    /**
     * The action all three segments name, or null where they name none.
     *
     * `tryFrom` at every step rather than `from`, for {@link \Phpanta\Http\HttpMethod::tryFrom()}'s
     * reason: each segment is whatever the caller sent, and a caller being verified does not make
     * their typo an exception. Three nulls collapse to one, because the difference between a
     * service that does not exist and an action that does not is of no use to anyone who has
     * already been told the address is wrong.
     *
     * @return ApiAction|null
     */
    private function action(): ?ApiAction
    {
        $service = ApiService::tryFrom($this->service);
        $version = ApiVersion::tryFrom($this->version);

        if ($service === null || $version === null) {
            return null;
        }

        return $service->action($version, $this->action);
    }
}
