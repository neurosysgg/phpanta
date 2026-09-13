<?php

declare(strict_types=1);

namespace Phpanta\Service;

use NoDiscard;
use Phpanta\App;
use Phpanta\CredentialFile;
use Phpanta\Http\BasicChallenge;
use Phpanta\Http\Header;
use Phpanta\Http\HttpStatusCode;
use Phpanta\Http\Request;
use Phpanta\Http\ResponseHeader;
use Phpanta\Support\File;
use Phpanta\Support\PasswordHash;

/**
 * The Auth class. Provides HTTP Basic Authentication gates for the site.
 *
 * The decision and the 401 are separate, the same way {@link \Phpanta\Http\SecurityHeaders}
 * separates `headers()` from `send()`, and for the same reason: a gate that ends the request
 * cannot be asserted against in-process, so everything worth asserting lives in
 * {@link self::accepts()}, and the two `require*` methods are only the challenge around it.
 *
 * That split is what the {@link \NeuroSYS\Test\Unit\AdminTest} needs to exist. Before it, the
 * credential comparison had never run under either suite: `data/admin.php` ships with an empty
 * `pass_hash`, so the guard short-circuits and neither `hash_equals()` nor `password_verify()`
 * is reached — which means the two `/admin/stats → 401` checks in `test/basic_test.sh` prove the
 * route is gated, not that the comparison works.
 *
 * **Two gates here, and any number built on them, differing in where the credential comes from
 * rather than in what is done with it.** The site gate and the admin gate read a `data/` file
 * returning a user and a hash. A site's own gate brings its own credential — this one's
 * {@link DemoGate} carries a {@link PasswordHash} per demo — and asks {@link self::matches()} and
 * {@link self::challenge()}, which are public for that reason. So every gate still ends up in
 * {@link self::matches()}, and that is still the only place a credential is compared.
 */
class Auth
{
    /**
     * The challenge the two file-backed gates answer a 401 with.
     *
     * One value rather than the same one built twice: the browser keys stored credentials by realm,
     * so two challenges differing by a character are two separate prompts to the same visitor.
     * {@link BasicChallenge} owns the quoting around the realm, which is grammar rather than
     * decoration.
     *
     * @return BasicChallenge
     */
    private static function challengeValue(): BasicChallenge
    {
        return new BasicChallenge(App::current()->name());
    }

    /**
     * True if $request carries the credentials $file holds.
     *
     * Both file-backed gates ask the same question of the same shape of file, so they ask it in one
     * place. The comparison itself is {@link self::matches()}.
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
     * Enforces site-wide pre-launch authentication if a credentials file exists.
     *
     * Exits with a 401 if the credentials are wrong. Does nothing at all if the credentials file
     * is absent — that absence is how pre-launch auth is switched off, and `data/site_auth.php`
     * is gitignored precisely so the repo copy cannot switch it on.
     *
     * @param Request   $request The incoming request.
     * @param File|null $file    The credentials file; defaults to `data/site_auth.php`.
     * @return void
     */
    public static function requireSiteAuth(Request $request, ?File $file = null): void
    {
        $file ??= App::current()->dataFile(CredentialFile::SiteAuth);

        if (!$file->exists()) {
            return;
        }

        if (!self::accepts($request, $file)) {
            self::challenge(self::challengeValue());
        }
    }

    /**
     * Enforces admin authentication for protected routes (e.g. /admin/stats).
     *
     * Exits with a 401 if the credentials do not match. Unlike the site gate there is no absent-file
     * case: a missing `data/admin.php` is a broken deployment, and `require` says so loudly rather
     * than leaving the admin routes open.
     *
     * @param Request   $request The incoming request.
     * @param File|null $file    The credentials file; defaults to `data/admin.php`.
     * @return void
     */
    public static function requireAdminAuth(Request $request, ?File $file = null): void
    {
        if (!self::accepts($request, $file ?? App::current()->dataFile(CredentialFile::Admin))) {
            self::challenge(self::challengeValue());
        }
    }

    /**
     * Sends the Basic Auth challenge and ends the request.
     *
     * Public for the reason {@link self::matches()} is: a site's own gate answers its 401 here, so
     * there is one way a challenge goes out.
     *
     * @param BasicChallenge $challenge The realm to prompt in — the site's, or one a site's own gate names.
     * @return never
     */
    public static function challenge(BasicChallenge $challenge): never
    {
        header(new Header(ResponseHeader::WwwAuthenticate, $challenge)->line());
        http_response_code(HttpStatusCode::Unauthorized->value);
        exit;
    }
}
