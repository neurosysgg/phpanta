<?php

declare(strict_types=1);

namespace Phpanta\Support;

use Phpanta\Exception\InvalidValueException;

/**
 * The PasswordHash class. A bcrypt digest, checked to be one.
 *
 * It sits in `Support/` for the reason {@link Charset} does: two layers read it and neither owns
 * it. {@link \NeuroSYS\Model\Demo} declares one in `data/demos.php`, and
 * {@link \Phpanta\Service\Auth} is what compares against it — so it belongs to neither
 * `Model/` nor `Service/`.
 *
 * **What it is for is the failure a bare string has.** `password_verify()` answers `false` for a
 * wrong password and `false` for a hash that is not a hash, and those are opposite problems wearing
 * the same face: one is a visitor typing the wrong thing, the other is a credential that will never
 * open for anybody and says so to nobody. A truncated paste is the ordinary way to get the second,
 * and it presents as a password that "stopped working". So the shape is asked about once, where it
 * is written down, the way {@link \NeuroSYS\Model\Link\HiDriveLink} asks about a share id.
 *
 * Bcrypt specifically rather than "any algorithm PHP knows": it is what
 * `password_hash($p, PASSWORD_BCRYPT)` produces, which is what every credential here is minted
 * with and what `docs/` tells you to run. Widening this is a decision, not a convenience.
 */
final readonly class PasswordHash
{
    /**
     * Constructs an instance of {@link self}.
     *
     * @param string $digest A bcrypt digest — `$2y$…`, as `password_hash()` returns it.
     *
     * @throws InvalidValueException if $digest is not a bcrypt hash.
     */
    public function __construct(private string $digest)
    {
        $this->verify();
    }

    /**
     * The hash a credentials file holds, or `null` where it holds none.
     *
     * The empty string is the one non-hash this accepts, because it is not a mistake: `data/admin.php`
     * ships with `'pass_hash' => ''` and that is how an unconfigured gate is spelled. A caller gets
     * null and refuses, which is what {@link \Phpanta\Service\Auth::accepts()} already did in its
     * own words — there is no right answer to compare against, so there is no timing to protect and
     * nothing to verify. Anything else non-empty goes through the constructor and throws.
     *
     * @param string $digest
     * @return self|null
     *
     * @throws InvalidValueException if $digest is neither empty nor a bcrypt hash.
     */
    public static function configured(string $digest): ?self
    {
        return $digest === '' ? null : new self($digest);
    }

    /**
     * A digest nobody has the password to, for spending the time a real comparison would have.
     *
     * **It exists so that "no such demo" and "wrong password" cost the same.** A gate that returns
     * early when there is nothing to compare against answers in microseconds where a real
     * comparison pays bcrypt's ~100 ms, and that difference is measurable across a network — so a
     * uniform 401 is undone by a stopwatch, and anyone can read off which slugs exist. The fix is
     * to do the work anyway: verify against this, discard the answer, refuse.
     *
     * It is a genuine bcrypt digest of 32 random bytes generated once and thrown away, so
     * `password_verify()` runs its full cost against it and can never return true. Being in a
     * public repository costs nothing, because there is no preimage to publish — the same reason a
     * password file holds digests at all. Do not "fix" it by hashing something here at runtime:
     * that would pay for a hash *and* a verify, and be slower than the path it is imitating.
     *
     * @return self
     */
    public static function unmatchable(): self
    {
        return new self('$2y$12$nalTqfllhF8gEvzMsRb0Tu4ncuqIsvi3b9n4wP/A.7dh4WcUAuQB2');
    }

    /**
     * True if $password is the one this hash was made from.
     *
     * Constant-time by construction — that is what `password_verify()` is — and the only way to
     * *compare* against this object, which is the part that stays inside.
     *
     * @param string $password The candidate, in the clear, as it arrived on the request.
     * @return bool
     */
    public function matches(string $password): bool
    {
        return password_verify($password, $this->digest);
    }

    /**
     * The digest, for writing down.
     *
     * The one way the string leaves this class, and it has one caller:
     * `tools/lib/Demo/DemoEntryWriter`, which puts it into `data/demos.php`. Reading a digest is
     * not the risk a digest carries — being readable and useless to a reader is the whole idea —
     * and what this class actually keeps to itself is the *comparison*, which stays in
     * {@link self::matches()}.
     *
     * @return string
     */
    public function digest(): string
    {
        return $this->digest;
    }

    /**
     *
     * @return void
     * @throws InvalidValueException
     */
    private function verify(): void
    {
        if (password_get_info($this->digest)['algo'] !== PASSWORD_BCRYPT) {
            throw new InvalidValueException(sprintf(
                "PasswordHash must be a bcrypt digest, got '%s'. "
                . 'Mint one with: php -r "echo password_hash(\'…\', PASSWORD_BCRYPT);" — '
                . 'and note a digest that is not one verifies as false against every password, '
                . 'which reads as a gate nobody has the key to.',
                $this->digest,
            ));
        }
    }
}
