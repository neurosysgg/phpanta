<?php

declare(strict_types=1);

namespace Phpanta\Service;

use NoDiscard;
use Phpanta\App;
use Phpanta\CredentialFile;
use Phpanta\Exception\ApiException;
use Phpanta\Http\AuthScheme;
use Phpanta\Http\Request;
use Phpanta\Model\Api\ApiCredential;
use Phpanta\Model\Api\ApiEnvelope;
use Phpanta\Model\Api\SerialRefusal;
use Phpanta\Model\Api\VerifiedRequest;
use Phpanta\Support\File;
use Phpanta\Support\FileLock;
use Phpanta\Support\PublicKey;

/**
 * The ApiGate class. Decides whether a request is one this deployment signed for, and answers with
 * everything it proved when it is.
 *
 * It is {@link Auth} for `/api`, and the split is the same one: the decision is a returned value
 * and the refusal lives outside it, because a method that ends the request cannot be asserted
 * against. Here the refusal is not even a challenge — it is
 * {@link \Phpanta\Controller\ApiController} answering exactly as the app answers for a path no
 * route claims, so a caller without the key cannot tell `/api` or anything under it from a typo.
 *
 * **Nothing this class refuses says why.** Every failure below is one `null`, and the controller
 * turns every `null` into the same 404 or 405. That is the difference between this gate and the
 * Basic ones: those announce a realm because a person has to be prompted for a password, and
 * this must announce nothing at all, because the only legitimate caller already knows the endpoint
 * is there. Past the signature the situation inverts and diagnostics become generous — see
 * {@link \Phpanta\Http\Api\ApiHandler} — since by then the caller has proved possession of the
 * private key.
 *
 * **The order below is the design.** Cheap and unforgeable checks first, then the signature, then
 * everything the signature makes trustworthy. Nothing derived from the credential is used for
 * anything before {@link PublicKey::verifies()} has passed, and — the change that matters most
 * against its predecessor — **the body is not read at all until the envelope has said how long it
 * is**. `/update` read up to 8 MB of an unsigned caller's body before it could refuse them, which
 * `docs/security.md` names as the one thing that could have told the endpoint from a typo, by the
 * time it took to drain. An unrouted path drains nothing; now neither does this.
 *
 * **The key and the serial are still named for `update`** — `data/update.pub` and
 * `.update-serial` — though both now cover every service. Renaming either would mean a file
 * uploaded by hand on the server and a counter starting again from zero, which is a migration to
 * buy a tidier name; the names are the service that first needed them, and that is written here
 * rather than fixed.
 */
final readonly class ApiGate
{
    /**
     * The most a request body may weigh.
     *
     * A real payload is about 250 KB, so this is thirty times what it takes and a **sixteenth**
     * of what a host whose `post_max_size` is 128M would permit.
     *
     * **It is a ceiling on what the envelope may ask for rather than the length anything is read
     * to**: the read below is bounded by the *signed* size, so a credential claiming ten bytes
     * cannot make this process buffer eight megabytes, and a credential claiming more than this is
     * refused before a byte is read at all. A bound the application states is worth more than one
     * inherited from a php.ini the app does not own.
     *
     * **Neither figure above is worth carrying**: the payload grows with the codebase, and the
     * limit is the host's. Re-derive both rather than trusting these —
     * `php tools/push-update.php --dry-run` prints the archive's size, and
     * `php tools/api.php health v1 settings` checks the limit against this.
     *
     * Public because two floors are derived from it rather than written out a second time —
     * `post_max_size` and `memory_limit`, in {@link \Phpanta\Support\RequirementInitialization}.
     */
    public const int MAX_BODY = 8_388_608;

    /**
     * How far a credential's serial may sit from this server's clock, in seconds.
     *
     * Five minutes each way, which is enough for an unsynchronised laptop and short enough that a
     * captured credential is worthless long before anyone could use it. It is the belt to the
     * monotonic check's braces on replay: that one refuses a serial that has been seen, and this
     * refuses one saved up to be used later.
     */
    private const int MAX_SKEW = 300;

    /**
     * Constructs an instance of {@link self}.
     *
     * @param File|null $key Where the public key lives. The parameter is a test seam, the way
     *                       {@link Auth::requireSiteAuth()}'s is; production passes nothing.
     * @param File|null $serial Where the last accepted serial is recorded. Same.
     */
    public function __construct(private ?File $key = null, private ?File $serial = null) {}

    /**
     * Everything this request proved, or null if it proved nothing.
     *
     * @param Request $request
     * @return VerifiedRequest|null
     */
    #[NoDiscard('this is the API gate\'s decision and nothing else; dropping it is a door left open')]
    public function accepts(Request $request): ?VerifiedRequest
    {
        $method = $request->method();

        // First, and not merely tidy. A verb the framework does not recognise is null, and null has no
        // ->value — so comparing it against the envelope's method without asking would be an
        // uncaught TypeError, which is a 500 where an address that does not exist sends a 405.
        // One differing status code and the endpoint has announced itself to anybody who types
        // BREW. MethodPolicy::Delegated exists precisely so that null gets this far.
        if ($method === null) {
            return null;
        }

        // Asked for by scheme, so the raw header never leaves Request and a credential in some
        // other scheme is simply not ours — no reading required to establish that.
        $parameters = $request->credential(AuthScheme::NS1);
        if ($parameters === null) {
            return null;
        }

        $key = $this->key();
        if ($key === null) {
            return null;
        }

        try {
            $parsed = ApiCredential::parse($parameters);

            // Everything above this line is framing. Nothing below trusts a byte of it until the
            // signature has passed, and the envelope is not even parsed until then: parsing is a
            // decision about attacker-controlled bytes, and the cheapest place to make none.
            if (!$key->verifies($parsed->manifest, $parsed->signature)) {
                return null;
            }

            $envelope = ApiEnvelope::parse($parsed->manifest);
        } catch (ApiException) {
            return null;
        }

        // The credential now proves someone holding the key wrote this manifest. What it does not
        // yet prove is that they wrote it for *this* request, which is what these two ask — and
        // without them a credential minted for a read would replay as a write, and one minted for
        // one action would verify at any other.
        //
        // Compared against Request::path() directly and never against a path rebuilt from the
        // router's captures: Path::to() encodes afresh what Route::matches() decoded, so a rebuild
        // would disagree with the signed path on any segment the caller encoded differently.
        if ($envelope->method !== $method->value || $envelope->path !== $request->path()) {
            return null;
        }

        if (!$this->isFresh($envelope->serial)) {
            return null;
        }

        if ($envelope->size > self::MAX_BODY) {
            return null;
        }

        // Read to one past the signed length rather than to the cap: the check below can then
        // reject a body longer than the envelope claimed, having pulled only that one extra byte.
        // A read signs a size of zero, so this asks php://input for one byte and gets none.
        $body = $request->body($envelope->size + 1);

        // The envelope is signed, so its digest is ours; this is what binds the body to it. Asked
        // for every method, deliberately — a read's digest is sha256('') and its size is zero, so
        // there is no branch here, and a signed GET cannot smuggle a body past this for some later
        // action to read unsigned. hash_equals rather than === because one side is attacker-
        // supplied, which is the case it exists for.
        if (strlen($body) !== $envelope->size || !hash_equals($envelope->digest, hash('sha256', $body))) {
            return null;
        }

        return new VerifiedRequest($envelope, $parsed->manifest, $body);
    }

    /**
     * Spends $serial for a write, and answers the lock the write then runs under.
     *
     * Called by the controller for a write *before* it runs, and never for a read or a dry run:
     * neither changes anything, so leaving the serial where it is lets the same credential be sent
     * again for real, and a captured dry run replayed on its own still does nothing.
     *
     * **Three steps, under one lock the caller holds until the write has finished**:
     *
     * - The lock, beside the serial and non-blocking. Two writes in flight at once would each write
     *   and mirror over the other — deleting the files the other just wrote — so the second is
     *   refused rather than queued; see {@link FileLock}.
     * - Freshness, asked again. {@link self::accepts()} asked it before the body was read, and
     *   another write may have recorded a newer serial since. Unasked, two overlapping writes both
     *   pass, the older one's record lands last, and the serial moves *backwards* — which hands the
     *   newer credential a replay window.
     * - The record. A serial that cannot be stored is replay protection quietly off, so that is a
     *   refusal too, before anything is written.
     *
     * @param int $serial
     * @return FileLock|SerialRefusal The lock to release once the write has run, or why nothing may.
     */
    #[NoDiscard('a lock nobody holds is released at once, and a refusal dropped is a write run unguarded')]
    public function spend(int $serial): FileLock|SerialRefusal
    {
        $record = $this->serial ?? App::current()->updateSerial();
        $lock   = FileLock::exclusive(new File($record->path . '.lock'));

        if ($lock === null) {
            return SerialRefusal::Busy;
        }

        if (!$this->isFresh($serial)) {
            $lock->release();

            return SerialRefusal::Stale;
        }

        if (!$record->write($serial . "\n", 0o600)) {
            $lock->release();

            return SerialRefusal::Unrecorded;
        }

        return $lock;
    }

    /**
     * The key this deployment verifies against, or null where it holds none.
     *
     * A key file that is absent, unreadable, or not a usable EC key all collapse to null and so to
     * the same 404 — which is the correct collapse here, unlike {@link \Phpanta\Support\File::read()}'s,
     * because the difference matters to whoever installs the key and to nobody else. A malformed
     * key is loud in the one place it can be: `openssl pkey -pubin -in data/update.pub -text`.
     *
     * **Its absence is the off switch**, and it is read before the signature rather than after so
     * that a deployment holding no key does no verification work at all — which is also what keeps
     * a fresh clone free of any measurable difference between `/api` and an address that is not
     * there.
     *
     * @return PublicKey|null
     */
    private function key(): ?PublicKey
    {
        $pem = ($this->key ?? App::current()->dataFile(CredentialFile::UpdateKey))->read();

        if ($pem === null) {
            return null;
        }

        try {
            return PublicKey::fromPem($pem);
        } catch (ApiException) {
            return null;
        }
    }

    /**
     * Whether $serial is both close to this clock and ahead of every serial already accepted.
     *
     * Two questions rather than one because each closes a gap the other leaves. The monotonic half
     * alone would accept a credential signed years ago and never sent; the skew half alone would
     * let one be replayed for five minutes. A missing or unreadable record reads as zero, which is
     * the permissive direction and is correct: the first push a deployment ever receives has
     * nothing to be ahead of.
     *
     * **Strictly greater, for reads as well as writes**, which has one papercut worth knowing:
     * a serial is `time()`, so a write and a read minted in the same second cannot both be
     * accepted — the second is refused as stale. Relaxing it for reads would mean asking which
     * action this is before the freshness question is settled, splitting one decision across two
     * objects to buy a second's patience. Waiting the second is cheaper.
     *
     * @param int $serial
     * @return bool
     */
    private function isFresh(int $serial): bool
    {
        if (abs(time() - $serial) > self::MAX_SKEW) {
            return false;
        }

        $recorded = ($this->serial ?? App::current()->updateSerial())->read();

        // (int) '' is 0, so an absent or unreadable record needs no sentinel of its own.
        return $serial > (int) trim($recorded ?? '');
    }
}
