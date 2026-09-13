<?php

declare(strict_types=1);

namespace Phpanta\Service;

use NoDiscard;
use Phpanta\Http\CacheControl;
use Phpanta\Http\Header;
use Phpanta\Http\HttpStatusCode;
use Phpanta\Http\PlainTextResponse;
use Phpanta\Http\Request;
use Phpanta\Http\Response;
use Phpanta\Http\ResponseHeader;
use Phpanta\Http\RetryAfter;
use Phpanta\Http\Session;
use Phpanta\Support\Collection;
use Phpanta\Support\PasswordHash;
use Phpanta\Support\Throttle;
use Phpanta\Text\FrameworkText;
use SensitiveParameter;

/**
 * The Login class. Whether a name and a password open a session — asked slowly, and not too often.
 *
 * A login form's controller asks this with what the form sent and the digest it keeps for that name,
 * or null where it keeps none, and answers with what comes back:
 *
 * ```php
 * $result = $login->attempt($request, $request->session(), $name, $password, $users->hash($name));
 *
 * return match (true) {
 *     $result instanceof Session  => $result->attachTo(new RedirectResponse(new Location('/'))),
 *     $result instanceof Response => $result,                    // too many attempts
 *     default                     => new ViewResponse(new LoginView(wrong: true), HttpStatusCode::Unauthorized),
 * };
 * ```
 *
 * **Every attempt pays for a comparison.** A name the site does not know is compared against
 * {@link PasswordHash::unmatchable()} — a real bcrypt digest nothing opens — so the time an answer
 * takes says nothing about whether the name exists. The same levelling {@link Auth::matches()} and a
 * site's demo gate do, for the same reason.
 *
 * **Every attempt is counted, by address and name**, against the {@link Throttle} the site gives it,
 * and once there have been too many the answer is a 429 with a `Retry-After`, whatever the password
 * — the right one included, or the limit would be an oracle. A successful login forgets that name's
 * count from that address. The name is counted case-folded, so `Ada` and `ada` share one count. A
 * site that also wants to cap one address across every name lists the
 * {@link \Phpanta\Service\Layer\RateLimit} layer on the login route as well.
 *
 * A login hands the session a new form token — see {@link Session::withUser()} — so a token written
 * into a page before it is worth nothing after it.
 */
final readonly class Login
{
    /**
     * Constructs an instance of {@link self}.
     *
     * @param Throttle $throttle How many attempts one address may make at one name, and in how long.
     */
    public function __construct(private Throttle $throttle) {}

    /**
     * $session logged in as $user, if $password opens $hash; null if it does not; a 429 if this address
     * has tried this name too often.
     *
     * @param Request           $request  The request the form arrived with — whose address is counted.
     * @param Session           $session  The visitor's session, to log in.
     * @param string            $user     The name the form sent.
     * @param string            $password The password the form sent.
     * @param PasswordHash|null $hash     The digest kept for that name, or null where the site keeps none.
     * @return Session|Response|null
     */
    #[NoDiscard(
        'attempt() decides a login and changes nothing the visitor sees; a call whose result goes nowhere '
        . 'logged nobody in',
    )]
    public function attempt(
        Request $request,
        Session $session,
        string $user,
        #[SensitiveParameter] string $password,
        ?PasswordHash $hash,
    ): Session|Response|null {
        $key     = 'login ' . $request->remoteAddress() . ' ' . mb_strtolower($user);
        $verdict = $this->throttle->attempt($key);

        if (!$verdict->allowed()) {
            return new PlainTextResponse(
                HttpStatusCode::TooManyRequests,
                FrameworkText::TooManyRequests->in($request->language()) . "\n",
                new Collection(Header::class)->with(
                    new Header(ResponseHeader::RetryAfter, new RetryAfter($verdict->retryAfter())),
                    new Header(ResponseHeader::CacheControl, CacheControl::doNotStore()),
                ),
            );
        }

        // Both, always: the comparison is paid whether or not the name is known.
        $matches = ($hash ?? PasswordHash::unmatchable())->matches($password);

        if ($hash === null || !$matches) {
            return null;
        }

        (void) $this->throttle->clear($key);

        return $session->withUser($user);
    }
}
