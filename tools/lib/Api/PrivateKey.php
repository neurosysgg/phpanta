<?php

declare(strict_types=1);

namespace Phpanta\Tool\Api;

use OpenSSLAsymmetricKey;
use Phpanta\Support\Diagnostics;
use Phpanta\Support\File;
use Phpanta\Tool\Cli\UsageException;

/**
 * The PrivateKey class. The half of the pair that can make a signature.
 *
 * The counterpart to {@link \Phpanta\Support\PublicKey}, and deliberately on this side of the
 * boundary: a deploy uploads `src/` and never `tools/`, so the server holds a class that can
 * only ever *check* a signature and has no way to make one. **That asymmetry is the whole security
 * argument for `/api`**, and it is worth noticing that it is enforced by where the files are rather
 * than by any check in the code.
 *
 * **The private key is read and used here and nowhere else in this repository.** It never leaves
 * this machine and is never committed — a path like `~/.config/example/update.key` is outside the
 * repository entirely, which is the arrangement any API client's refresh token should have too, and
 * for the same reason: no `.gitignore` entry and no rsync `--exclude` is what stands between it and
 * a webroot.
 *
 * It is a class of its own rather than part of whatever builds a push, because a second signer —
 * one for a push, one for a read — would make the claim above false. One class signs; what is
 * signed is its caller's business.
 */
final readonly class PrivateKey
{
    /**
     * Constructs an instance of {@link self}.
     *
     * @param OpenSSLAsymmetricKey $key
     */
    private function __construct(private OpenSSLAsymmetricKey $key) {}

    /**
     * Reads the key at $file.
     *
     * @param File $file
     * @return self
     *
     * @throws UsageException if it is absent, unreadable, or not a PEM private key.
     */
    public static function fromFile(File $file): self
    {
        $pem = $file->read();

        if ($pem === null) {
            throw new UsageException(sprintf(
                "cannot read the private key at %s. Generate the pair with:\n%s",
                $file->path,
                self::generation($file),
            ));
        }

        // Refused rather than warned about, the way ssh refuses an unprotected identity: a key
        // anyone else on this machine can read is a key anyone else on this machine can push with.
        $mode = Diagnostics::muted(fn(): int|false => fileperms($file->path));

        if ($mode !== false && ($mode & 0o077) !== 0) {
            throw new UsageException(sprintf(
                '%s can be read by other users (mode %o) — `chmod 600` it before it signs anything',
                $file->path,
                $mode & 0o777,
            ));
        }

        $key = Diagnostics::muted(static fn(): mixed => openssl_pkey_get_private($pem));

        if (!$key instanceof OpenSSLAsymmetricKey) {
            throw new UsageException($file->path . ' is not a readable PEM private key');
        }

        return new self($key);
    }

    /**
     * The two commands that mint a key pair at $file, as lines to paste.
     *
     * Under `umask 077`, so the private half is never on disk at the default mode even for the
     * moment between writing it and a `chmod`. The public half goes to standard output, because
     * which deployment's `data/update.pub` it becomes is the reader's to say, not this one's.
     *
     * @param File $file
     * @return string
     */
    public static function generation(File $file): string
    {
        $path = escapeshellarg($file->path);

        return "  (umask 077; mkdir -p " . escapeshellarg($file->directory()->path)
            . " && openssl genpkey -algorithm EC -pkeyopt ec_paramgen_curve:P-256 -out $path)\n"
            . "  openssl pkey -in $path -pubout    # → that deployment's data/update.pub";
    }

    /**
     * The DER signature over $data.
     *
     * @param string $data The bytes to sign — exactly those, byte for byte. It is always a
     *                     manifest, and always the same bytes the credential then carries, since a
     *                     re-encoding is a second spelling of one fact and JSON has more than one
     *                     way to write the same object.
     * @return string
     *
     * @throws UsageException if the key cannot sign — in practice, if it is not EC.
     */
    public function sign(string $data): string
    {
        $signature = null;

        // A full closure with `use (&$signature)` rather than an arrow function: `openssl_sign()`
        // writes the signature into its second argument, and `fn()` captures by value — so an
        // arrow function would sign perfectly well and leave $signature null.
        $signed = Diagnostics::muted(function () use ($data, &$signature): bool {
            return openssl_sign($data, $signature, $this->key, OPENSSL_ALGO_SHA256);
        });

        if (!$signed || !is_string($signature)) {
            throw new UsageException(
                'could not sign the manifest. The key must be an EC P-256 key: Ed25519 does not '
                . "work through PHP's openssl binding, which drives the digest-based API.",
            );
        }

        return $signature;
    }
}
