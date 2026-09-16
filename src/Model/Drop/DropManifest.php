<?php

declare(strict_types=1);

namespace Phpanta\Model\Drop;

use JsonException;
use Phpanta\Exception\ApiException;
use Phpanta\Http\Api\ActionField;
use Phpanta\Http\Api\DropAction;
use stdClass;

/**
 * The DropManifest class. What a `drop` write asks for beside its address: whether to carry it out,
 * and — to make one — its text, the name it is saved under, how long it is kept, whether it opens
 * once, and a password.
 *
 * Read out of the signed bytes — or out of a browser's form, which
 * {@link \Phpanta\Service\Passkey\AdminBrowser} puts into the same shape — strictly: `apply` must be a
 * bool, and every other field, where it is there at all, what it may be. A field left out is the
 * empty one, since each of them is optional: a signed call sends its bytes as its body, and a browser
 * sends a file or text.
 */
final readonly class DropManifest
{
    /** How deep the manifest JSON may nest. The envelope's reason, and the same number. */
    private const int MAX_DEPTH = 8;

    /** The longest password taken, in bytes. */
    public const int MAX_PASSWORD = 1_024;

    /**
     * Constructs an instance of {@link self}.
     *
     * @param bool     $apply    False for a dry run: say what would be done, change nothing.
     * @param string   $text     The text to keep, where no body or file is sent.
     * @param string   $filename The name it is saved under; `''` for none.
     * @param int|null $lifetime How long it is kept, in seconds; null for the deployment's default.
     * @param bool     $once     Whether it is gone once it has been read.
     * @param string   $password A password it needs besides its link; `''` for none.
     */
    private function __construct(
        public bool    $apply,
        public string  $text = '',
        public string  $filename = '',
        public ?int    $lifetime = null,
        public bool    $once = false,
        public string  $password = '',
    ) {}

    /**
     * What $json asks $action for.
     *
     * @param string     $json   The exact bytes the signature was checked against.
     * @param DropAction $action
     * @return self
     * @throws ApiException if the manifest does not read, or carries something its action cannot take.
     */
    public static function parse(string $json, DropAction $action): self
    {
        try {
            $values = json_decode($json, false, self::MAX_DEPTH, JSON_THROW_ON_ERROR);
        } catch (JsonException $cause) {
            throw new ApiException(sprintf('what drop %s was sent is not JSON', $action->value), previous: $cause);
        }

        if (!$values instanceof stdClass) {
            throw new ApiException(sprintf('what drop %s was sent is no JSON object', $action->value));
        }

        $apply = $values->{ActionField::Apply->value} ?? null;

        if (!is_bool($apply)) {
            throw new ApiException(sprintf('drop %s needs apply, true or false', $action->value));
        }

        if ($action !== DropAction::Create) {
            return new self($apply);
        }

        $text     = $values->{ActionField::Text->value} ?? '';
        $filename = $values->{ActionField::Filename->value} ?? '';
        $lifetime = $values->{ActionField::Lifetime->value} ?? '';
        $once     = $values->{ActionField::Once->value} ?? false;
        $password = $values->{ActionField::Password->value} ?? '';

        $typed = is_string($text)
            && is_string($filename)
            && is_string($lifetime)
            && is_string($password)
            && is_bool($once);

        if (!$typed) {
            throw new ApiException(
                'the create manifest carries text, filename, lifetime and password as text, and once as true or false',
            );
        }

        if ($filename !== '' && !DropMeta::isName($filename)) {
            throw new ApiException(
                'a filename is one segment of UTF-8, not . or .., with no slash, backslash or control character',
            );
        }

        $seconds = $lifetime === '' ? null : DropLifetime::parse(trim($lifetime));

        if ($lifetime !== '' && $seconds === null) {
            throw new ApiException(sprintf(
                'a lifetime is a number of seconds, or of minutes, hours or days — 90, 30m, 12h, 7d — '
                . 'and at least %d seconds',
                DropLifetime::SHORTEST,
            ));
        }

        if (strlen($password) > self::MAX_PASSWORD) {
            throw new ApiException(sprintf('a password is %d bytes at most', self::MAX_PASSWORD));
        }

        return new self($apply, $text, $filename, $seconds, $once, $password);
    }
}
