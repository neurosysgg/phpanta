<?php

declare(strict_types=1);

namespace Phpanta\Service\Passkey;

use Closure;
use JsonException;
use NoDiscard;
use Phpanta\App;
use Phpanta\CredentialFile;
use Phpanta\Model\Passkey\Passkey;
use Phpanta\Support\Collection;
use Phpanta\Support\File;
use Phpanta\Support\FileLock;

/**
 * The PasskeyRegistry class. The devices that may open the admin, kept in `data/admin-passkeys.json`.
 *
 * **Absent is none, and so is unreadable.** No file means no device may open the admin — the entrance
 * is all a browser sees — which is the safe state a fresh clone and every deployment nobody enrolled a
 * device on are in. A file that does not parse is read the same way, rather than as a fault: what it
 * guards is a door, and a door that cannot read its list stays shut.
 *
 * **Every write is a read, a change and a write under the store's own lock** — a waiting one, on a file
 * beside the store ({@link FileLock::beside()}). Writes come from two directions: `access v1 enrol` and
 * `revoke`, under the lock a signed write holds, and every unlock and lock at the entrance, under no
 * other. A change made to the copy a request read at its start would drop whatever landed meanwhile —
 * a revocation undone by an unlock racing it, a device lost to one enrolled beside it — so each is made
 * to the store as it is once the lock is held.
 */
final readonly class PasskeyRegistry
{
    /** The store is a list of flat objects. */
    private const int MAX_DEPTH = 4;

    /**
     * Constructs an instance of {@link self}.
     *
     * @param File|null $file Where the store is; the app's by default. A test passes its own.
     */
    public function __construct(private ?File $file = null) {}

    /**
     * Every enrolled device, in the order they were enrolled.
     *
     * @return Collection<Passkey>
     */
    public function all(): Collection
    {
        $none = new Collection(Passkey::class);
        $text = $this->file()->read();

        try {
            $data = $text === null ? null : json_decode($text, false, self::MAX_DEPTH, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return $none;
        }

        if (!is_array($data)) {
            return $none;
        }

        $passkeys = [];

        foreach ($data as $each) {
            $passkey = Passkey::fromData($each);

            if ($passkey !== null) {
                $passkeys[] = $passkey;
            }
        }

        return $none->with(...$passkeys);
    }

    /**
     * The device whose credential id is $id, or null.
     *
     * @param string $id
     * @return Passkey|null
     */
    public function find(string $id): ?Passkey
    {
        return $this->all()->first(static fn(Passkey $passkey): bool => hash_equals($passkey->id, $id));
    }

    /**
     * The store with $passkey in it — added, or in place of the one with its id. False where the store
     * could not be written.
     *
     * @param Passkey $passkey
     * @return bool
     */
    public function keep(Passkey $passkey): bool
    {
        return $this->rewrite(static fn(Collection $all): Collection => $all
            ->where(static fn(Passkey $each): bool => $each->id !== $passkey->id)
            ->with($passkey));
    }

    /**
     * The store without the device whose credential id is $id. False where it could not be written.
     *
     * @param string $id
     * @return bool
     */
    public function forget(string $id): bool
    {
        return $this->rewrite(
            static fn(Collection $all): Collection => $all->where(static fn(Passkey $each): bool => $each->id !== $id),
        );
    }

    /**
     * The device whose credential id is $id, as $change leaves it — decided and written under the store's
     * lock, against the store as it stands by then.
     *
     * Null where no such device is enrolled any more, where $change refuses by answering null, or where
     * the store could not be written — so a change never brings back a device revoked while it was
     * being decided.
     *
     * @param string                     $id
     * @param Closure(Passkey): ?Passkey $change
     * @return Passkey|null What the store now holds for $id.
     */
    #[NoDiscard('change() answers whether the change was made; a call whose result goes nowhere made it blind')]
    public function change(string $id, Closure $change): ?Passkey
    {
        $changed = null;
        $written = $this->rewrite(static function (Collection $all) use ($id, $change, &$changed): ?Collection {
            $current = $all->first(static fn(Passkey $each): bool => hash_equals($each->id, $id));
            $changed = $current instanceof Passkey ? $change($current) : null;

            return $changed === null
                ? null
                : $all->map(static fn(Passkey $each): Passkey => $each === $current ? $changed : $each);
        });

        return $written ? $changed : null;
    }

    /**
     * Rewrites the store as $change makes it, under the store's lock — or leaves it be, where $change
     * answers null. False where the lock could not be taken or the store not written.
     *
     * @param Closure(Collection<Passkey>): ?Collection<Passkey> $change
     * @return bool
     */
    private function rewrite(Closure $change): bool
    {
        $lock = FileLock::waitFor(FileLock::beside($this->file()));

        if ($lock === null) {
            return false;
        }

        try {
            $changed = $change($this->all());

            return $changed !== null && $this->write($changed);
        } finally {
            $lock->release();
        }
    }

    /**
     * @param Collection<Passkey> $passkeys
     * @return bool
     */
    private function write(Collection $passkeys): bool
    {
        return $this->file()->write(
            (string) json_encode($passkeys->toValues(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n",
            0o600,
        );
    }

    /**
     * @return File
     */
    private function file(): File
    {
        return $this->file ?? App::current()->dataFile(CredentialFile::AdminPasskeys);
    }
}
