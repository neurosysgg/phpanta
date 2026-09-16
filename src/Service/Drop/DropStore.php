<?php

declare(strict_types=1);

namespace Phpanta\Service\Drop;

use NoDiscard;
use Phpanta\App;
use Phpanta\Exception\FilesystemException;
use Phpanta\Model\Drop\DropConfig;
use Phpanta\Model\Drop\DropHeader;
use Phpanta\Model\Drop\DropKind;
use Phpanta\Model\Drop\DropMeta;
use Phpanta\Model\Drop\DropRefusal;
use Phpanta\Model\Drop\DropSummary;
use Phpanta\Model\Drop\DropToken;
use Phpanta\Support\Collection;
use Phpanta\Support\Directory;
use Phpanta\Support\File;
use SensitiveParameter;

/**
 * The DropStore class. Where drops are kept — `data/drops/`, a file each — and the one place one is
 * made, opened, claimed, listed or taken away.
 *
 * **A file per drop, named by {@link DropCipher::idOf()}**, sealed whole — see {@link DropHeader} for
 * its layout — and written beside itself and renamed into place at `0600`, so a reader finds a whole
 * drop or none. The directory is made at `0700` by the first drop.
 *
 * **Read once means claimed once.** A drop to be read once is claimed by renaming its file to a name
 * of its own, which the filesystem lets exactly one request do; the winner unlinks it at once and
 * reads through the handle it already holds, and every other request finds nothing. That happens only
 * once the link, and the password where there is one, have opened its description — a wrong password
 * never burns a drop — and only for a file whose length is what its description says, so a drop cut
 * short is not claimed half-read.
 *
 * **What has expired is swept whenever the store is touched** — a drop made, opened, listed or taken
 * away — an enumerated delete of `*.drop` files whose header says they are gone, and of any claimed
 * name a request left behind. There is no cron: a deployment on a shared host has none to offer.
 */
final readonly class DropStore
{
    /** The store's directory, in the deployment's data directory. */
    public const string DIRECTORY = 'drops';

    /** What a drop's file is named with, after its id. */
    private const string SUFFIX = '.drop';

    /** What a claimed drop's file is renamed with, after its name and a random part. */
    private const string CLAIMED = '.claimed';

    /** What a drop's id is: thirty-two lower-case hex digits. */
    private const string ID = '/\A[0-9a-f]{32}\z/';

    /** A drop's file: its owner's alone. */
    private const int MODE = 0o600;

    /** The store's directory: its owner's alone. */
    private const int DIRECTORY_MODE = 0o700;

    /**
     * Constructs an instance of {@link self}.
     *
     * @param Directory  $directory  Where drops are kept.
     * @param DropCipher $cipher     What they are sealed with.
     * @param int        $iterations The PBKDF2 rounds a new drop's password is stretched with. A test
     *                               seam: production takes {@link DropCipher::ITERATIONS}.
     */
    public function __construct(
        private Directory  $directory,
        private DropCipher $cipher,
        private int        $iterations = DropCipher::ITERATIONS,
    ) {}

    /**
     * This deployment's store — or null where drops are off here: no `data/drop.json` that reads, or no
     * key in `data/drop.key`.
     *
     * @return self|null
     */
    public static function current(): ?self
    {
        $cipher = DropConfig::current() === null ? null : DropCipher::current();

        return $cipher === null ? null : new self(App::current()->data()->directory(self::DIRECTORY), $cipher);
    }

    /**
     * Whether $id is what a drop's id looks like.
     *
     * @param string $id
     * @return bool
     */
    public static function isId(string $id): bool
    {
        return preg_match(self::ID, $id) === 1;
    }

    /**
     * Keeps $payload, sealed, and answers the token that opens it — which nothing here keeps.
     *
     * @param string      $payload
     * @param string|null $name     The name it is saved under, which makes it a file; null for text.
     * @param bool        $once     Whether it is gone once it has been read.
     * @param string      $password A password it needs besides its link; `''` for none.
     * @param int         $lifetime How long it is kept, in seconds.
     * @param int|null    $now
     * @return DropToken
     * @throws FilesystemException if the store cannot be made, or the drop not written into it.
     */
    #[NoDiscard(
        'create() answers the one token that opens the drop; a call whose result goes nowhere kept a drop '
        . 'nobody can open',
    )]
    public function create(
        string $payload,
        ?string $name,
        bool $once,
        #[SensitiveParameter] string $password,
        int $lifetime,
        ?int $now = null,
    ): DropToken {
        $now ??= time();
        $this->sweep($now);

        if (!$this->directory->create(self::DIRECTORY_MODE)) {
            throw new FilesystemException(sprintf('The drop store %s could not be made.', $this->directory->path));
        }

        $token  = DropToken::mint();
        $meta   = new DropMeta($name === null ? DropKind::Text : DropKind::File, $name, strlen($payload));
        $json   = $meta->json();
        $header = new DropHeader(
            $once,
            $password !== '',
            $now,
            $now + $lifetime,
            $password === '' ? 0 : $this->iterations,
            random_bytes(DropHeader::SALT_BYTES),
            strlen($json) + DropHeader::TAG,
        );
        $keys   = $this->cipher->keys($token, $password, $header);
        $sealed = $header->bytes() . $this->cipher->seal($json, $keys->meta, 0, $header->bytes());
        $count  = $meta->chunks();

        for ($index = 0; $index < $count; $index++) {
            $sealed .= $this->cipher->seal(
                substr($payload, $index * DropHeader::CHUNK, DropHeader::CHUNK),
                $keys->data,
                $index,
                $header->chunkData($index, $index === $count - 1),
            );
        }

        if (!$this->file($this->cipher->idOf($token))->write($sealed, self::MODE)) {
            throw new FilesystemException(sprintf('A drop could not be written into %s.', $this->directory->path));
        }

        return $token;
    }

    /**
     * The drop $token opens with $password, ready to be read — claimed first, where it opens once — or
     * why it does not open.
     *
     * @param DropToken $token
     * @param string    $password Ignored where it needs none.
     * @param int|null  $now
     * @return OpenedDrop|DropRefusal
     */
    #[NoDiscard('open() claims a drop meant to be read once; a call whose result goes nowhere burned it unread')]
    public function open(
        DropToken $token,
        #[SensitiveParameter] string $password,
        ?int $now = null,
    ): OpenedDrop|DropRefusal {
        $now ??= time();
        $this->sweep($now);

        $file   = $this->file($this->cipher->idOf($token));
        $handle = $file->reading();

        if ($handle === null) {
            return DropRefusal::Absent;
        }

        $header = DropHeader::read((string) fread($handle, DropHeader::LENGTH));

        if ($header === null || $header->isExpired($now)) {
            fclose($handle);

            return DropRefusal::Absent;
        }

        if ($header->locked && $password === '') {
            fclose($handle);

            return DropRefusal::Locked;
        }

        $keys = $this->cipher->keys($token, $password, $header);
        $json = $this->cipher->open((string) fread($handle, $header->metaLength), $keys->meta, 0, $header->bytes());
        $meta = $json === null ? null : DropMeta::read($json);

        if ($meta === null) {
            fclose($handle);

            return $header->locked ? DropRefusal::Locked : DropRefusal::Absent;
        }

        if ($file->size() !== $header->fileSize($meta) || ($header->once && !$this->claim($file))) {
            fclose($handle);

            return DropRefusal::Absent;
        }

        return new OpenedDrop($header, $meta, $keys, $this->cipher, $handle);
    }

    /**
     * Every drop kept, oldest first — once what has expired is swept.
     *
     * @param int|null $now
     * @return Collection<DropSummary>
     */
    public function summaries(?int $now = null): Collection
    {
        $this->sweep($now ?? time());

        $summaries = [];

        foreach ($this->directory->files('*' . self::SUFFIX) as $file) {
            $header = self::headerOf($file);

            if ($header !== null) {
                $summaries[] = new DropSummary(substr($file->name(), 0, -strlen(self::SUFFIX)), $header, $file->size());
            }
        }

        usort(
            $summaries,
            static fn(DropSummary $a, DropSummary $b): int => $a->header->created <=> $b->header->created,
        );

        return new Collection(DropSummary::class)->with(...$summaries);
    }

    /**
     * Whether a drop is kept as $id.
     *
     * @param string $id
     * @return bool
     */
    public function has(string $id): bool
    {
        return self::isId($id) && $this->file($id)->exists();
    }

    /**
     * Takes the drop kept as $id away.
     *
     * @param string $id
     * @return bool False where one is there and could not be removed. None there is a success.
     */
    public function revoke(string $id): bool
    {
        return !self::isId($id) || $this->file($id)->delete();
    }

    /**
     * Removes every drop gone by $now, every file here that is no drop's, and every claimed name a
     * request left behind.
     *
     * An enumerated delete of named files, one at a time, never a walk and never
     * {@link Directory::remove()} — only a name this store writes is ever asked about.
     *
     * @param int $now
     * @return int How many files were removed.
     */
    public function sweep(int $now): int
    {
        $removed = 0;

        foreach ($this->directory->files('*' . self::SUFFIX) as $file) {
            $header = self::headerOf($file);

            if (($header === null || $header->isExpired($now)) && $file->delete()) {
                $removed++;
            }
        }

        foreach ($this->directory->files('*' . self::CLAIMED) as $file) {
            $removed += $file->delete() ? 1 : 0;
        }

        return $removed;
    }

    /**
     * Claims $file for the one request that reads it: renamed out of the way, which one request alone
     * can do, then unlinked — the handle already open keeps its bytes.
     *
     * @param File $file
     * @return bool False where another request claimed it first, or it was taken away.
     */
    private function claim(File $file): bool
    {
        $claimed = $this->directory->file($file->name() . '.' . bin2hex(random_bytes(4)) . self::CLAIMED);

        if (!$file->moveOnto($claimed)) {
            return false;
        }

        // Where the unlink fails, the claimed name is swept with the next touch of the store: nothing
        // opens at it, since its name is no drop's.
        $claimed->delete();

        return true;
    }

    /**
     * The header $file begins with, or null where it begins with none.
     *
     * @param File $file
     * @return DropHeader|null
     */
    private static function headerOf(File $file): ?DropHeader
    {
        return DropHeader::read((string) $file->read(DropHeader::LENGTH));
    }

    /**
     * The file the drop kept as $id is.
     *
     * @param string $id
     * @return File
     */
    private function file(string $id): File
    {
        return $this->directory->file($id . self::SUFFIX);
    }
}
