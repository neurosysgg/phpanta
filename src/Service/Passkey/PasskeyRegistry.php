<?php

declare(strict_types=1);

namespace Phpanta\Service\Passkey;

use JsonException;
use Phpanta\App;
use Phpanta\CredentialFile;
use Phpanta\Model\Passkey\Passkey;
use Phpanta\Support\Collection;
use Phpanta\Support\File;

/**
 * The PasskeyRegistry class. The devices that may open the admin, kept in `data/admin-passkeys.json`.
 *
 * **Absent is none, and so is unreadable.** No file means no device may open the admin — the entrance
 * is all a browser sees — which is the safe state a fresh clone and every deployment nobody enrolled a
 * device on are in. A file that does not parse is read the same way, rather than as a fault: what it
 * guards is a door, and a door that cannot read its list stays shut.
 *
 * It is only written by a write the gate has let through — `access v1 enrol` and `revoke`, and an
 * unlock recording a signature count — so every write runs under the one lock a write holds.
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
        $others = $this->all()->where(static fn(Passkey $each): bool => $each->id !== $passkey->id);

        return $this->write($others->with($passkey));
    }

    /**
     * The store without the device whose credential id is $id. False where it could not be written.
     *
     * @param string $id
     * @return bool
     */
    public function forget(string $id): bool
    {
        return $this->write($this->all()->where(static fn(Passkey $each): bool => $each->id !== $id));
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
