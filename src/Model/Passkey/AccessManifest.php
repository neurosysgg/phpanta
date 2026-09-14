<?php

declare(strict_types=1);

namespace Phpanta\Model\Passkey;

use JsonException;
use Phpanta\Exception\ApiException;
use Phpanta\Http\Api\ActionField;
use Phpanta\Support\Base64Url;
use stdClass;

/**
 * The AccessManifest class. What an `access` write asks for, read out of the signed bytes: whether to
 * apply it, and the enrolment code and name, or the passkey to revoke.
 *
 * The {@link \Phpanta\Model\Update\ApplyManifest} of the `access` service, and read the same way — the
 * exact bytes the signature covered, never a re-encoding — so a field can only have come from the key
 * holder. Every refusal is a sentence the verified caller is shown.
 */
final readonly class AccessManifest
{
    /** The envelope's depth, since this reads the same document. */
    private const int MAX_DEPTH = 8;

    /** More than any sealed code is, and a bound on what is handed to the seal. */
    private const int MAX_CODE = 4096;

    /** A name: one to sixty-four characters, none of them a control character. */
    private const string NAME = '/\A[^\p{Cc}]{1,64}\z/u';

    /**
     * Constructs an instance of {@link self}.
     *
     * @param bool   $apply   False for a dry run.
     * @param string $code    An enrolment's code; empty for a revocation.
     * @param string $name    An enrolment's name, trimmed; empty for a revocation.
     * @param string $passkey A revocation's credential id; empty for an enrolment.
     */
    private function __construct(
        public bool   $apply,
        public string $code = '',
        public string $name = '',
        public string $passkey = '',
    ) {}

    /**
     * An enrolment's fields: `apply`, `code` and `name`.
     *
     * @param string $json   The manifest, exactly as signed.
     * @param string $action The action's name, for the sentence a refusal is.
     * @return self
     * @throws ApiException if the manifest is not an object, or a field is missing or not what it must be.
     */
    public static function enrolment(string $json, string $action): self
    {
        $values = self::values($json, $action);
        $code   = $values->{ActionField::Code->value} ?? null;
        $name   = $values->{ActionField::Name->value} ?? null;
        $name   = is_string($name) ? trim($name) : '';

        if (!is_string($code) || $code === '' || strlen($code) > self::MAX_CODE) {
            throw new ApiException(sprintf('the %s manifest must carry the code the entrance showed', $action));
        }

        if (preg_match(self::NAME, $name) !== 1) {
            throw new ApiException(sprintf(
                'the %s manifest must name the device in one to sixty-four characters, none a control character',
                $action,
            ));
        }

        return new self(self::apply($values, $action), $code, $name);
    }

    /**
     * A revocation's fields: `apply` and `passkey`.
     *
     * @param string $json
     * @param string $action
     * @return self
     * @throws ApiException if the manifest is not an object, or a field is missing or not what it must be.
     */
    public static function revocation(string $json, string $action): self
    {
        $values  = self::values($json, $action);
        $passkey = $values->{ActionField::Passkey->value} ?? null;

        if (!is_string($passkey) || $passkey === '' || Base64Url::decode($passkey) === null) {
            throw new ApiException(sprintf('the %s manifest must carry a credential id, as listed', $action));
        }

        return new self(self::apply($values, $action), passkey: $passkey);
    }

    /**
     * The manifest, decoded — refused where it is not an object.
     *
     * @param string $json
     * @param string $action
     * @return stdClass
     * @throws ApiException
     */
    private static function values(string $json, string $action): stdClass
    {
        try {
            $values = json_decode($json, false, self::MAX_DEPTH, JSON_THROW_ON_ERROR);
        } catch (JsonException $cause) {
            throw new ApiException(sprintf('the %s manifest does not read as JSON', $action), previous: $cause);
        }

        return $values instanceof stdClass
            ? $values
            : throw new ApiException(sprintf('the %s manifest is not an object', $action));
    }

    /**
     * Whether to apply — a bool, present.
     *
     * @param stdClass $values
     * @param string   $action
     * @return bool
     * @throws ApiException
     */
    private static function apply(stdClass $values, string $action): bool
    {
        $apply = $values->{ActionField::Apply->value} ?? null;

        return is_bool($apply)
            ? $apply
            : throw new ApiException(sprintf('the %s manifest must say, as a bool, whether to apply', $action));
    }
}
