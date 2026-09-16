<?php

declare(strict_types=1);

namespace Phpanta\Model\Drop;

use JsonException;
use Phpanta\App;
use Phpanta\CredentialFile;
use Phpanta\Service\ApiGate;
use Phpanta\Support\File;
use stdClass;

/**
 * The DropConfig class. What `data/drop.json` switches on: the `drop` service, and how much it keeps
 * and for how long.
 *
 * **Absent is off, and so is anything that does not read** — {@link CredentialFile::Drop}, with
 * {@link \Phpanta\Model\Machine\MachineConfig}'s polarity. No file, JSON that does not parse, a
 * setting that is not a whole number or is out of range: each is null, which the admin reads as a
 * service that is not there and `/drop` as an address that is not there. A setting left out takes its
 * default, so `{}` is the service switched on with both.
 */
final readonly class DropConfig
{
    /**
     * The largest drop kept where the file does not say: the most a signed call may carry, so the
     * signing commands and a browser meet the same limit.
     */
    public const int MAX_BYTES = ApiGate::MAX_BODY;

    /** The longest a drop may be kept where the file does not say: a week. */
    public const int MAX_LIFETIME = 604_800;

    /** How long a drop is kept when its maker does not say: a day, or the most, where that is less. */
    public const int LIFETIME = 86_400;

    /** How deep the file may nest: an object of numbers. */
    private const int MAX_DEPTH = 2;

    /**
     * Constructs an instance of {@link self}.
     *
     * @param int $maxBytes    The largest drop kept, in bytes; at least one.
     * @param int $maxLifetime The longest one is kept, in seconds; at least {@link DropLifetime::SHORTEST}.
     */
    private function __construct(public int $maxBytes, public int $maxLifetime) {}

    /**
     * What this deployment's `data/drop.json` says, or null where the service is off.
     *
     * @param File|null $file A test seam; null is the deployment's own.
     * @return self|null
     */
    public static function current(?File $file = null): ?self
    {
        $json = ($file ?? App::current()->dataFile(CredentialFile::Drop))->read();

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

        $bytes    = $values->{DropSetting::MaxBytes->value} ?? self::MAX_BYTES;
        $lifetime = $values->{DropSetting::MaxLifetime->value} ?? self::MAX_LIFETIME;

        return is_int($bytes) && is_int($lifetime) && $bytes >= 1 && $lifetime >= DropLifetime::SHORTEST
            ? new self($bytes, $lifetime)
            : null;
    }

    /**
     * How long a drop is kept when its maker does not say.
     *
     * @return int Seconds.
     */
    public function defaultLifetime(): int
    {
        return min(self::LIFETIME, $this->maxLifetime);
    }
}
