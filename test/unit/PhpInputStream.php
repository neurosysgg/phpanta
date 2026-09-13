<?php

declare(strict_types=1);

namespace Phpanta\Test\Unit;

/**
 * A stand-in for `php://input`, so a test can give {@link \Phpanta\Http\Request::body()} a body.
 *
 * **The alternative was a seam in production code, and it was worth avoiding.** `Request` is a
 * `readonly` class with a private constructor, so it cannot be subclassed or built with a body; the
 * only other way in is a constructor parameter or a setter, either of which would put a hole in the
 * one class the whole request pipeline is parsed by, for the benefit of one test file. A stream
 * wrapper puts the seam in the *environment* instead, which is where the difference between CLI and
 * a real request actually lives — under CLI `php://input` reads STDIN, and there is nothing on it.
 *
 * {@link self::around()} is the only way to use it, and it restores the real wrapper in a `finally`.
 * That scoping matters: while this is registered, every `php://` URL goes through it, so nothing but
 * the code under test may run inside the callback.
 */
final class PhpInputStream
{
    /** What the next `php://input` read will return. */
    public static string $body = '';

    /** Required by the streams API; unused, but PHP writes to it. */
    public mixed $context = null;

    /** How far through {@link self::$body} this handle has read. */
    private int $position = 0;

    /**
     * Runs $callback with `php://input` answering $body.
     *
     * @template T
     * @param string $body
     * @param callable(): T $callback
     * @return T
     */
    public static function around(string $body, callable $callback): mixed
    {
        self::$body = $body;

        stream_wrapper_unregister('php');
        stream_wrapper_register('php', self::class);

        try {
            return $callback();
        } finally {
            stream_wrapper_restore('php');
        }
    }

    /**
     * @param string $path
     * @param string $mode
     * @param int $options
     * @param string|null $openedPath
     * @return bool
     */
    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        $this->position = 0;

        return true;
    }

    /**
     * @param int $count
     * @return string
     */
    public function stream_read(int $count): string
    {
        $chunk = substr(self::$body, $this->position, $count);
        $this->position += strlen($chunk);

        return $chunk;
    }

    /**
     * @return bool
     */
    public function stream_eof(): bool
    {
        return $this->position >= strlen(self::$body);
    }

    /**
     * @return array<string, int>
     */
    public function stream_stat(): array
    {
        return ['size' => strlen(self::$body)];
    }

    /**
     * @return int
     */
    public function stream_tell(): int
    {
        return $this->position;
    }

    /**
     * @param int $offset
     * @param int $whence
     * @return bool
     */
    public function stream_seek(int $offset, int $whence = SEEK_SET): bool
    {
        $this->position = match ($whence) {
            SEEK_CUR => $this->position + $offset,
            SEEK_END => strlen(self::$body) + $offset,
            default  => $offset,
        };

        return true;
    }
}
