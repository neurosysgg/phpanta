<?php

declare(strict_types=1);

namespace Phpanta\Service;

use NoDiscard;
use Phpanta\App;
use Phpanta\CredentialFile;
use Phpanta\Http\BasicChallenge;
use Phpanta\Http\Header;
use Phpanta\Http\HttpStatusCode;
use Phpanta\Http\PlainTextResponse;
use Phpanta\Http\Request;
use Phpanta\Http\Response;
use Phpanta\Http\ResponseHeader;
use Phpanta\Support\Collection;
use Phpanta\Support\File;
use Phpanta\Support\PasswordHash;

/**
 * The Auth class. Provides HTTP Basic Authentication gates for an app.
 *
 * **A gate returns its refusal; it never ends the request.** {@link self::adminGate()} answers the
 * 401 as a {@link Response}, or null to let the request through, and the caller returns it — so a
 * refusal is a value a test can hold, and nothing but {@link \Phpanta\App::run()} sends anything. It
 * carries `#[\NoDiscard]`, because the one way to get this wrong is a call whose result goes
 * nowhere, and that is a door left open. The decision itself is {@link self::accepts()}, and the
 * gate is only the challenge around it.
 *
 * That split is what lets a test reach the comparison at all. An unconfigured `data/admin.php`
 * holds an empty `pass_hash`, so the guard short-circuits and neither `hash_equals()` nor
 * `password_verify()` is reached — which means an end-to-end check that an admin route answers 401
 * proves the route is gated, not that the comparison works.
 *
 * **One gate here, and any number built on it, differing in where the credential comes from rather
 * than in what is done with it.** The admin gate reads a `data/` file returning a user and a hash.
 * A site's own gate brings its own credential — a {@link PasswordHash} per protected item, say — and
 * asks {@link self::matches()} and {@link self::challenge()}, which are public for that reason. So
 * every gate still ends up in {@link self::matches()}, and that is still the only place a credential
 * is compared.
 *
 * **A Basic gate goes on a route, never around the app.** A request carries one `Authorization`, so
 * a Basic gate in front of every request would stand in front of the admin as well, and no signed
 * call could carry both the password and its signature.
 */
class Auth
{
    /**
     * True if $request carries the credentials $file holds.
     *
     * The admin gate asks it of `data/admin.php`, and a site's own gate may ask it of a file of its
     * own, so it is asked in one place. The comparison itself is {@link self::matches()}.
     *
     * @param Request $request The request whose Basic Auth credentials to check.
     * @param File    $file    A credentials file returning `['user' => …, 'pass_hash' => …]`.
     * @return bool
     */
    #[NoDiscard('this is the gate\'s decision and nothing else; dropping it is a door left open')]
    public static function accepts(Request $request, File $file): bool
    {
        // `require` is a language construct and takes a path: a credentials file is PHP this
        // executes, not bytes it reads, and File::read() is deliberately not a way to run one.
        /** @var array{user: string, pass_hash: string} $creds */
        $creds = require $file->path;

        // An empty hash is an unconfigured gate rather than one that accepts an empty password, and
        // PasswordHash::configured() is where that distinction now lives — it answers null for the
        // empty string and throws for a digest that is neither empty nor bcrypt. The guard stays
        // ahead of the comparison because there is no timing to protect on a gate that is not
        // configured: there is no right answer for the difference to be measured against.
        $hash = PasswordHash::configured($creds['pass_hash']);

        return $hash !== null && self::matches($request, $creds['user'], $hash);
    }

    /**
     * Compares a request's Basic Auth credentials against a user name and a hash.
     *
     * The one place a credential is checked, whichever gate asked — public so that a site's own
     * gate compares the same way rather than writing a second comparison. Every comparison is
     * constant-time: the password because that is what `password_verify()` is, the user name
     * because it is compared on every request just the same.
     *
     * **And neither is skipped when the other fails.** Chaining the two with `&&` made the pair
     * leak what each one individually does not: bcrypt is deliberately slow, so a wrong user name
     * came back in microseconds while a right one paid the full cost, and that difference is
     * measurable across a network. It tells an attacker which half of the credential they have
     * already got — which is the half they cannot otherwise find out, since the password is the one
     * a brute-force attempt gets feedback on. So both run, every time, and the results are combined
     * afterwards.
     *
     * @param Request      $request
     * @param string       $user The user name this gate expects.
     * @param PasswordHash $hash The digest to verify the password against.
     * @return bool
     */
    public static function matches(Request $request, string $user, PasswordHash $hash): bool
    {
        // Both, always. See the note above on why this is not one `&&` chain.
        $userMatches     = hash_equals($user, $request->authUser());
        $passwordMatches = $hash->matches($request->authPassword());

        return $userMatches && $passwordMatches;
    }

    /**
     * The admin gate: the 401 a request is refused with, or null to let it through.
     *
     * There is no absent-file case: a missing `data/admin.php` is a broken deployment, and
     * `require` says so loudly rather than leaving the admin routes open. The realm is the app's
     * name, encoded — see {@link BasicChallenge::encoding()}.
     *
     * A controller behind it returns the refusal as its own response —
     * `if (($refusal = Auth::adminGate($request)) !== null) { return $refusal; }` — and nothing
     * else stands between a dropped one and the page, which is what the attribute is for.
     *
     * @param Request   $request The incoming request.
     * @param File|null $file    The credentials file; defaults to `data/admin.php`.
     * @return Response|null
     */
    #[NoDiscard('the refusal is only sent if it is returned; dropping it is a door left open')]
    public static function adminGate(Request $request, ?File $file = null): ?Response
    {
        return self::accepts($request, $file ?? App::current()->dataFile(CredentialFile::Admin))
            ? null
            : self::challenge(BasicChallenge::encoding(App::current()->name()));
    }

    /**
     * The Basic Auth challenge: a 401 asking for credentials in $challenge's realm.
     *
     * Public for the reason {@link self::matches()} is: a site's own gate answers its 401 here, so
     * there is one way a challenge goes out. It has no body — the browser's prompt is the whole of
     * what a visitor sees.
     *
     * @param BasicChallenge $challenge The realm to prompt in — the site's, or one a site's own gate names.
     * @return Response
     */
    #[NoDiscard('the 401 is only sent if it is returned; dropping it is a door left open')]
    public static function challenge(BasicChallenge $challenge): Response
    {
        return new PlainTextResponse(
            HttpStatusCode::Unauthorized,
            '',
            new Collection(Header::class)->with(new Header(ResponseHeader::WwwAuthenticate, $challenge)),
        );
    }
}
