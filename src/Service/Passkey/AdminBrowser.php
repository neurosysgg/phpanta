<?php

declare(strict_types=1);

namespace Phpanta\Service\Passkey;

use Phpanta\App;
use Phpanta\Exception\InputException;
use Phpanta\Exception\SessionException;
use Phpanta\Exception\ThrottleException;
use Phpanta\Http\Api\AdminHeaders;
use Phpanta\Http\Api\ApiAction;
use Phpanta\Http\CookieName;
use Phpanta\Http\CsrfField;
use Phpanta\Http\Header;
use Phpanta\Http\HttpMethod;
use Phpanta\Http\HttpStatusCode;
use Phpanta\Http\Input;
use Phpanta\Http\Location;
use Phpanta\Http\Origin;
use Phpanta\Http\PasskeyFormField;
use Phpanta\Http\RedirectResponse;
use Phpanta\Http\Request;
use Phpanta\Http\Response;
use Phpanta\Http\ResponseHeader;
use Phpanta\Http\RetryAfter;
use Phpanta\Http\Session;
use Phpanta\Http\SessionSeal;
use Phpanta\Http\ViewResponse;
use Phpanta\Model\Api\ApiEnvelope;
use Phpanta\Model\Api\VerifiedRequest;
use Phpanta\Model\Passkey\Challenge;
use Phpanta\Model\Passkey\ChallengePurpose;
use Phpanta\Model\Passkey\EnrolmentCode;
use Phpanta\Model\Passkey\EntranceCeremony;
use Phpanta\Model\Passkey\Passkey;
use Phpanta\Support\AdminPath;
use Phpanta\Support\Base64Url;
use Phpanta\Support\Collection;
use Phpanta\Support\Throttle;
use Phpanta\Text\AdminText;
use Phpanta\Text\FrameworkText;
use Phpanta\Text\Translatable;
use Phpanta\View\AdminEnrolmentView;
use Phpanta\View\AdminEntranceView;
use Phpanta\View\ApiActionFormView;
use Phpanta\View\View;
use stdClass;

/**
 * The AdminBrowser class. The admin, for a browser: which browser it has let in, the entrance's three
 * ceremonies, and a write a passkey answers.
 *
 * **A browser cannot carry `NS1`** — a navigation sends no header a page chose — so a browser is let
 * in by a sealed session, unlocked at the entrance with an enrolled passkey and good for
 * {@link Session::ADMIN_LIFETIME}. Past that it may do what the signing key may, less what an action
 * keeps for the key alone ({@link ApiAction::fromBrowser()}); and a write needs a fresh answer from
 * the passkey that unlocked the session, over a challenge minted for that one address and method.
 *
 * **The one reader of a form under `/admin`.** An action's handler reads a manifest. A signed one comes
 * out of the credential; a browser's is built here, out of the fields the action declares and nothing
 * else, so what a handler reads has the same shape whichever door it came through.
 *
 * **Off unless the deployment says where it is.** A passkey is bound to an origin, and the origin is
 * the app's ({@link App::origin()}), never the `Host` a request names. An app that names none lets no
 * browser in. In development and from loopback only, the origin the request says it comes from comes
 * first — so a local copy runs a real ceremony at the address it is served on, whatever public origin
 * the app names. With no session key, no browser is let in either.
 */
final readonly class AdminBrowser
{
    /** How many posts to the entrance one address may make in a window. */
    private const int ATTEMPTS = 10;

    /** That window, in seconds: fifteen minutes. */
    private const int WINDOW = 900;

    /** Where the entrance counts its attempts, in the deployment's data directory. */
    private const string THROTTLE = 'throttle';

    /**
     * Constructs an instance of {@link self}. Every argument is a test seam; null is the deployment's own.
     *
     * @param SessionSeal|null     $seal     What sessions are sealed with.
     * @param PasskeyRegistry|null $registry The enrolled devices.
     * @param Throttle|null        $throttle What the entrance counts attempts against.
     * @param Origin|null          $origin   Where the admin is served.
     */
    public function __construct(
        private ?SessionSeal     $seal = null,
        private ?PasskeyRegistry $registry = null,
        private ?Throttle        $throttle = null,
        private ?Origin          $origin = null,
    ) {}

    /**
     * The browser $request comes from, if the admin has let it in: a session unlocked less than
     * {@link Session::ADMIN_LIFETIME} ago, by a passkey that is still enrolled.
     *
     * @param Request  $request
     * @param int|null $now
     * @return AdminCaller|null
     */
    public function caller(Request $request, ?int $now = null): ?AdminCaller
    {
        $seal = $request->cookies()->value(CookieName::Session) === null ? null : $this->seal();

        if ($seal === null) {
            return null;
        }

        $session    = Session::of($request, $seal, $now);
        $credential = $session->admin($now);
        $passkey    = $credential === null ? null : $this->registry()->find($credential);

        return $passkey === null ? null : new AdminCaller($passkey, $session);
    }

    /**
     * The entrance: a read is the page, a post one of its ceremonies, anything else sent back to it.
     *
     * The page offers both ceremonies over one challenge where this deployment lets a browser in, and
     * says that it does not otherwise. A post is counted against the sender's address before anything
     * is read, and carries the form token the page handed out.
     *
     * @param Request  $request
     * @param int|null $now
     * @return Response
     */
    public function entrance(Request $request, ?int $now = null): Response
    {
        $now ??= time();
        $seal  = $this->offers($request) ? $this->seal() : null;

        if ($request->isReadOnly()) {
            return $seal === null
                ? self::page(new AdminEntranceView(self::said(AdminText::PasskeysOff)))
                : $this->door(Session::of($request, $seal, $now), $now);
        }

        return $seal === null || $request->method() !== HttpMethod::Post
            ? self::toEntrance()
            : $this->ceremony($request, Session::of($request, $seal, $now), $seal, $now);
    }

    /**
     * A write's form, for a browser the admin has let in, holding a challenge minted for that write.
     *
     * @param AdminCaller $caller
     * @param ApiAction   $action
     * @param string      $path   The write's address.
     * @param int|null    $now
     * @return Response
     */
    public function writeForm(AdminCaller $caller, ApiAction $action, string $path, ?int $now = null): Response
    {
        $now     ??= time();
        $challenge = Challenge::mint(ChallengePurpose::Write, $now, self::bound($path));
        $session   = $caller->session->withToken()->withChallenge($challenge);

        return $session->attachTo(
            self::page(new ApiActionFormView($action, $path, (string) $session->token(), $challenge->value)),
            $now,
        );
    }

    /**
     * A browser's read, as a handler takes it: where it was asked, and nothing else.
     *
     * @param Request  $request
     * @param int|null $now
     * @return VerifiedRequest
     */
    public function read(Request $request, ?int $now = null): VerifiedRequest
    {
        return new VerifiedRequest(
            ApiEnvelope::of($now ?? time(), HttpMethod::Get, $request->path()),
            (string) json_encode(new stdClass()),
            '',
        );
    }

    /**
     * A browser's write, as a handler takes it — if the form carries the session's token and the
     * unlocking passkey's answer to the challenge minted for this address. The challenge is spent
     * either way.
     *
     * Its serial is the time, as a signing command's is, and spent the same way: a browser's write and
     * a push take the same lock, and neither can follow the other within the same second.
     *
     * @param Request     $request
     * @param AdminCaller $caller
     * @param ApiAction   $action
     * @param int|null    $now
     * @return BrowserRequest
     */
    public function write(Request $request, AdminCaller $caller, ApiAction $action, ?int $now = null): BrowserRequest
    {
        $now ??= time();
        $path  = $request->path();
        $spent = $caller->session->withoutChallenge();

        try {
            $form     = $request->form();
            $answered = $this->tokened($form, $caller->session)
                && $form->text(PasskeyFormField::Credential) === $caller->passkey->id
                && $this->answered($request, $form, $caller->passkey, $caller->session, self::bound($path), $now);
            $fields   = $answered ? self::fields($form, $action) : null;
        } catch (InputException) {
            return new BrowserRequest(null, $spent);
        }

        return new BrowserRequest(
            $fields === null ? null : new VerifiedRequest(
                ApiEnvelope::of($now, HttpMethod::Post, $path),
                (string) json_encode($fields, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                '',
            ),
            $spent,
        );
    }

    /**
     * The entrance page, with its forms and a new challenge — and the messages the last post left,
     * shown once.
     *
     * @param Session $session
     * @param int     $now
     * @return Response
     */
    private function door(Session $session, int $now): Response
    {
        $challenge = Challenge::mint(ChallengePurpose::Entrance, $now);
        $shown     = $session->withToken()->withChallenge($challenge);

        return $shown->withoutMessages()->attachTo(
            self::page(new AdminEntranceView($session->messages(), $shown->token(), $challenge->value)),
            $now,
        );
    }

    /**
     * One post to the entrance: counted, checked for the form token, then the ceremony it names.
     *
     * @param Request     $request
     * @param Session     $session
     * @param SessionSeal $seal
     * @param int         $now
     * @return Response
     */
    private function ceremony(Request $request, Session $session, SessionSeal $seal, int $now): Response
    {
        try {
            $verdict = $this->throttle()->attempt($request->remoteAddress(), $now);
        } catch (ThrottleException) {
            return self::page(
                new AdminEntranceView(self::said(AdminText::EntranceUncounted)),
                HttpStatusCode::ServiceUnavailable,
            );
        }

        if (!$verdict->allowed()) {
            return self::page(
                new AdminEntranceView(self::said(FrameworkText::TooManyRequests)),
                HttpStatusCode::TooManyRequests,
                new Header(ResponseHeader::RetryAfter, new RetryAfter($verdict->retryAfter())),
            );
        }

        try {
            $form = $request->form();

            if (!$this->tokened($form, $session)) {
                return self::toEntrance();
            }

            return match ($form->choice(PasskeyFormField::Ceremony, EntranceCeremony::class)) {
                EntranceCeremony::Unlock   => $this->unlock($request, $form, $session, $now),
                EntranceCeremony::Register => $this->register($request, $form, $session, $seal, $now),
                EntranceCeremony::Logout   => Session::endOn(self::toEntrance()),
                default                    => self::toEntrance(),
            };
        } catch (InputException) {
            return self::toEntrance();
        }
    }

    /**
     * An unlock: the session let in, where an enrolled passkey answered the entrance's challenge.
     *
     * @param Request $request
     * @param Input   $form
     * @param Session $session
     * @param int     $now
     * @return Response
     */
    private function unlock(Request $request, Input $form, Session $session, int $now): Response
    {
        $passkey = $this->registry()->find((string) $form->text(PasskeyFormField::Credential));

        if ($passkey !== null && $this->answered($request, $form, $passkey, $session, '', $now)) {
            return $session->withAdmin($passkey->id, $now)->attachTo(self::toEntrance(), $now);
        }

        return $session->withoutChallenge()->withMessage(AdminText::UnlockRefused)->attachTo(self::toEntrance(), $now);
    }

    /**
     * A registration: an enrolment code for the key, where the device registered over the entrance's
     * challenge. Nothing is stored — the code is what the signing key enrols.
     *
     * @param Request     $request
     * @param Input       $form
     * @param Session     $session
     * @param SessionSeal $seal
     * @param int         $now
     * @return Response
     */
    private function register(Request $request, Input $form, Session $session, SessionSeal $seal, int $now): Response
    {
        $code  = $this->registered($request, $form, $session, $now);
        $spent = $session->withoutChallenge();

        return $code === null
            ? $spent->withMessage(AdminText::RegistrationRefused)->attachTo(self::toEntrance(), $now)
            : $spent->attachTo(self::page(new AdminEnrolmentView($code->seal($seal), $code->fingerprint())), $now);
    }

    /**
     * The enrolment code a registration earns, or null where it is not one over the entrance's challenge.
     *
     * @param Request $request
     * @param Input   $form
     * @param Session $session
     * @param int     $now
     * @return EnrolmentCode|null
     */
    private function registered(Request $request, Input $form, Session $session, int $now): ?EnrolmentCode
    {
        $credential = (string) $form->text(PasskeyFormField::Credential);
        $challenge  = $session->challenge();
        $origin     = $this->originOf($request);
        $data       = self::bytes($form, PasskeyFormField::AuthenticatorData);
        $client     = self::bytes($form, PasskeyFormField::ClientData);
        $key        = self::bytes($form, PasskeyFormField::Key);

        if ($challenge === null || $origin === null || $data === null || $client === null || $key === null) {
            return null;
        }

        return $credential !== ''
            && Base64Url::decode($credential) !== null
            && $challenge->expects(ChallengePurpose::Entrance, $now)
            && new PasskeyVerifier($origin)->registers($data, $client, $key, $challenge->value)
            ? new EnrolmentCode($credential, $key, $now)
            : null;
    }

    /**
     * Whether $passkey answered the challenge $session holds — the entrance's where $bound is empty,
     * a write's at $bound otherwise — and, where the passkey counts, its new count was kept, so the
     * same count again is refused.
     *
     * @param Request $request
     * @param Input   $form
     * @param Passkey $passkey
     * @param Session $session
     * @param string  $bound
     * @param int     $now
     * @return bool
     */
    private function answered(
        Request $request,
        Input $form,
        Passkey $passkey,
        Session $session,
        string $bound,
        int $now,
    ): bool {
        $purpose   = $bound === '' ? ChallengePurpose::Entrance : ChallengePurpose::Write;
        $challenge = $session->challenge();
        $origin    = $this->originOf($request);
        $data      = self::bytes($form, PasskeyFormField::AuthenticatorData);
        $client    = self::bytes($form, PasskeyFormField::ClientData);
        $signature = self::bytes($form, PasskeyFormField::Signature);

        if ($challenge === null || $origin === null || $data === null || $client === null || $signature === null) {
            return false;
        }

        $count = $challenge->expects($purpose, $now, $bound)
            ? new PasskeyVerifier($origin)->asserts($passkey, $data, $client, $signature, $challenge->value)
            : null;

        return $count !== null && ($count === $passkey->count || $this->registry()->keep($passkey->counted($count)));
    }

    /**
     * Whether $form carries the token $session handed out.
     *
     * @param Input   $form
     * @param Session $session
     * @return bool
     * @throws InputException if the token was sent more than once.
     */
    private function tokened(Input $form, Session $session): bool
    {
        $expected = $session->token();
        $sent     = $form->text(CsrfField::Token);

        return $expected !== null && $sent !== null && hash_equals($expected, $sent);
    }

    /**
     * The manifest a browser's write stands for: each field $action declares, read from $form — a yes
     * or a no for a flag, text otherwise, and nothing it does not declare.
     *
     * @param Input     $form
     * @param ApiAction $action
     * @return stdClass
     * @throws InputException if a field was sent more than once, or a flag as neither a yes nor a no.
     */
    private static function fields(Input $form, ApiAction $action): stdClass
    {
        $fields = new stdClass();

        foreach ($action->fields() as $field) {
            $fields->{$field->value} = $field->isFlag() ? $form->flag($field) : (string) $form->text($field);
        }

        return $fields;
    }

    /**
     * $field's bytes, sent as base64url — or null where it was not sent, or is not base64url.
     *
     * @param Input            $form
     * @param PasskeyFormField $field
     * @return string|null
     */
    private static function bytes(Input $form, PasskeyFormField $field): ?string
    {
        $sent = $form->text($field);

        return $sent === null ? null : Base64Url::decode($sent);
    }

    /**
     * Whether this deployment lets a browser in at all, as far as where it is goes.
     *
     * @param Request $request
     * @return bool
     */
    private function offers(Request $request): bool
    {
        return $this->origin !== null
            || App::current()->origin() !== null
            || App::current()->environment()->showsFaultsTo($request);
    }

    /**
     * The origin a ceremony must have run on: the app's — except in development and from loopback,
     * where the request's own comes first, so a local copy of a site that names its public origin
     * still runs a real ceremony at the address it is served on.
     *
     * @param Request $request
     * @return Origin|null
     */
    private function originOf(Request $request): ?Origin
    {
        $app = App::current();

        return ($app->environment()->showsFaultsTo($request) ? $request->origin() : null)
            ?? $this->origin
            ?? $app->origin();
    }

    /**
     * The deployment's session seal — or null where it has no session key, which lets no browser in.
     *
     * @return SessionSeal|null
     */
    private function seal(): ?SessionSeal
    {
        try {
            return $this->seal ?? App::current()->sessionSeal();
        } catch (SessionException) {
            return null;
        }
    }

    /**
     * @return PasskeyRegistry
     */
    private function registry(): PasskeyRegistry
    {
        return $this->registry ?? new PasskeyRegistry();
    }

    /**
     * @return Throttle
     */
    private function throttle(): Throttle
    {
        return $this->throttle
            ?? new Throttle(App::current()->data()->directory(self::THROTTLE), self::ATTEMPTS, self::WINDOW);
    }

    /**
     * $view, as the admin answers a page.
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
     * Back to the entrance, which says why where there is something to say.
     *
     * @return Response
     */
    private static function toEntrance(): Response
    {
        return new RedirectResponse(
            new Location(AdminPath::Index->to()),
            HttpStatusCode::SeeOther,
            AdminHeaders::with(),
        );
    }

    /**
     * One thing to say.
     *
     * @param Translatable $message
     * @return Collection<Translatable>
     */
    private static function said(Translatable $message): Collection
    {
        return new Collection(Translatable::class)->with($message);
    }

    /**
     * What a write's challenge is bound to: the method and the address.
     *
     * @param string $path
     * @return string
     */
    private static function bound(string $path): string
    {
        return HttpMethod::Post->value . ' ' . $path;
    }
}
