<?php

declare(strict_types=1);

namespace Phpanta\Model\Machine;

use JsonException;
use Phpanta\Exception\ApiException;
use Phpanta\Http\Api\ActionField;
use Phpanta\Http\Api\MachineAction;
use Phpanta\Support\Collection;
use stdClass;

/**
 * The MachineManifest class. What a `machine` write asks for beside its address: whether to carry it
 * out, and the name or the command line it takes.
 *
 * Read out of the signed bytes — or out of a browser's form, which {@link
 * \Phpanta\Service\Passkey\AdminBrowser} puts into the same shape — strictly and with no default:
 * `apply` must be a bool, and a field the action takes must be there and be what it may be. A name is
 * one {@link MachinePath::isName()} accepts, so a write can never be pointed out of the directory its
 * address names. A command line is anything but a NUL, up to {@link self::MAX_COMMAND} bytes.
 */
final readonly class MachineManifest
{
    /** How deep the manifest JSON may nest. The envelope's reason, and the same number. */
    private const int MAX_DEPTH = 8;

    /** The longest command line taken, in bytes. */
    private const int MAX_COMMAND = 8192;

    /**
     * Constructs an instance of {@link self}.
     *
     * @param bool   $apply   False for a dry run: say what would be done, change nothing.
     * @param string $target  The name a directory or an entry is given, where the action takes one.
     * @param string $command The command line, where the action runs one.
     */
    private function __construct(public bool $apply, public string $target = '', public string $command = '') {}

    /**
     * What $json asks $action for.
     *
     * @param string        $json   The exact bytes the signature was checked against.
     * @param MachineAction $action
     * @return self
     * @throws ApiException if the manifest does not read, or does not carry what $action takes.
     */
    public static function parse(string $json, MachineAction $action): self
    {
        try {
            $values = json_decode($json, false, self::MAX_DEPTH, JSON_THROW_ON_ERROR);
        } catch (JsonException $cause) {
            throw new ApiException(sprintf('the %s manifest is not JSON', $action->value), previous: $cause);
        }

        if (!$values instanceof stdClass) {
            throw new ApiException(sprintf('the %s manifest holds no JSON object', $action->value));
        }

        $apply   = $values->{ActionField::Apply->value} ?? null;
        $target  = $values->{ActionField::Target->value} ?? '';
        $command = $values->{ActionField::Command->value} ?? '';
        $takes   = $action->fields();

        if (!is_bool($apply)) {
            throw new ApiException(sprintf('the %s manifest must carry apply, true or false', $action->value));
        }

        $badName    = !is_string($target) || !MachinePath::isName($target);
        $badCommand = !is_string($command)
            || trim($command) === ''
            || strlen($command) > self::MAX_COMMAND
            || str_contains($command, "\0");

        if (self::takes($takes, ActionField::Target) && $badName) {
            throw new ApiException(sprintf(
                'the %s manifest must carry a name: one segment, not . or .., with no slash, backslash or NUL',
                $action->value,
            ));
        }

        if (self::takes($takes, ActionField::Command) && $badCommand) {
            throw new ApiException(sprintf(
                'the %s manifest must carry a command line of up to %d bytes, with no NUL',
                $action->value,
                self::MAX_COMMAND,
            ));
        }

        return new self($apply, is_string($target) ? $target : '', is_string($command) ? $command : '');
    }

    /**
     * Whether $fields holds $field — the fields an action declares, asked whether it takes one.
     *
     * @param Collection<ActionField> $fields
     * @param ActionField             $field
     * @return bool
     */
    private static function takes(Collection $fields, ActionField $field): bool
    {
        return $fields->first(static fn(ActionField $each): bool => $each === $field) !== null;
    }
}
