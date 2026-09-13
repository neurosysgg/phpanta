<?php

declare(strict_types=1);

namespace Phpanta\Test\Unit;

use Phpanta\Http\HttpStatusCode;
use ReflectionProperty;

/**
 * What {@link UpdateTest} and {@link ApiTest} both have to be able to do, in one place.
 *
 * The two files were one before `/update` became `/api/update/v1/patch`, and splitting them left
 * four helpers wanted on both sides. Copying them would have been the smaller diff and the exact
 * shape of drift this codebase spends most of its types avoiding — a tar fixture that stopped
 * meaning the same thing in the file that tests the reader and the file that tests the gate.
 *
 * Two jobs, and they are both "see what a test otherwise cannot":
 *
 * - **Build archives `TarWriter` will not.** That writer — deliberately — cannot produce a symlink,
 *   a device node, or a name with `..` in it, so a fixture built by it could only ever exercise the
 *   refusals that do not matter. These are raw ustar bytes, assembled by hand.
 * - **Read a response's status and body.** Both are private on {@link \Phpanta\Http\PlainTextResponse},
 *   because `send()` ends the request and there is nothing to assert against afterwards. That is
 *   the same split {@link \Phpanta\Service\Auth::accepts()} makes, seen from the test's side.
 */
final class UpdateFixture
{
    /** ustar's block size, which is every offset in the format. */
    public const int BLOCK = 512;

    /**
     * A gzipped ustar archive holding $files, keyed by member name.
     *
     * @param array<string, string> $files
     * @return string
     */
    public static function archive(array $files): string
    {
        $tar = '';

        foreach ($files as $name => $contents) {
            $tar .= self::member($name, $contents);
        }

        return (string) gzencode($tar . str_repeat("\0", self::BLOCK * 2));
    }

    /**
     * One raw ustar member.
     *
     * @param string $name
     * @param string $contents
     * @param string $type
     * @param int|null $sizeOverride A size the member does not actually carry, for truncation.
     * @param string $prefix The second of ustar's two name fields, and the only way a member name
     *                        exceeds the 100-byte `name` field at all — so also the only way to
     *                        reach the reader's own 255-byte bound, since prefix + '/' + name tops
     *                        out at 256.
     * @return string
     */
    public static function member(
        string $name,
        string $contents,
        string $type = '0',
        ?int $sizeOverride = null,
        string $prefix = '',
    ): string {
        $size   = $sizeOverride ?? ($type === '0' ? strlen($contents) : 0);
        $header = pack(
            'a100a8a8a8a12a12a8a1a100a6a2a32a32a8a8a155a12',
            $name,
            "0000644\0",
            "0000000\0",
            "0000000\0",
            sprintf('%011o', $size) . "\0",
            sprintf('%011o', 0) . "\0",
            '        ',
            $type,
            '',
            'ustar',
            '00',
            '',
            '',
            "0000000\0",
            "0000000\0",
            $prefix,
            '',
        );

        $sum = 0;
        for ($i = 0; $i < self::BLOCK; $i++) {
            $sum += ord($header[$i]);
        }
        $header = substr_replace($header, sprintf('%06o', $sum) . "\0 ", 148, 8);

        if ($type !== '0' || $contents === '') {
            return $header;
        }

        $pad = strlen($contents) % self::BLOCK;

        return $header . $contents . ($pad === 0 ? '' : str_repeat("\0", self::BLOCK - $pad));
    }

    /**
     * @param object $response
     * @return HttpStatusCode
     */
    public static function statusOf(object $response): HttpStatusCode
    {
        return new ReflectionProperty($response, 'status')->getValue($response);
    }

    /**
     * @param object $response
     * @return string
     */
    public static function bodyOf(object $response): string
    {
        return new ReflectionProperty($response, 'body')->getValue($response);
    }

    /**
     * Takes $path apart: the files it holds, then itself.
     *
     * `Directory::remove()` would do it, and deliberately does not recurse — which is right for the
     * production class and wrong for a sandbox holding a whole deployment.
     *
     * @param string $path
     * @return void
     */
    public static function removeTree(string $path): void
    {
        foreach ((array) glob($path . '/{,.}*', GLOB_BRACE) as $entry) {
            $entry = (string) $entry;
            $name  = basename($entry);

            if ($name === '.' || $name === '..') {
                continue;
            }

            is_dir($entry) ? self::removeTree($entry) : @unlink($entry);
        }

        @rmdir($path);
    }
}
