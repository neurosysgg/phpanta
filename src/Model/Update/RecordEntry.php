<?php

declare(strict_types=1);

namespace Phpanta\Model\Update;

use Phpanta\Exception\UpdateException;
use Phpanta\Service\UpdateApplier;

/**
 * The RecordEntry class. One path in the record of the previous release: what the push did to it,
 * and the two states it moved the path between.
 *
 * **Both states are kept as digests, and neither is optional where it exists.** `before` is what
 * was on disk ahead of the push — its bytes are also saved, and the digest is what proves the saved
 * copy is still those bytes. `after` is what the push wrote there; the bytes are not kept, since
 * they are the payload, but the digest is what lets a rollback ask whether the path still holds
 * them. A path whose current bytes match neither is a path something else has changed since, and
 * {@link self::step()} answers {@link RollbackStep::Conflict} for it.
 *
 * **It is built from names that have already passed every rule a pushed member passes**, and a line
 * read back off disk passes them again in {@link self::parse()} through
 * {@link UpdateApplier::claim()} — so a record whose index was edited to name `data/` or `../`
 * is refused rather than followed. The root is carried resolved, for {@link UpdateFile}'s reason.
 */
final readonly class RecordEntry
{
    /**
     * The digest both states are kept as.
     *
     * SHA-384 rather than the SHA-256 the API's envelope uses, only so that the two are not one
     * literal in two files standing for two unrelated facts; either is far past what comparing a
     * file to itself needs.
     */
    private const string ALGORITHM = 'sha384';

    /** How a state that does not exist — no file before an add, none after a delete — is written. */
    private const string NONE = '-';

    /** One line of the index: kind, before, after, name, one space apart. */
    private const string LINE = '#\A([a-z]+) ([0-9a-f]{96}|-) ([0-9a-f]{96}|-) (\S+)\z#';

    /** Who holds a name, in the sentence a refused name is reported in. */
    private const string HOLDER = 'the record of the previous release';

    /**
     * Constructs an instance of {@link self}.
     *
     * @param RecordKind $kind
     * @param UpdateRoot $root The root the name falls under, resolved and no longer in question.
     * @param string $name The payload's own name for the path, prefix included.
     * @param string|null $before The digest of the bytes before the push; null where there were none.
     * @param string|null $after The digest of the bytes the push wrote; null where it deleted the path.
     */
    private function __construct(
        public RecordKind $kind,
        public UpdateRoot $root,
        public string     $name,
        public ?string    $before,
        public ?string    $after,
    ) {}

    /**
     * A file the push overwrote.
     *
     * @param UpdateRoot $root
     * @param string $name
     * @param string $before The bytes that were there.
     * @param string $after The bytes the push writes.
     * @return self
     */
    public static function changed(UpdateRoot $root, string $name, string $before, string $after): self
    {
        return new self(RecordKind::Changed, $root, $name, self::digest($before), self::digest($after));
    }

    /**
     * A file the push wrote where there was none.
     *
     * @param UpdateRoot $root
     * @param string $name
     * @param string $after The bytes the push writes.
     * @return self
     */
    public static function added(UpdateRoot $root, string $name, string $after): self
    {
        return new self(RecordKind::Added, $root, $name, null, self::digest($after));
    }

    /**
     * A file the mirror deletes.
     *
     * @param UpdateRoot $root
     * @param string $name
     * @param string $before The bytes that were there.
     * @return self
     */
    public static function deleted(UpdateRoot $root, string $name, string $before): self
    {
        return new self(RecordKind::Deleted, $root, $name, self::digest($before), null);
    }

    /**
     * The digest a state is kept as.
     *
     * @param string $bytes
     * @return string
     */
    public static function digest(string $bytes): string
    {
        return hash(self::ALGORITHM, $bytes);
    }

    /**
     * What a rollback does with this path, given the digest of what is there now.
     *
     * One `match` for all three kinds, because the kinds differ only in which state is absent: a
     * path the push added has no `before`, so "nothing there" is already the state a rollback wants;
     * a path the mirror deleted has no `after`, so "nothing there" is the state the push left.
     *
     * @param string|null $current The digest of the file there now, or null where there is none.
     * @return RollbackStep
     */
    public function step(?string $current): RollbackStep
    {
        return match (true) {
            $current === $this->after  => $this->kind === RecordKind::Added
                ? RollbackStep::Remove
                : RollbackStep::Restore,
            $current === $this->before => RollbackStep::Keep,
            default                    => RollbackStep::Conflict,
        };
    }

    /**
     * The entry as one line of the index, without its newline.
     *
     * @return string
     */
    public function render(): string
    {
        return implode(' ', [$this->kind->value, $this->before ?? self::NONE, $this->after ?? self::NONE, $this->name]);
    }

    /**
     * An entry read back from one line of the index.
     *
     * **Parsed as strictly as the payload it came from**, because the file it is read from sits on
     * the server where anything with the account's access could have edited it, and a rollback
     * writes and deletes where it points. The name goes through every rule a pushed member does; the
     * digests must be present exactly where the kind says a state exists.
     *
     * @param string $line
     * @return self
     *
     * @throws UpdateException if the line is not one this class writes, or names a path a push could
     *                         not have written.
     */
    public static function parse(string $line): self
    {
        if (preg_match(self::LINE, $line, $fields) !== 1) {
            throw new UpdateException(sprintf("%s holds a line it cannot read: '%s'", self::HOLDER, $line));
        }

        $kind   = RecordKind::tryFrom($fields[1]);
        $before = $fields[2] === self::NONE ? null : $fields[2];
        $after  = $fields[3] === self::NONE ? null : $fields[3];

        // A saved kind has a before and a deletion has no after; anything else is a line this class
        // never writes, whatever its shape.
        if (
            $kind === null
            || ($before !== null) !== $kind->saves()
            || ($after === null) !== ($kind === RecordKind::Deleted)
        ) {
            throw new UpdateException(sprintf(
                "%s holds a line whose kind and states do not agree: '%s'",
                self::HOLDER,
                $line,
            ));
        }

        return new self($kind, UpdateApplier::claim($fields[4], self::HOLDER), $fields[4], $before, $after);
    }
}
