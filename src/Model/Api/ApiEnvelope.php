<?php

declare(strict_types=1);

namespace Phpanta\Model\Api;

use JsonException;
use Phpanta\Exception\ApiException;
use Phpanta\Http\HttpMethod;
use Phpanta\Support\PublicKey;

/**
 * The ApiEnvelope class. What every signed request claims about itself, whatever it is asking for.
 *
 * **The signature covers the manifest and the manifest covers the request.** One signature over a
 * couple of hundred bytes therefore authenticates a body of any size, and a body of no size at all
 * — which is what lets a read be signed on the same terms as a push. Every field here is
 * load-bearing: a field the envelope does not carry is a field an attacker chooses.
 *
 * Five of them, and the two that are new are the two that matter most:
 *
 * - **`method` and `path` bind the request to the credential.** Without them a credential
 *   authenticates only *some* request, so one captured for a read could be replayed as a write, and
 *   a payload signed for one action would verify at another. That was sound while `/update` was the
 *   only thing there was to sign for and stopped being sound the moment there were two addresses.
 * - `digest` and `size` bind the body, and are checked for **every** method rather than skipped when
 *   a request is not expected to have one. A read signs `sha256('')` and a size of zero, so the
 *   check needs no branch — and, more to the point, a signed GET cannot smuggle a body past it for
 *   some later action to read unsigned.
 * - `serial` is the replay guard, doing double duty against the clock and against the last one
 *   accepted. See {@link \Phpanta\Service\ApiGate}.
 *
 * **What `path` binds is {@link \Phpanta\Http\Request::path()}'s output, not the wire target**, and
 * the difference is worth knowing rather than discovering. That path is normalised — a trailing
 * slash is stripped, a query string is already gone, and an absolute-form target has its authority
 * discarded — so `/admin/update/v1/patch` and `/admin/update/v1/patch/` are one signed path. It is
 * compared against that string directly and never against a path rebuilt from the router's
 * captures, because {@link \Phpanta\Support\Route::matches()} decodes each value and
 * {@link \Phpanta\Support\Path::to()} encodes it afresh: a segment sent as `%70atch` would be
 * rebuilt as `patch`, and two spellings of one address is the failure this whole
 * arrangement exists to avoid.
 *
 * **A consequence to keep**: the query string is not covered, because nothing here reads one — no
 * code under `src/` touches `$_GET` or `QUERY_STRING`. An API action must therefore never read a
 * query parameter, since it would be the one input reaching a verified caller's handler unsigned.
 * A parameter belongs in this manifest or in the body.
 *
 * **Cross-deployment replay is closed by key separation rather than by an audience field.**
 * `data/update.pub` is gitignored, per-deployment and uploaded by hand, so no two deployments hold
 * the same key and a credential minted for one verifies nowhere else. An `aud` field would have to
 * be checked against something the server knows independently of the request — and `Host` is
 * whatever the caller sent, so it would bind nothing. If a second deployment ever shares this key,
 * that field is what to add.
 *
 * It is parsed strictly: every key present, every type exact, no defaults and no coercions. The
 * argument is {@link \Phpanta\Model\Update\UpdateManifest}'s and applies twice over here, since
 * this is the half a caller cannot get wrong without the request meaning something else.
 */
final readonly class ApiEnvelope
{
    /** A SHA-256, written out. */
    private const string DIGEST_PATTERN = '/\A[0-9a-f]{64}\z/';

    /**
     * How deep the manifest JSON may nest.
     *
     * Two, not one: this reads five scalars, but the same bytes are read again by whatever action
     * the request names, and an action's own parameters may be an object. It is the argument
     * against an unbounded depth that matters rather than the number — `json_decode`'s default is
     * 512.
     */
    private const int MAX_DEPTH = 8;

    /**
     * Constructs an instance of {@link self}.
     *
     * @param int $serial A Unix timestamp doing double duty — bounded against the clock and against
     *                    the last serial accepted.
     * @param string $method The method this credential was minted for, as {@link \Phpanta\Http\HttpMethod}
     *                       spells it. A string rather than the enum because it is compared against
     *                       a request whose own method may be *unrecognised*, and the comparison
     *                       has to be able to fail rather than throw.
     * @param string $path The path it was minted for — `Request::path()`'s spelling.
     * @param string $digest SHA-256 of the request body, lowercase hex. `sha256('')` for a read.
     * @param int $size Length of the request body in bytes. Zero for a read.
     */
    private function __construct(
        public int    $serial,
        public string $method,
        public string $path,
        public string $digest,
        public int    $size,
    ) {}

    /**
     * The envelope of a request the admin built itself rather than read out of a credential — a
     * browser's, which a session and a passkey vouch for instead of a signature. It has no body.
     *
     * @param int        $serial
     * @param HttpMethod $method
     * @param string     $path
     * @return self
     */
    public static function of(int $serial, HttpMethod $method, string $path): self
    {
        return new self($serial, $method->value, $path, hash(PublicKey::DIGEST, ''), 0);
    }

    /**
     * Parses the envelope out of the signed manifest.
     *
     * `json_decode`'s array stays a local and is never a declared type, which is the whole point of
     * the method: it is the door, and nothing past it is an array.
     *
     * @param string $json The exact bytes the signature was checked against.
     * @return self
     *
     * @throws ApiException if the JSON is unreadable or any field is missing or mistyped.
     */
    public static function parse(string $json): self
    {
        try {
            /** @var mixed $values */
            $values = json_decode($json, true, self::MAX_DEPTH, JSON_THROW_ON_ERROR);
        } catch (JsonException $cause) {
            throw new ApiException(
                'the API manifest is not readable JSON: ' . $cause->getMessage(),
                previous: $cause,
            );
        }

        if (!is_array($values)) {
            throw new ApiException('the API manifest is not a JSON object');
        }

        $serial = $values['serial'] ?? null;
        $method = $values['method'] ?? null;
        $path   = $values['path']   ?? null;
        $digest = $values['digest'] ?? null;
        $size   = $values['size']   ?? null;

        if (!is_int($serial) || !is_string($method) || !is_string($path) || !is_string($digest) || !is_int($size)) {
            throw new ApiException(
                'the API manifest must carry serial:int, method:string, path:string, digest:string '
                . 'and size:int, all present and all of those types',
            );
        }

        if (preg_match(self::DIGEST_PATTERN, $digest) !== 1) {
            throw new ApiException('the API manifest\'s digest is not a lowercase hex SHA-256');
        }

        if ($size < 0) {
            throw new ApiException('the API manifest declares a negative body size');
        }

        return new self($serial, $method, $path, $digest, $size);
    }
}
