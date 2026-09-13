<?php

declare(strict_types=1);

namespace Phpanta\Http;

use Phpanta\Support\BareArray;

/**
 * The AuthScheme enum. The authentication schemes this site reads off an `Authorization` header.
 *
 * What made it worth writing is that the token was spelled **twice, in two files, on the two sides
 * of one handshake**: {@link BasicChallenge} wrote `Basic realm="…"` into the 401, and
 * {@link Request::fromGlobals()} matched `'Basic '` on the way back in. Neither knew about the
 * other.
 *
 * **And a mismatch there is the quietest failure on the site.** `Request::authorization()`'s own
 * docblock already names it about the header's *name*: the parse fails closed, so a visitor who
 * typed the right password is told, in the only way a browser can tell them, that they typed the
 * wrong one. It would look identical on both gates, on every attempt, with nothing in any log —
 * and the first guess at the cause would be the credentials file.
 *
 * **Two cases now, and they are not alternatives at one door.** Each names the scheme one gate
 * speaks, and no gate offers a choice — {@link BasicChallenge} still writes `Basic` and nothing
 * else, because `Digest` and `Bearer` have different grammars and offering one would be a second
 * named constructor rather than a second string. What the enum holds is that each grammar is
 * written down once: `Basic SP base64(user ":" pass)` in {@link self::credentials()}, and
 * {@link self::NS1}'s single opaque token in {@link self::parameters()}, instead of as `explode()`
 * calls in the middle of building a request.
 *
 * The two cannot both be satisfied by one request, since a request carries one `Authorization`.
 * That is the known interaction the demo gate already has with the pre-launch site gate, and it
 * reaches {@link self::NS1} the same way — see {@link \Phpanta\Service\ApiGate}.
 */
enum AuthScheme: string
{
    /**
     * HTTP Basic. Base64, not encryption — which is why
     * {@link Security\StrictTransportSecurity} is not optional here; see the site's `.htaccess`.
     */
    case Basic = 'Basic';

    /**
     * The scheme every signed API request carries, and the only credential `/api` accepts.
     *
     * Its parameters are one opaque base64 token — {@link \Phpanta\Model\Api\ApiCredential}'s
     * framing, a length-prefixed manifest followed by the ECDSA signature over it — so there is no
     * grammar here beyond "the rest of the header", and {@link self::parameters()} is all the
     * reading this class does. The structure inside is that class's business.
     *
     * **The digit is a format version and is deliberately not the API's.** `/api/{service}/v1/…`
     * versions what is being asked for; this versions how the asking is signed, and the two move
     * for different reasons. It is the same argument `Waveform`'s magic
     * makes, and this token *is* the magic: the credential needs no magic bytes of its own when the
     * scheme it arrives under already says which reader to use.
     *
     * **It is not a bearer token and the name is chosen to stop it reading as one.** A `Bearer`
     * value is a secret the server could replay; this is a signature over a manifest naming the
     * method, the path and the body's digest, so it authenticates one request and is worth nothing
     * against any other.
     */
    case NS1 = 'NS1';

    /**
     * True if $authorization is a credential in this scheme.
     *
     * The space is part of the question and not decoration: `Basicxyz` starts with `Basic` and is
     * not a Basic credential. RFC 9110 separates the scheme from the parameters with at least one
     * space, and one is what every client sends.
     *
     * @param string $authorization A raw `Authorization` header value, or `''` if none arrived.
     * @return bool
     */
    public function carries(string $authorization): bool
    {
        return str_starts_with($authorization, $this->value . ' ');
    }

    /**
     * Everything after the scheme token in $authorization, or null if it is not in this scheme.
     *
     * The counterpart to {@link self::credentials()} for a scheme whose parameters have no
     * structure this class can see. It answers **null** rather than `''` because the two are
     * genuinely different answers — a header in another scheme carries nothing for this one, where
     * `Basic ` with nothing after it carries an empty credential — and because the one caller,
     * {@link \Phpanta\Service\ApiGate}, has to refuse both and should not have to know they
     * arrived by different routes.
     *
     * @param string $authorization A raw `Authorization` header value, or `''` if none arrived.
     * @return string|null
     */
    public function parameters(string $authorization): ?string
    {
        if (!$this->carries($authorization)) {
            return null;
        }

        // Limit 2 for the reason credentials() gives: the scheme is separated from its parameters
        // by the first space and by no other, so a token containing one survives intact.
        [, $parameters] = explode(' ', $authorization, 2);

        return $parameters;
    }

    /**
     * The user name and password inside $authorization, or two empty strings.
     *
     * `['', '']` for a header in another scheme, or for base64 that does not decode. That is the
     * same answer {@link Request} already gives for credentials that did not arrive at all, and it
     * is the right one: an unreadable credential is not a credential, and
     * {@link \Phpanta\Service\Auth} compares whatever it is handed in constant time either way.
     * Nothing here decides anything; it only reads.
     *
     * @param string $authorization A raw `Authorization` header value.
     * @return array{string, string} The user name and the password, in that order.
     */
    #[BareArray(
        'a tuple, not a group: a user name and a password are two roles rather than two items, and '
        . 'the destructuring at the call site is what says which is which.',
    )]
    public function credentials(string $authorization): array
    {
        // Basic's grammar and only Basic's. A credential in another scheme carries something with
        // no user and no password in it — NS1's is a signature blob — and reading one *as* a Basic
        // credential would not fail, it would split a signature on its first colon and hand the two
        // halves to a comparison as though they were a login. `carries()` is not enough on its own
        // here, because for the wrong case it answers true about the wrong grammar.
        if ($this !== self::Basic || !$this->carries($authorization)) {
            return ['', ''];
        }

        // Limit 2, so a password containing a space survives: the scheme is separated from its
        // parameters by the first space and by no other.
        [, $encoded] = explode(' ', $authorization, 2);

        // strict: true, so base64 that is not base64 comes back false rather than being silently
        // repaired into some other user's name. Same instinct as HttpMethod::tryFrom() refusing to
        // guess GET.
        $decoded = base64_decode($encoded, true);

        if ($decoded === false) {
            return ['', ''];
        }

        // Limit 2, and for a stronger reason than above: a colon is legal in a password and illegal
        // in a user name, so the first one is the separator and every later one is content.
        //
        // Padded rather than guarded, which is the behaviour this has always had and is pinned by a
        // test named for it: a payload with no colon at all is a user name and no password. That
        // reads as generous and is not — an empty password matches no stored bcrypt digest, so the
        // credential still fails; it just fails in Auth, where every other wrong credential does.
        [$user, $password] = explode(':', $decoded, 2) + ['', ''];

        return [$user, $password];
    }
}
