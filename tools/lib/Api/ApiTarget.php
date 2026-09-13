<?php

declare(strict_types=1);

namespace Phpanta\Tool\Api;

use InvalidArgumentException;
use Phpanta\Support\File;
use Phpanta\Tool\Cli\UsageException;
use Phpanta\Tool\Http\Url;

/**
 * The ApiTarget class. Which deployment a signed call goes to, and which key signs for it.
 *
 * **One key per deployment, and this is where that stops being a convention.** The server's replay
 * guard is a counter per deployment, and a signed manifest names a method and a path but no host —
 * deliberately, see {@link \Phpanta\Model\Api\ApiEnvelope} — so a credential minted for one
 * deployment verifies at any other holding the same public key, for as long as its serial is fresh
 * there. Two deployments sharing a key share every credential, a push included. So they never do:
 *
 * - The default origin signs with the default key.
 * - Any other origin signs with a key of its own — by default the sibling `update-<host>.key` — and
 *   **the default key is refused for it** even when named with `--key`, compared by content so a
 *   copy under another name is refused as well.
 *
 * `ApiCall` and `PushUpdate` both resolve their target here, so the rule has one spelling.
 */
final readonly class ApiTarget
{
    /**
     * Constructs an instance of {@link self}.
     *
     * @param Url  $origin The deployment, as a bare origin.
     * @param File $key    The private key that signs for it.
     */
    private function __construct(public Url $origin, public File $key) {}

    /**
     * The target a command line names.
     *
     * @param string|null $url            The `--url` given, or null for the default origin.
     * @param string|null $key            The `--key` given, or null for the origin's own key.
     * @param string      $defaultOrigin  The deployment a call goes to unless told otherwise.
     * @param string      $defaultKeyPath That deployment's key, relative to $home.
     * @param string|null $home           Where a relative key path hangs — `$HOME`, see {@link self::home()}.
     * @return self
     *
     * @throws UsageException if the origin is not one, a key cannot be located, or the default key is
     *                        named for a deployment that is not the default one.
     */
    public static function resolve(
        ?string $url,
        ?string $key,
        string $defaultOrigin,
        string $defaultKeyPath,
        ?string $home,
    ): self {
        try {
            $origin  = Url::origin($url ?? $defaultOrigin);
            $default = Url::origin($defaultOrigin);
        } catch (InvalidArgumentException $exception) {
            throw new UsageException($exception->getMessage());
        }

        $defaultKey = $home === null ? null : new File($home . '/' . $defaultKeyPath);
        $given      = $key === null ? null : new File($key);

        if ($origin->render() === $default->render()) {
            return new self($origin, $given ?? $defaultKey ?? throw self::homeless());
        }

        $own = $given ?? ($defaultKey === null ? throw self::homeless() : self::siblingOf($defaultKey, $origin));

        if ($defaultKey !== null && self::same($own, $defaultKey)) {
            $mint = self::siblingOf($defaultKey, $origin);

            throw new UsageException(sprintf(
                "%s is the key for %s, and %s needs a key of its own — two deployments sharing one "
                . "share every credential, a push included. Mint a pair for it:\n%s",
                $own->path,
                $default->render(),
                $origin->render(),
                PrivateKey::generation($mint),
            ));
        }

        return new self($origin, $own);
    }

    /**
     * `$HOME`, or null where it is not set.
     *
     * @return string|null
     */
    public static function home(): ?string
    {
        $home = getenv('HOME');

        return is_string($home) && $home !== '' ? $home : null;
    }

    /**
     * The key a non-default origin signs with unless told otherwise: `update.key` becomes
     * `update-<host>.key` beside it, a port joining the host with a dash.
     *
     * @param File $key
     * @param Url  $origin
     * @return File
     */
    public static function siblingOf(File $key, Url $origin): File
    {
        $extension = pathinfo($key->path, PATHINFO_EXTENSION);
        $name      = pathinfo($key->path, PATHINFO_FILENAME) . '-' . str_replace(':', '-', $origin->authority());

        return new File($key->directory()->path . '/' . $name . ($extension === '' ? '' : '.' . $extension));
    }

    /**
     * Whether $a is $b — the same path, or the same bytes under another name.
     *
     * @param File $a
     * @param File $b
     * @return bool
     */
    private static function same(File $a, File $b): bool
    {
        if ($a->path === $b->path) {
            return true;
        }

        $mine = $a->read();

        return $mine !== null && $mine === $b->read();
    }

    /**
     * @return UsageException
     */
    private static function homeless(): UsageException
    {
        return new UsageException('HOME is not set, so --key must name the private key.');
    }
}
