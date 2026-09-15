<?php

declare(strict_types=1);

namespace Phpanta\Model\Machine;

use JsonSerializable;
use Phpanta\Http\Api\ResultKey;
use Phpanta\Support\BareArray;
use Phpanta\Support\Collection;
use Phpanta\Support\Diagnostics;

/**
 * The MachineEntry class. One entry of a directory, as `lstat()` saw it: its name, its kind, its
 * size, when it last changed, its permissions and its owner — and, for a link, where it leads.
 */
final readonly class MachineEntry implements JsonSerializable
{
    /**
     * Constructs an instance of {@link self}.
     *
     * @param string      $name     The entry's name in its directory.
     * @param string      $path     Its whole path.
     * @param EntryKind   $kind     What it is, a link not followed.
     * @param int         $size     Its size in bytes.
     * @param int         $modified When its contents last changed.
     * @param int         $mode     Its permission bits.
     * @param string      $owner    Who owns it, by name where the machine can say, else by number.
     * @param string|null $target   Where a link leads, as the link says it; null for anything else.
     * @param bool        $opens    Whether it is a directory to open, a link to one included.
     */
    public function __construct(
        public string    $name,
        public string    $path,
        public EntryKind $kind,
        public int       $size,
        public int       $modified,
        public int       $mode,
        public string    $owner,
        public ?string   $target = null,
        public bool      $opens = false,
    ) {}

    /**
     * The entry at $path, or null where nothing is there to `lstat()`.
     *
     * @param string $path
     * @return self|null
     */
    public static function at(string $path): ?self
    {
        $stat = Diagnostics::muted(
            #[BareArray('lstat() answers in an array, or false: this is the door it comes through')]
            static fn(): array|false => lstat($path),
        );

        if ($stat === false) {
            return null;
        }

        // lstat()'s numbered half: 2 the mode, 4 the owner, 7 the size, 9 when it last changed.
        $kind   = EntryKind::ofMode($stat[2]);
        $target = $kind === EntryKind::Link ? Diagnostics::muted(static fn(): string|false => readlink($path)) : false;
        $name   = basename($path);

        return new self(
            $name === '' ? $path : $name,
            $path,
            $kind,
            $stat[7],
            $stat[9],
            $stat[2] & 0o7777,
            self::owner($stat[4]),
            $target === false ? null : $target,
            is_dir($path),
        );
    }

    /**
     * Every entry in the directory at $path — directories first, then by name, as a person reads a
     * listing — or none where it cannot be read.
     *
     * @param string $path
     * @return Collection<self>
     */
    public static function in(string $path): Collection
    {
        $names = Diagnostics::muted(
            #[BareArray('scandir() answers in an array, or false: this is the door it comes through')]
            static fn(): array|false => scandir($path),
        );

        $entries = [];

        foreach ($names === false ? [] : $names as $name) {
            $entry = $name === '.' || $name === '..' ? null : self::at(rtrim($path, '/') . '/' . $name);

            if ($entry !== null) {
                $entries[] = $entry;
            }
        }

        usort($entries, static fn(self $a, self $b): int => [!$a->opens, strnatcasecmp($a->name, $b->name)]
            <=> [!$b->opens, strnatcasecmp($b->name, $a->name)]);

        return new Collection(self::class)->with(...$entries);
    }

    /**
     * Who $uid is, by name where the machine can say — the number where it cannot.
     *
     * @param int $uid
     * @return string
     */
    public static function owner(int $uid): string
    {
        $user = function_exists('posix_getpwuid') ? posix_getpwuid($uid) : false;

        // A passwd entry's first field is the user's name, as the C library orders it.
        return $user === false ? (string) $uid : (string) reset($user);
    }

    /**
     * This entry under another name — a root, listed by its whole path.
     *
     * @param string $name
     * @return self
     */
    public function named(string $name): self
    {
        return new self(
            $name,
            $this->path,
            $this->kind,
            $this->size,
            $this->modified,
            $this->mode,
            $this->owner,
            $this->target,
            $this->opens,
        );
    }

    /**
     * The permissions as `ls -l` writes them — `drwxr-xr-x`.
     *
     * @return string
     */
    public function permissions(): string
    {
        $bits = '';

        foreach ([0o400, 0o200, 0o100, 0o040, 0o020, 0o010, 0o004, 0o002, 0o001] as $index => $bit) {
            $bits .= ($this->mode & $bit) !== 0 ? 'rwx'[$index % 3] : '-';
        }

        return $this->kind->mark() . $bits;
    }

    /**
     * The entry as a line of a listing: permissions, size, when, owner, name — and a link's target.
     *
     * @return string
     */
    public function line(): string
    {
        return sprintf(
            '%s %10s  %s  %-10s %s%s',
            $this->permissions(),
            $this->kind === EntryKind::Directory ? '-' : Measure::bytes($this->size),
            Measure::moment($this->modified),
            $this->owner,
            $this->name,
            $this->target === null ? '' : ' -> ' . $this->target,
        );
    }

    /**
     * @return mixed
     */
    public function jsonSerialize(): mixed
    {
        return [
            ResultKey::Name->value     => $this->name,
            ResultKey::Kind->value     => $this->kind->value,
            ResultKey::Size->value     => $this->size,
            ResultKey::Modified->value => date(DATE_ATOM, $this->modified),
            ResultKey::Mode->value     => $this->permissions(),
            ResultKey::Owner->value    => $this->owner,
            ResultKey::Target->value   => $this->target,
        ];
    }
}
