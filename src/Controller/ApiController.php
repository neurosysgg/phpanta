<?php

declare(strict_types=1);

namespace Phpanta\Controller;

use Phpanta\Exception\ApiException;
use Phpanta\Http\Allow;
use Phpanta\Http\Api\ApiAction;
use Phpanta\Http\Api\ApiListing;
use Phpanta\Http\Api\ApiResult;
use Phpanta\Http\Api\ApiService;
use Phpanta\Http\Api\ApiVersion;
use Phpanta\Http\CacheControl;
use Phpanta\Http\Header;
use Phpanta\Http\HttpStatusCode;
use Phpanta\Http\JsonResponse;
use Phpanta\Http\Location;
use Phpanta\Http\PlainTextResponse;
use Phpanta\Http\RedirectResponse;
use Phpanta\Http\Representation;
use Phpanta\Http\Request;
use Phpanta\Http\RequestHeader;
use Phpanta\Http\Response;
use Phpanta\Http\ResponseHeader;
use Phpanta\Http\RobotsPolicy;
use Phpanta\Http\SignedChallenge;
use Phpanta\Http\Vary;
use Phpanta\Http\ViewResponse;
use Phpanta\Model\Api\SerialRefusal;
use Phpanta\Model\Api\VerifiedRequest;
use Phpanta\Service\ApiGate;
use Phpanta\Support\AdminPath;
use Phpanta\Support\Collection;
use Phpanta\View\AdminEntranceView;
use Phpanta\View\ApiListingView;
use Phpanta\View\ApiResultView;

/**
 * The ApiController class. Everything under `/admin`, at every depth.
 *
 * **Three questions, in this order, and the order is the design.**
 *
 * 1. *What can the caller read?* A page by default, which is what a browser and `curl` get; data
 *    for `Accept: application/json`, which is what the signing commands ask for; a `406` for a
 *    request that named only types it cannot have.
 * 2. *Who is asking?* A request the gate cannot verify gets one answer at every depth below the
 *    entrance, **whether the address exists or not**: a page request is sent to `/admin`, a request
 *    for data is a `401` challenging for `NS1`. The entrance itself is a page anybody may see. So a
 *    stranger learns that there is an admin — which a site may say anyway, with a link to it — and
 *    nothing about what is in it.
 * 3. *What is here?* Only past the gate, and reported in full, because the caller has proved it
 *    holds the key: a listing of what is under the address, or the action's answer — and an unknown
 *    service, version or action is a real `404` with a sentence in it, a verb that is not the
 *    action's a real `405` naming the one that is.
 *
 * **It verifies before it resolves**, which is what keeps the second answer uniform: asking "does
 * this service exist" first would answer a stranger through a different path depending on what they
 * guessed. And it negotiates before it runs, so a write is never carried out for a caller who then
 * could not be told how it went. Every answer is kept by no cache, varies on `Accept`, and asks not
 * to be indexed. There is nowhere else for the detail past the gate to go: a production host has
 * `display_errors` off, and may have an empty `error_log`.
 *
 * Every depth is registered {@link \Phpanta\Support\MethodPolicy::Delegated}, so every method — one
 * the framework does not recognise included — reaches this class, and a stranger's `BREW` is
 * answered exactly as their `GET` is.
 */
final readonly class ApiController implements Controller
{
    /**
     * Constructs an instance of {@link self}.
     *
     * @param string|null $service The first segment, exactly as it was sent, or null at the entrance.
     *                             A string rather than an {@link ApiService}, because resolving it
     *                             here would mean a `from()` in the route factory — a bare
     *                             `ValueError`, uncaught, *before* the caller is known.
     * @param string|null $version Same, for the second; null above a version.
     * @param string|null $action  Same, for the third; null above an action.
     * @param ApiGate|null $gate A test seam: null is the real gate, and a test passes its own.
     */
    public function __construct(
        private ?string  $service = null,
        private ?string  $version = null,
        private ?string  $action = null,
        private ?ApiGate $gate = null,
    ) {}

    /**
     * @param Request $request
     * @return Response
     */
    public function handle(Request $request): Response
    {
        $representation = $request->accepted()->preferred(Representation::Html, Representation::Json);

        if ($representation === null) {
            return self::notAcceptable();
        }

        $gate     = $this->gate ?? new ApiGate();
        $verified = $gate->accepts($request);

        if ($verified === null) {
            return $this->unverified($request, $representation);
        }

        if ($this->service === null || $this->version === null || $this->action === null) {
            return $this->listing($request, $representation);
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
     * The one answer a caller the gate cannot verify gets, whatever it asked for and wherever.
     *
     * Data is a `401` challenging for `NS1` — a scheme a browser has no prompt for, so it never shows
     * one. A page is the entrance, for a read of the entrance, and a `303` to it for everything else:
     * a deeper address, existing or not, and a write of any kind, since a write that arrives without
     * a credential has nothing to be told but where to start.
     *
     * @param Request $request
     * @param Representation $representation
     * @return Response
     */
    private function unverified(Request $request, Representation $representation): Response
    {
        if ($representation === Representation::Json) {
            return new JsonResponse(
                ApiResult::refusal(HttpStatusCode::Unauthorized, 'this needs a signed request'),
                HttpStatusCode::Unauthorized,
                self::private(
                    new Header(ResponseHeader::WwwAuthenticate, new SignedChallenge()),
                    new Header(ResponseHeader::Vary, Vary::on(RequestHeader::Accept)),
                ),
            );
        }

        if ($this->service === null && $request->isReadOnly()) {
            return new ViewResponse(new AdminEntranceView(), HttpStatusCode::Ok, self::private());
        }

        return new RedirectResponse(
            new Location(AdminPath::Index->to()),
            HttpStatusCode::SeeOther,
            self::private(new Header(ResponseHeader::Vary, Vary::on(RequestHeader::Accept))),
        );
    }

    /**
     * What is under this address, for a verified caller — or a `404` where nothing is, and a `405`
     * for anything but a read, since a listing only lists.
     *
     * @param Request $request
     * @param Representation $representation
     * @return Response
     */
    private function listing(Request $request, Representation $representation): Response
    {
        if (!$request->isReadOnly()) {
            return $this->answer(
                $representation,
                ApiResult::refusal(HttpStatusCode::MethodNotAllowed, sprintf('%s only lists', $request->path())),
                new Header(ResponseHeader::Allow, Allow::readOnly()),
            );
        }

        // A version is resolved from the ones the service offers rather than from every version
        // there is, so a version one service has and another has not is an address the second does
        // not have — and a listing of it can never come out empty.
        $language = $request->language();
        $service  = $this->service === null ? null : ApiService::tryFrom($this->service);
        $version  = $service?->versions()->first(fn(ApiVersion $each): bool => $each->value === $this->version);

        $listing = match (true) {
            $this->service === null => ApiListing::services($language),
            $service === null       => null,
            $this->version === null => ApiListing::versions($service, $language),
            $version === null       => null,
            default                 => ApiListing::actions($service, $version, $language),
        };

        if ($listing === null) {
            return $this->answer(
                $representation,
                ApiResult::refusal(HttpStatusCode::NotFound, sprintf('no such admin address: %s', $request->path())),
            );
        }

        return match ($representation) {
            Representation::Html => new ViewResponse(new ApiListingView($listing), HttpStatusCode::Ok, self::private()),
            Representation::Json => new JsonResponse(
                $listing,
                HttpStatusCode::Ok,
                self::private(new Header(ResponseHeader::Vary, Vary::on(RequestHeader::Accept))),
            ),
        };
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
     * $result, written as $representation.
     *
     * @param Representation $representation
     * @param ApiResult $result
     * @param Header ...$headers What this one answer adds — a 405's `Allow`.
     * @return Response
     */
    private function answer(Representation $representation, ApiResult $result, Header ...$headers): Response
    {
        // The page's Vary comes from its view, beside the ones every page sends; the data's has to
        // be said here, because a JsonResponse varies on nothing unless told.
        return match ($representation) {
            Representation::Html => new ViewResponse(
                new ApiResultView($result, $this->address()),
                $result->status,
                self::private(...$headers),
            ),
            Representation::Json => new JsonResponse(
                $result,
                $result->status,
                self::private(...$headers, ...[new Header(ResponseHeader::Vary, Vary::on(RequestHeader::Accept))]),
            ),
        };
    }

    /**
     * The `406` for a request that named only types this has neither of.
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
            self::private(new Header(ResponseHeader::Vary, Vary::on(RequestHeader::Accept))),
        );
    }

    /**
     * What every answer here carries: kept by no cache, and not to be indexed — then $headers.
     *
     * @param Header ...$headers
     * @return Collection<Header>
     */
    private static function private(Header ...$headers): Collection
    {
        return new Collection(Header::class)->with(
            new Header(ResponseHeader::CacheControl, CacheControl::doNotStore()),
            new Header(ResponseHeader::Robots, RobotsPolicy::hide()),
            ...$headers,
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
        $service = ApiService::tryFrom((string) $this->service);
        $version = ApiVersion::tryFrom((string) $this->version);

        if ($service === null || $version === null) {
            return null;
        }

        return $service->action($version, (string) $this->action);
    }
}
