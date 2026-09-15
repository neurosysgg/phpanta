<?php

declare(strict_types=1);

namespace Phpanta\Model\Machine;

use JsonException;
use Phpanta\App;
use Phpanta\CredentialFile;
use Phpanta\Exception\SecurityPolicyException;
use Phpanta\Http\Origin;
use Phpanta\Support\Collection;
use Phpanta\Support\Directory;
use Phpanta\Support\File;
use stdClass;

/**
 * The MachineConfig class. What `data/machine.json` switches on: the admin's `machine` service, the
 * roots it may walk, and whether it may write there or run a command.
 *
 * **Absent is off, and so is anything that does not read.** The file is
 * {@link CredentialFile::Machine}, and every way it can be wrong collapses to null — no file, JSON
 * that does not parse, a root that is not a string, a switch that is not a bool, or roots of which
 * none is a directory here — which the service reads as not being there at all. A setting left out
 * is the closed one: no writes, no commands. A typo therefore closes something rather than opening
 * it, and `capability v1 deployment` says whether the file is there.
 *
 * **A root is resolved when the file is read**, through `realpath()`, so a root given through a link
 * is the directory the link points at, and a path is under it by what it resolves to — see
 * {@link MachinePath}. A root that is not a directory here is dropped rather than refused, so one file
 * can name `/home` and `D:/` and serve on either kind of machine.
 */
final readonly class MachineConfig
{
    /** How deep the file may nest: an object holding one list. */
    private const int MAX_DEPTH = 4;

    /**
     * Constructs an instance of {@link self}.
     *
     * @param Collection<Directory> $roots    Each resolved, each a directory; never empty.
     * @param bool                  $writes   Whether the service may change what is under them.
     * @param bool                  $commands Whether it may run a command — only ever with writes.
     * @param Origin|null           $origin   Where an app serving only this service is served from.
     */
    private function __construct(
        private Collection $roots,
        public bool        $writes,
        public bool        $commands,
        public ?Origin     $origin,
    ) {}

    /**
     * What this deployment's `data/machine.json` says, or null where the service is off.
     *
     * @param File|null $file A test seam; null is the deployment's own.
     * @return self|null
     */
    public static function current(?File $file = null): ?self
    {
        $json = ($file ?? App::current()->dataFile(CredentialFile::Machine))->read();

        return $json === null ? null : self::parse($json);
    }

    /**
     * What $json says, or null where it does not say it readably.
     *
     * @param string $json
     * @return self|null
     */
    public static function parse(string $json): ?self
    {
        try {
            $values = json_decode($json, false, self::MAX_DEPTH, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        if (!$values instanceof stdClass) {
            return null;
        }

        $roots    = self::resolved($values->{MachineSetting::Roots->value} ?? null);
        $writes   = $values->{MachineSetting::Writes->value} ?? false;
        $commands = $values->{MachineSetting::Commands->value} ?? false;
        $origin   = $values->{MachineSetting::Origin->value} ?? null;

        if ($roots === null || !is_bool($writes) || !is_bool($commands) || ($origin !== null && !is_string($origin))) {
            return null;
        }

        try {
            $origin = $origin === null ? null : Origin::of($origin);
        } catch (SecurityPolicyException) {
            return null;
        }

        return new self($roots, $writes, $writes && $commands, $origin);
    }

    /**
     * The directories the service may walk, in the order the file names them.
     *
     * @return Collection<Directory>
     */
    public function roots(): Collection
    {
        return $this->roots;
    }

    /**
     * The roots $listed names that are directories here, resolved — or null where $listed is not a
     * list of strings, or names none that is.
     *
     * @param mixed $listed
     * @return Collection<Directory>|null
     */
    private static function resolved(mixed $listed): ?Collection
    {
        if (!is_array($listed)) {
            return null;
        }

        $roots = new Collection(Directory::class);

        foreach ($listed as $root) {
            if (!is_string($root)) {
                return null;
            }

            $real = realpath($root);

            if ($real !== false && is_dir($real)) {
                $roots = $roots->with(new Directory(MachinePath::normalised($real)));
            }
        }

        return $roots->isEmpty() ? null : $roots;
    }
}
