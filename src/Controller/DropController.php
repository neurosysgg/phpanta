<?php

declare(strict_types=1);

namespace Phpanta\Controller;

use Phpanta\App;
use Phpanta\Exception\ThrottleException;
use Phpanta\Http\Allow;
use Phpanta\Http\Api\AdminHeaders;
use Phpanta\Http\ContentDisposition;
use Phpanta\Http\ContentLength;
use Phpanta\Http\DropField;
use Phpanta\Http\Header;
use Phpanta\Http\HttpMethod;
use Phpanta\Http\HttpStatusCode;
use Phpanta\Http\MimeType;
use Phpanta\Http\PlainTextResponse;
use Phpanta\Http\Request;
use Phpanta\Http\Response;
use Phpanta\Http\ResponseHeader;
use Phpanta\Http\RetryAfter;
use Phpanta\Http\StreamResponse;
use Phpanta\Http\ViewResponse;
use Phpanta\Model\Drop\DropKind;
use Phpanta\Model\Drop\DropRefusal;
use Phpanta\Model\Drop\DropToken;
use Phpanta\Service\Drop\DropStore;
use Phpanta\Service\Drop\OpenedDrop;
use Phpanta\Support\DropPath;
use Phpanta\Support\Throttle;
use Phpanta\Text\DropText;
use Phpanta\Text\Translatable;
use Phpanta\View\DropTextView;
use Phpanta\View\DropView;
use Phpanta\View\View;

/**
 * The DropController class. `/drop`: the page a drop's link opens, and the post that reveals it.
 *
 * **Off, it is an address that is not there.** Where the `drop` service is not switched on — no
 * `data/drop.json` that reads, or no key in `data/drop.key` — every request, whatever its method, is
 * handed to {@link UnroutedController} and answered exactly as `/no-such-page` is. That is why the
 * route is {@link \Phpanta\Support\MethodPolicy::Delegated}: a method gate in the router would answer a
 * `PUT` with an `Allow` naming `POST`, and say that a drop could be posted here when none can.
 *
 * **On, a read is the page and a post reveals.** The post carries the link's token and, where the
 * drop has one, its password; what answers it is what the drop holds — text on a page to read it on,
 * where the page's own form asked, and otherwise the bytes: a file saved under its name as
 * `application/octet-stream`, whatever it claims to be, and text as plain text. Every answer is kept
 * by no cache and asks not to be indexed.
 *
 * **One answer for every kind of nothing.** A token that is none, a drop never made, one expired, one
 * read once already: a `404` that says only that nothing is here. Only somebody holding a real link is
 * told a password is wanted, or wrong — a `403`. Every refusal is counted against the sender's address,
 * {@link self::FAILURES} in {@link self::WINDOW} seconds, and past that a post is a `429` before the
 * drop is asked anything: guessing a password costs the whole stretch of it, a few times an hour.
 * What reveals a drop is never counted, so a machine fetching what it was sent is never held up.
 */
final readonly class DropController implements Controller
{
    /** How many posts that revealed nothing one address may make in a window. */
    private const int FAILURES = 10;

    /** That window, in seconds: fifteen minutes. */
    private const int WINDOW = 900;

    /**
     * Constructs an instance of {@link self}. Every argument is a test seam; null is the deployment's own.
     *
     * @param DropStore|null $store    Where drops are kept — null to ask the deployment, which is off
     *                                 where it keeps none.
     * @param Throttle|null  $throttle What refusals are counted against.
     * @param int|null       $now      The time.
     */
    public function __construct(
        private ?DropStore $store = null,
        private ?Throttle  $throttle = null,
        private ?int       $now = null,
    ) {}

    /**
     * @param Request $request
     * @return Response
     */
    public function handle(Request $request): Response
    {
        $store = $this->store ?? DropStore::current();

        if ($store === null) {
            return new UnroutedController()->handle($request);
        }

        if ($request->isReadOnly()) {
            return self::page(new DropView());
        }

        if ($request->method() !== HttpMethod::Post) {
            return PlainTextResponse::refusing(
                HttpStatusCode::MethodNotAllowed,
                UnroutedController::refusal($request->language()),
                new Header(ResponseHeader::Allow, Allow::of(HttpMethod::Get, HttpMethod::Head, HttpMethod::Post)),
            );
        }

        $form     = $request->form();
        $page     = $form->flag(DropField::Page);
        $password = (string) $form->text(DropField::Password);
        $token    = DropToken::read(trim((string) $form->text(DropField::Token)));
        $counted  = DropPath::Index->value . ' ' . $request->remoteAddress();

        try {
            if ($this->throttle()->remaining($counted, $this->now) === 0) {
                return self::said($request, $page, DropText::Uncounted, HttpStatusCode::TooManyRequests, null, ...[
                    new Header(ResponseHeader::RetryAfter, new RetryAfter(self::WINDOW)),
                ]);
            }

            $opened = $token === null ? DropRefusal::Absent : $store->open($token, $password, $this->now);

            if ($opened instanceof OpenedDrop) {
                return self::revealed($opened, $page);
            }

            $verdict = $this->throttle()->attempt($counted, $this->now);
        } catch (ThrottleException) {
            return self::said($request, $page, DropText::Uncounted, HttpStatusCode::ServiceUnavailable);
        }

        $message = match (true) {
            !$verdict->allowed()             => DropText::Uncounted,
            $opened === DropRefusal::Absent  => DropText::Absent,
            $password === ''                 => DropText::NeedsPassword,
            default                          => DropText::WrongPassword,
        };

        return self::said(
            $request,
            $page,
            $message,
            $verdict->allowed() ? $opened->status() : HttpStatusCode::TooManyRequests,
            $opened === DropRefusal::Locked ? $token : null,
        );
    }

    /**
     * What $opened holds: text on a page to read it on, where the page asked — otherwise its bytes.
     *
     * @param OpenedDrop $opened
     * @param bool       $page
     * @return Response
     */
    private static function revealed(OpenedDrop $opened, bool $page): Response
    {
        $meta = $opened->meta;

        if ($meta->kind === DropKind::Text && $page) {
            $text = $opened->text();

            return $text === null
                ? self::page(new DropView(DropText::Absent), HttpStatusCode::NotFound)
                : self::page(new DropTextView($text, $opened->header->once));
        }

        return new StreamResponse(
            HttpStatusCode::Ok,
            $meta->kind === DropKind::Text ? MimeType::plainText() : MimeType::octetStream(),
            static fn(): iterable => $opened->chunks(),
            AdminHeaders::with(
                new Header(ResponseHeader::ContentLength, new ContentLength($meta->size)),
                new Header(
                    ResponseHeader::ContentDisposition,
                    $meta->name === null ? ContentDisposition::inline() : ContentDisposition::attachment($meta->name),
                ),
            ),
        );
    }

    /**
     * $message, as $status: on the page, where the page posted — with $token written back into it — and
     * as a line of text otherwise.
     *
     * @param Request        $request
     * @param bool           $page
     * @param Translatable   $message
     * @param HttpStatusCode $status
     * @param DropToken|null $token
     * @param Header         ...$headers
     * @return Response
     */
    private static function said(
        Request $request,
        bool $page,
        Translatable $message,
        HttpStatusCode $status,
        ?DropToken $token = null,
        Header ...$headers,
    ): Response {
        return $page
            ? self::page(new DropView($message, $token), $status, ...$headers)
            : PlainTextResponse::refusing($status, $message->in($request->language()) . "\n", ...$headers);
    }

    /**
     * $view, as `/drop` answers a page: kept by no cache, and not to be indexed.
     *
     * @param View           $view
     * @param HttpStatusCode $status
     * @param Header         ...$headers
     * @return Response
     */
    private static function page(View $view, HttpStatusCode $status = HttpStatusCode::Ok, Header ...$headers): Response
    {
        return new ViewResponse($view, $status, AdminHeaders::with(...$headers));
    }

    /**
     * @return Throttle
     */
    private function throttle(): Throttle
    {
        return $this->throttle ?? new Throttle(App::current()->throttles(), self::FAILURES, self::WINDOW);
    }
}
