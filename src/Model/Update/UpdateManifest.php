<?php

declare(strict_types=1);

namespace Phpanta\Model\Update;

use JsonException;
use Phpanta\Exception\UpdateException;

/**
 * The UpdateManifest class. What a push asks for, on top of what every signed request claims.
 *
 * **It reads the same bytes {@link \Phpanta\Model\Api\ApiEnvelope} read, and takes the half that
 * belongs to this one action.** The envelope is about the request — who signed it, for which
 * method and path, over which body — and is checked before anything is resolved; these two are
 * about what to do once it has been, and are meaningless to a read. Splitting them is what let the
 * envelope become general without `apply` and `mirror` following it into every action that will
 * never have an opinion about either.
 *
 * Two parses of one document rather than one parse handing out an array, which is the trade worth
 * naming: it costs a second `json_decode` of about two hundred bytes, and it buys each half
 * reading only what it owns, with no `array` crossing a boundary and no field arriving somewhere it
 * has no meaning. The bytes are the signed ones both times — never a re-encoding of the parsed
 * form, since JSON has more than one way to write the same object.
 *
 * It is parsed strictly. Every key must be present and of the right type; there are no defaults and
 * no coercions, because a missing `mirror` defaulting to false would be an update that silently
 * stopped deleting, and a missing `apply` defaulting to true would be a dry run that was not one.
 * {@link \NeuroSYS\Tool\Http\JsonBody} on the tooling side answers `''` and `0` for an absent key,
 * which is right for reading somebody else's API and wrong for reading a security boundary.
 */
final readonly class UpdateManifest
{
    /**
     * How deep the manifest JSON may nest. The envelope's reason, and the same number, since this
     * reads the same document.
     */
    private const int MAX_DEPTH = 8;

    /**
     * Constructs an instance of {@link self}.
     *
     * @param bool $apply False for a dry run: validate everything, write nothing, advance nothing.
     * @param bool $mirror True to delete what the payload omits.
     */
    private function __construct(
        public bool $apply,
        public bool $mirror,
    ) {}

    /**
     * Parses a push's own fields out of the signed manifest.
     *
     * `json_decode`'s array stays a local and is never a declared type, which is the whole point of
     * the method: it is the door, and nothing past it is an array.
     *
     * @param string $json The exact bytes the signature was checked against.
     * @return self
     *
     * @throws UpdateException if the JSON is unreadable or either field is missing or mistyped.
     */
    public static function parse(string $json): self
    {
        try {
            /** @var mixed $values */
            $values = json_decode($json, true, self::MAX_DEPTH, JSON_THROW_ON_ERROR);
        } catch (JsonException $cause) {
            throw new UpdateException(
                'the update manifest is not readable JSON: ' . $cause->getMessage(),
                previous: $cause,
            );
        }

        if (!is_array($values)) {
            throw new UpdateException('the update manifest is not a JSON object');
        }

        $apply  = $values['apply']  ?? null;
        $mirror = $values['mirror'] ?? null;

        if (!is_bool($apply) || !is_bool($mirror)) {
            throw new UpdateException(
                'the update manifest must carry apply:bool and mirror:bool, both present and both '
                . 'of those types',
            );
        }

        return new self($apply, $mirror);
    }
}
