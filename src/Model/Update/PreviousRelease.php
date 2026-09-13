<?php

declare(strict_types=1);

namespace Phpanta\Model\Update;

use NoDiscard;
use Phpanta\Exception\UpdateException;
use Phpanta\Support\Collection;
use Phpanta\Support\SearchableCollection;

/**
 * The PreviousRelease class. The index of the record a push takes of the release it replaces: which
 * push took it, whether it was finished, and one {@link RecordEntry} per path the push changed.
 *
 * **The index is written twice, and the second write is the commit.** A push writes it first marked
 * incomplete, then saves each file, then writes it again marked complete — each write a
 * {@link \Phpanta\Support\File::write()}, so each lands whole. An incomplete index is therefore
 * what a push that died halfway through recording leaves behind: it still lists every name the
 * record may hold, which is what lets the next push clear the record by enumeration, and a rollback
 * refuses it, since the push that began it was refused before it wrote anything live.
 *
 * It is a text file a person can read over a mount — a header, the serial, the state, then a line
 * per path — because the day it is needed by hand is the day nothing else is working.
 */
final readonly class PreviousRelease
{
    /** The first line, naming the format so a later one can refuse this one rather than misread it. */
    private const string HEADER = 'phpanta previous-release 1';

    /** The three lines every index opens with. */
    private const string HEAD = "%s\nserial %s\nstate %s\n";

    /** The second line. A serial or a dash, never zero — see {@link \Phpanta\Service\Api\UpdateVersion}. */
    private const string SERIAL = '#\Aserial (-|[1-9][0-9]{0,18})\z#';

    /** The third line. */
    private const string STATE = '#\Astate (complete|incomplete)\z#';

    /**
     * Constructs an instance of {@link self}.
     *
     * @param int|null $serial The serial of the push that took the record, where it had one.
     * @param bool $complete True once every saved file is on disk. See the class docblock.
     * @param Collection<RecordEntry> $entries In the order the push wrote them, then the order the
     *                                         mirror deleted.
     */
    public function __construct(
        public ?int       $serial,
        public bool       $complete,
        public Collection $entries,
    ) {}

    /**
     * A copy marked complete.
     *
     * @return self
     */
    #[NoDiscard('the index copies rather than changing; a dropped call leaves the record incomplete')]
    public function completed(): self
    {
        return new self($this->serial, true, $this->entries);
    }

    /**
     * The entries whose earlier bytes the record keeps a copy of.
     *
     * @return Collection<RecordEntry>
     */
    public function saved(): Collection
    {
        return $this->entries->where(static fn(RecordEntry $entry): bool => $entry->kind->saves());
    }

    /**
     * The index as it is written to disk.
     *
     * @return string
     */
    public function render(): string
    {
        $text = sprintf(self::HEAD, self::HEADER, $this->serial ?? '-', $this->complete ? 'complete' : 'incomplete');

        foreach ($this->entries as $entry) {
            $text .= $entry->render() . "\n";
        }

        return $text;
    }

    /**
     * An index read back from disk.
     *
     * @param string $text
     * @return self
     *
     * @throws UpdateException if the text is not an index this class writes, or any line in it is
     *                         refused by {@link RecordEntry::parse()}, or a name appears twice.
     */
    public static function parse(string $text): self
    {
        // Cut short is refused rather than read as a shorter record: File::write() lands whole, so a
        // missing last newline is an index somebody else wrote.
        $lines = str_ends_with($text, "\n") ? explode("\n", substr($text, 0, -1)) : [];

        if (
            count($lines) < 3
            || $lines[0] !== self::HEADER
            || preg_match(self::SERIAL, $lines[1], $serial) !== 1
            || preg_match(self::STATE, $lines[2], $state) !== 1
        ) {
            throw new UpdateException('the record of the previous release does not open the way this class writes one');
        }

        $entries = new SearchableCollection(RecordEntry::class);

        foreach (array_slice($lines, 3) as $line) {
            $entry = RecordEntry::parse($line);

            // A name twice would be restored twice or restored and removed, and which came last
            // would decide what the deployment ends up holding.
            if ($entries->find($entry->name) !== null) {
                throw new UpdateException(sprintf(
                    "the record of the previous release names '%s' twice",
                    $entry->name,
                ));
            }

            $entries = $entries->with($entry->name, $entry);
        }

        return new self(
            $serial[1] === '-' ? null : (int) $serial[1],
            $state[1] === 'complete',
            new Collection(RecordEntry::class)->with(...$entries->toValues()),
        );
    }
}
