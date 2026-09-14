<?php

declare(strict_types=1);

namespace Phpanta\Support;

use OpenSSLAsymmetricKey;
use Phpanta\Exception\UpdateException;

/**
 * The PublicKey class. An ECDSA P-256 public key, checked to be one, and the only thing in the
 * framework that verifies a signature.
 *
 * It is {@link PasswordHash} for the other kind of credential, and it is written to the same shape
 * on purpose: the material is validated once where it is written down, the comparison is the one
 * thing that stays inside, and nothing else in `src/` calls the underlying primitive. **This is the
 * single `openssl_*` call site in the whole framework**, which the verify script pins the way it
 * pins `curl_` to one file of tooling.
 *
 * **Why a key rather than a password.** The other gates are HTTP Basic, where the
 * secret is on the wire and the server holds something derived from it. This gate protects a route
 * that overwrites `src/` and the webroot, so the server must hold nothing that can be replayed:
 * what it stores is the *public* half, useless to anyone who reads it, and a compromise of the
 * whole account yields no ability to push an update. That asymmetry is the entire reason this class
 * exists instead of one more bcrypt digest.
 *
 * **ECDSA P-256 with SHA-256, decided by measurement rather than taste.** Ed25519 would be the
 * modern default and is not available: `ext/sodium` is absent on the development machine, and
 * PHP's own openssl binding refuses an Ed25519 key with `Provider routines::invalid digest`,
 * because its signing call drives the digest-based API while Ed25519 is a one-shot algorithm.
 * P-256 was then verified end to end on a shared host — key parses, a good signature returns 1, a
 * tampered one returns 0 — before any of this was written. Widening the algorithm is a decision,
 * not a convenience, exactly as it is on {@link PasswordHash}.
 *
 * Note that nothing here can sign, and that is structural rather than a matter of restraint: this
 * class holds a public key and calls one function. The verify script asserts that no file under
 * `src/` names a signing or key-minting call at all, so a private key arriving on the server would
 * have nothing to use it.
 */
final readonly class PublicKey
{
    /**
     * What `openssl_verify()` returns for a signature that is genuinely this key's.
     *
     * Named because the other two answers are not interchangeable: `0` is a signature that does not
     * verify, and `-1` is an error inside openssl. Only `1` is a pass, and `!== 1` is the only safe
     * way to ask — a truthiness check would read `-1` as success.
     */
    private const int VERIFIED = 1;

    /** P-256, as openssl names it — the one curve this class documents, and so the one it accepts. */
    private const string CURVE = 'prime256v1';

    /**
     * The digest every signature here is made over, as `hash()` names it — SHA-256, the "256" in
     * ES256. Public, and the one spelling: a signed call's body digest, a passkey's client data and a
     * passkey's fingerprint are all this digest, and three classes each writing the name would be three
     * facts free to disagree about which one it is.
     */
    public const string DIGEST = 'sha256';

    /**
     * Constructs an instance of {@link self}.
     *
     * @param OpenSSLAsymmetricKey $key A parsed EC public key.
     */
    private function __construct(private OpenSSLAsymmetricKey $key) {}

    /**
     * Reads a PEM public key, refusing anything that is not an EC one.
     *
     * The type is checked as well as the parse, because `openssl_pkey_get_public()` is happy with
     * an RSA key and {@link self::verifies()} would then be asking a different question than this
     * class documents. A key of the wrong type is the same shape of mistake as a bcrypt digest that
     * is not one: it fails every comparison, for everybody, forever, and presents as a credential
     * that "stopped working".
     *
     * @param string $pem The `-----BEGIN PUBLIC KEY-----` block, as `openssl pkey -pubout` writes it.
     * @return self
     *
     * @throws UpdateException if $pem is not a parsable EC public key.
     */
    public static function fromPem(string $pem): self
    {
        $key = Diagnostics::muted(static fn(): OpenSSLAsymmetricKey|false => openssl_pkey_get_public($pem));
        if ($key === false) {
            throw new UpdateException(
                'the update key is not a readable PEM public key. Generate the pair with: '
                . 'openssl genpkey -algorithm EC -pkeyopt ec_paramgen_curve:P-256 -out update.key '
                . '&& openssl pkey -in update.key -pubout -out update.pub',
            );
        }

        $details = openssl_pkey_get_details($key);
        if (($details['type'] ?? null) !== OPENSSL_KEYTYPE_EC) {
            throw new UpdateException(
                'the update key parsed but is not an EC key, so it can verify nothing this site '
                . 'signs. Regenerate it with -algorithm EC -pkeyopt ec_paramgen_curve:P-256.',
            );
        }

        // The curve as well as the type. An EC key on any other curve parses, and verifies a SHA-256
        // signature just as happily — P-384 and secp112r1 alike — which would widen the algorithm
        // without anybody having decided to.
        $curve = $details['ec']['curve_name'] ?? null;
        if ($curve !== self::CURVE) {
            throw new UpdateException(sprintf(
                "the update key is an EC key on %s, not P-256. Regenerate it with -algorithm EC "
                . '-pkeyopt ec_paramgen_curve:P-256.',
                is_string($curve) ? $curve : 'an unnamed curve',
            ));
        }

        return new self($key);
    }

    /**
     * True if $signature is this key's signature over $data.
     *
     * The one way to compare against this object, and the only place `openssl_verify()` is called.
     * There is no timing concern to answer here, unlike {@link PasswordHash::matches()}: a
     * signature check compares public values with a public key, so there is no secret on this side
     * whose comparison could leak.
     *
     * @param string $data The bytes that were signed — exactly those, byte for byte.
     * @param string $signature The DER signature, raw.
     * @return bool
     */
    public function verifies(string $data, string $signature): bool
    {
        // Note the closure's return type. `openssl_verify()` answers `int|false`, and one typed
        // `int` would turn its error return into a TypeError raised from inside the suppression.
        return Diagnostics::muted(
            fn(): int|false => openssl_verify($data, $signature, $this->key, OPENSSL_ALGO_SHA256),
        ) === self::VERIFIED;
    }
}
