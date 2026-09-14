<?php

declare(strict_types=1);

namespace Phpanta\Http;

use BackedEnum;
use JsonException;
use NoDiscard;
use Phpanta\Exception\SessionException;
use Phpanta\Model\Passkey\Challenge;
use Phpanta\Support\BareString;
use Phpanta\Support\Collection;
use Phpanta\Support\SearchableCollection;
use Phpanta\Text\Translatable;

/**
 * The Session class. What a visitor carries from one request to the next: a few values by
 * {@link SessionKey}, who they are logged in as, the token their forms must send back, and a message
 * for the next page.
 *
 * **Kept in the visitor's own cookie, sealed.** There is no session store on the host — a shared host
 * gives a site no process to keep one in, and a store is one more thing to lose — so the whole
 * session goes into `__Host-session`, sealed by {@link SessionSeal}: the visitor holds it, and can
 * neither read nor change it. `__Host-` makes the browser insist on `Secure`, `Path=/` and no
 * `Domain`, so no other host under the same domain can set it; `HttpOnly` keeps it from scripts;
 * `SameSite=Lax` keeps it off another site's `POST`.
 *
 * **Immutable, like everything that crosses a boundary here.** `with()` copies, and a changed session
 * reaches the visitor only by being attached to the answer — {@link self::attachTo()}. A page that
 * reads the session and changes nothing attaches nothing.
 *
 * **It expires.** A session is kept for {@link self::LIFETIME} after the last answer that carried it,
 * and one kept past that opens as no session at all.
 *
 * Small on purpose: a cookie is at most about four kilobytes, and a session sealed larger than
 * {@link self::MAX_SEALED} refuses to be attached rather than being cut. It holds keys, not content.
 */
#[BareString(
    'string',
    'the declared type of the collections this holds: values and message references, which are '
    . 'strings. The same scalar-in-a-class-string coincidence Route, Vocabulary and TypedItems excuse.',
)]
final readonly class Session
{
    /** How long a session is kept after the last answer that carried it: two weeks, in seconds. */
    public const int LIFETIME = 1_209_600;

    /** The longest a sealed session may be — comfortably inside what every browser keeps in one cookie. */
    public const int MAX_SEALED = 3800;

    /** The framework's own keys begin with this, and a site's may not. */
    private const string RESERVED = '_';

    /** Who the visitor is logged in as. */
    private const string USER = '_user';

    /** The token a form must send back — see {@link \Phpanta\Service\Layer\CsrfGuard}. */
    private const string TOKEN = '_token';

    /** Where the framework keeps the admin's unlock: when it happened, and with which passkey. */
    private const string ADMIN = '_admin';

    /** Where the framework keeps a challenge a page handed out, until it is answered. */
    private const string CHALLENGE = '_challenge';

    /**
     * How long an unlock of the admin lasts, in seconds: eight hours, however long the cookie that
     * carries it is kept. The admin is a working session rather than a place to stay signed in to.
     */
    public const int ADMIN_LIFETIME = 28_800;

    /**
     * @param SessionSeal                  $seal     What this session is sealed with on its way out.
     * @param SearchableCollection<string> $values   Everything kept, by key.
     * @param Collection<string>           $messages The messages for the next page, as `Class::value`.
     */
    private function __construct(
        private SessionSeal          $seal,
        private SearchableCollection $values,
        private Collection           $messages,
    ) {}

    /**
     * A session with nothing in it, to be sealed with $seal.
     *
     * @param SessionSeal $seal
     * @return self
     */
    public static function fresh(SessionSeal $seal): self
    {
        return new self($seal, new SearchableCollection('string'), new Collection('string'));
    }

    /**
     * The session $request carried, opened with $seal — or a fresh one, where it carried none, or
     * one that does not open, or one kept past its expiry.
     *
     * @param Request     $request
     * @param SessionSeal $seal
     * @param int|null    $now     The time to judge expiry at; a test seam.
     * @return self
     */
    public static function of(Request $request, SessionSeal $seal, ?int $now = null): self
    {
        $sealed  = $request->cookies()->value(CookieName::Session);
        $opened  = $sealed === null ? null : $seal->open($sealed, SealContext::Session);
        $session = self::fresh($seal);

        if ($opened === null) {
            return $session;
        }

        try {
            $payload = json_decode($opened, true, 4, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return $session;
        }

        // Sealed by this class under this key, so the shape is ours — but read as if it were not,
        // since a key shared by mistake with something else would otherwise be trusted for it.
        if (!is_array($payload) || !is_int($payload['e'] ?? null) || $payload['e'] < ($now ?? time())) {
            return $session;
        }

        foreach (is_array($payload['v'] ?? null) ? $payload['v'] : [] as $key => $value) {
            if (is_string($value)) {
                $session = new self($seal, $session->values->with((string) $key, $value), $session->messages);
            }
        }

        foreach (is_array($payload['m'] ?? null) ? $payload['m'] : [] as $message) {
            if (is_string($message)) {
                $session = new self($seal, $session->values, $session->messages->with($message));
            }
        }

        return $session;
    }

    // ───────────────────────── what a site keeps ─────────────────────────

    /**
     * The value kept under $key, or null.
     *
     * @param SessionKey $key
     * @return string|null
     */
    #[NoDiscard('get() only reads; a call whose result goes nowhere read nothing')]
    public function get(SessionKey $key): ?string
    {
        return $this->values->find(self::key($key));
    }

    /**
     * This session with $value kept under $key.
     *
     * @param SessionKey $key
     * @param string     $value
     * @return self
     * @throws SessionException if the key begins with `_`, where the framework keeps its own.
     */
    #[NoDiscard('with() copies rather than keeps, so a call whose result goes nowhere keeps nothing')]
    public function with(SessionKey $key, string $value): self
    {
        return $this->withValue(self::key($key), $value);
    }

    /**
     * This session without $key.
     *
     * @param SessionKey $key
     * @return self
     */
    #[NoDiscard('without() copies rather than forgets, so a call whose result goes nowhere forgets nothing')]
    public function without(SessionKey $key): self
    {
        return $this->withoutValue(self::key($key));
    }

    // ───────────────────────── who the visitor is ─────────────────────────

    /**
     * Who the visitor is logged in as, or null.
     *
     * @return string|null
     */
    #[NoDiscard('user() only reads; a call whose result goes nowhere read nothing')]
    public function user(): ?string
    {
        return $this->values->find(self::USER);
    }

    /**
     * This session logged in as $user — and with a new form token, so a token a page handed out before
     * the login is worth nothing after it.
     *
     * @param string $user
     * @return self
     */
    #[NoDiscard('withUser() copies rather than logs in, so a call whose result goes nowhere logged nobody in')]
    public function withUser(string $user): self
    {
        return $this->withValue(self::USER, $user)->withNewToken();
    }

    /**
     * This session logged out — and with everything else it kept forgotten, since what a logged-in
     * visitor kept is theirs and not the next person's at the same browser.
     *
     * @return self
     */
    #[NoDiscard('withoutUser() copies rather than logs out, so a call whose result goes nowhere logged nobody out')]
    public function withoutUser(): self
    {
        return self::fresh($this->seal);
    }

    // ───────────────────────── the form token ─────────────────────────

    /**
     * The token a form on this visitor's page must send back, or null where none has been handed out.
     *
     * @return string|null
     */
    #[NoDiscard('token() only reads; a call whose result goes nowhere read nothing')]
    public function token(): ?string
    {
        return $this->values->find(self::TOKEN);
    }

    /**
     * This session with a token for its forms — the one it has, or a new one where it has none.
     *
     * A page that renders a form asks this, writes {@link self::token()} into the form, and attaches
     * the session to its answer.
     *
     * @return self
     */
    #[NoDiscard('withToken() copies rather than keeps, so a call whose result goes nowhere hands out nothing')]
    public function withToken(): self
    {
        return $this->token() === null ? $this->withNewToken() : $this;
    }

    // ───────────────────────── the admin ─────────────────────────

    /**
     * The passkey this session unlocked the admin with, if it did so less than
     * {@link self::ADMIN_LIFETIME} seconds before $now — or null.
     *
     * @param int|null $now The time to judge the unlock's age at; a test seam.
     * @return string|null The passkey's credential id.
     */
    #[NoDiscard('admin() only reads; a call whose result goes nowhere read nothing')]
    public function admin(?int $now = null): ?string
    {
        [$since, $credential] = array_pad(explode(' ', $this->values->find(self::ADMIN) ?? '', 2), 2, '');

        return preg_match(Input::WHOLE_NUMBER, $since) === 1
            && ($now ?? time()) - (int) $since <= self::ADMIN_LIFETIME
            && $credential !== ''
            ? $credential
            : null;
    }

    /**
     * When this session unlocked the admin, or null where it did not — however long ago; whether the
     * unlock is still good is {@link self::admin()}'s question.
     *
     * @return int|null
     */
    #[NoDiscard('adminSince() only reads; a call whose result goes nowhere read nothing')]
    public function adminSince(): ?int
    {
        [$since] = explode(' ', $this->values->find(self::ADMIN) ?? '', 2);

        return preg_match(Input::WHOLE_NUMBER, $since) === 1 ? (int) $since : null;
    }

    /**
     * This session with the admin unlocked by the passkey $credential, at $now — with a new form token,
     * for {@link self::withUser()}'s reason, and without the challenge the unlock answered, so it is
     * spent.
     *
     * @param string   $credential
     * @param int|null $now
     * @return self
     */
    #[NoDiscard('withAdmin() copies rather than unlocks, so a call whose result goes nowhere unlocked nothing')]
    public function withAdmin(string $credential, ?int $now = null): self
    {
        return $this->withValue(self::ADMIN, ($now ?? time()) . ' ' . $credential)
            ->withoutValue(self::CHALLENGE)
            ->withNewToken();
    }

    /**
     * This session with the admin locked again — and everything else it kept forgotten, for
     * {@link self::withoutUser()}'s reason.
     *
     * @return self
     */
    #[NoDiscard('withoutAdmin() copies rather than locks, so a call whose result goes nowhere locked nothing')]
    public function withoutAdmin(): self
    {
        return self::fresh($this->seal);
    }

    /**
     * The challenge this session's page handed out, or null where it handed out none.
     *
     * @return Challenge|null
     */
    #[NoDiscard('challenge() only reads; a call whose result goes nowhere read nothing')]
    public function challenge(): ?Challenge
    {
        $stored = $this->values->find(self::CHALLENGE);

        return $stored === null ? null : Challenge::fromStored($stored);
    }

    /**
     * This session holding $challenge until it is answered — one at a time, so a newer page's
     * challenge replaces an older one's.
     *
     * @param Challenge $challenge
     * @return self
     */
    #[NoDiscard('withChallenge() copies rather than keeps, so a call whose result goes nowhere handed out nothing')]
    public function withChallenge(Challenge $challenge): self
    {
        return $this->withValue(self::CHALLENGE, $challenge->stored());
    }

    /**
     * This session with its challenge spent.
     *
     * @return self
     */
    #[NoDiscard('withoutChallenge() copies rather than spends, so a call whose result goes nowhere spent nothing')]
    public function withoutChallenge(): self
    {
        return $this->withoutValue(self::CHALLENGE);
    }

    // ───────────────────────── a message for the next page ─────────────────────────

    /**
     * This session carrying $message to the next page — "Saved.", "You are logged out." — which shows
     * it once and attaches the session {@link self::withoutMessages()}.
     *
     * A catalog case rather than a string, so the message is in the visitor's language on the page
     * that shows it, whichever language the page that set it was in.
     *
     * @param Translatable&BackedEnum $message
     * @return self
     */
    #[NoDiscard('withMessage() copies rather than keeps, so a call whose result goes nowhere says nothing')]
    public function withMessage(Translatable&BackedEnum $message): self
    {
        return new self($this->seal, $this->values, $this->messages->with($message::class . '::' . $message->value));
    }

    /**
     * The messages this session carries for this page, in the order they were left.
     *
     * One that no longer names a catalog case — a case renamed since it was left — is skipped
     * rather than shown as its key.
     *
     * @return Collection<Translatable>
     */
    #[NoDiscard('messages() only reads; a call whose result goes nowhere read nothing')]
    public function messages(): Collection
    {
        $messages = new Collection(Translatable::class);

        foreach ($this->messages as $reference) {
            [$class, $value] = array_pad(explode('::', $reference, 2), 2, '');

            $message = enum_exists($class) && is_subclass_of($class, Translatable::class)
                ? $class::tryFrom($value)
                : null;

            if ($message instanceof Translatable) {
                $messages = $messages->with($message);
            }
        }

        return $messages;
    }

    /**
     * This session with its messages shown — what the page that shows them attaches.
     *
     * @return self
     */
    #[NoDiscard('withoutMessages() copies rather than forgets, so a call whose result goes nowhere forgets nothing')]
    public function withoutMessages(): self
    {
        return new self($this->seal, $this->values, new Collection('string'));
    }

    // ───────────────────────── on the way out ─────────────────────────

    /**
     * $response, with this session sealed into the cookie that carries it — kept for
     * {@link self::LIFETIME} from now.
     *
     * @param Response $response
     * @param int|null $now      The time the lifetime runs from; a test seam.
     * @return Response
     * @throws SessionException if the sealed session would not fit in a cookie.
     */
    #[NoDiscard(
        'attachTo() wraps the response rather than sending it, so a call whose result goes nowhere kept nothing',
    )]
    public function attachTo(Response $response, ?int $now = null): Response
    {
        $sealed = $this->seal->seal((string) json_encode([
            'e' => ($now ?? time()) + self::LIFETIME,
            'v' => $this->values->toArray(),
            'm' => $this->messages->toValues(),
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), SealContext::Session);

        if (strlen($sealed) > self::MAX_SEALED) {
            throw new SessionException(sprintf(
                'A session sealed to %d bytes does not fit in a cookie; keep keys in it, not content.',
                strlen($sealed),
            ));
        }

        return new WithHeaders(
            $response,
            new Collection(Header::class)->with(new Header(ResponseHeader::SetCookie, SetCookie::session($sealed))),
        );
    }

    /**
     * $response, with the session's cookie expired — the visitor has no session after it.
     *
     * @param Response $response
     * @return Response
     */
    #[NoDiscard('endOn() wraps the response rather than sending it, so a call whose result goes nowhere ended nothing')]
    public static function endOn(Response $response): Response
    {
        return new WithHeaders(
            $response,
            new Collection(Header::class)->with(
                new Header(ResponseHeader::SetCookie, SetCookie::expired(CookieName::Session)),
            ),
        );
    }

    /**
     * @return self
     */
    private function withNewToken(): self
    {
        return $this->withValue(self::TOKEN, bin2hex(random_bytes(32)));
    }

    /**
     * @param string $key
     * @param string $value
     * @return self
     */
    private function withValue(string $key, string $value): self
    {
        return new self($this->seal, $this->values->with($key, $value), $this->messages);
    }

    /**
     * @param string $key
     * @return self
     */
    private function withoutValue(string $key): self
    {
        $kept = new SearchableCollection('string');

        foreach ($this->values->toArray() as $name => $value) {
            if ((string) $name !== $key) {
                $kept = $kept->with((string) $name, $value);
            }
        }

        return new self($this->seal, $kept, $this->messages);
    }

    /**
     * A site's key, refused where it would collide with the framework's own.
     *
     * @param SessionKey $key
     * @return string
     * @throws SessionException if it begins with `_`.
     */
    private static function key(SessionKey $key): string
    {
        $name = (string) $key->value;

        if (str_starts_with($name, self::RESERVED)) {
            throw new SessionException(sprintf(
                "'%s' begins with '%s', where the framework keeps its own session values.",
                $name,
                self::RESERVED,
            ));
        }

        return $name;
    }
}
