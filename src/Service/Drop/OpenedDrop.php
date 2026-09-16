<?php

declare(strict_types=1);

namespace Phpanta\Service\Drop;

use Phpanta\Model\Drop\DropHeader;
use Phpanta\Model\Drop\DropKeys;
use Phpanta\Model\Drop\DropMeta;

/**
 * The OpenedDrop class. A drop its link — and its password — opened: what it is, and its bytes, a
 * chunk at a time.
 *
 * **It holds the file open, not the file's name.** A drop to be read once is claimed and unlinked
 * before this exists, so its bytes are reachable through this handle alone, and a second request finds
 * nothing; a drop revoked or swept while it streams streams to the end all the same. The handle is
 * closed when the last chunk has been read, or when the chunks are abandoned.
 */
final readonly class OpenedDrop
{
    /**
     * Constructs an instance of {@link self}.
     *
     * @param DropHeader $header
     * @param DropMeta   $meta
     * @param DropKeys   $keys
     * @param DropCipher $cipher
     * @param mixed      $handle The file, open for reading at its first chunk. `mixed` rather than a
     *                           resource, for {@link \Phpanta\Support\FileLock}'s reason.
     */
    public function __construct(
        public DropHeader  $header,
        public DropMeta    $meta,
        private DropKeys   $keys,
        private DropCipher $cipher,
        private mixed      $handle,
    ) {}

    /**
     * Its bytes, a chunk at a time, each checked before it is handed over.
     *
     * **A chunk that does not open ends them** rather than throwing — they are being sent by then, and
     * there is no status left to change. The answer states its length, so what receives a drop cut
     * short knows it was; no chunk that did not open is ever handed over.
     *
     * @return iterable<string>
     */
    public function chunks(): iterable
    {
        try {
            $count = $this->meta->chunks();

            for ($index = 0; $index < $count; $index++) {
                $last   = $index === $count - 1;
                $sealed = is_resource($this->handle)
                    ? (string) stream_get_contents($this->handle, $this->meta->chunkLength($index) + DropHeader::TAG)
                    : '';
                $chunk  = $this->cipher->open(
                    $sealed,
                    $this->keys->data,
                    $index,
                    $this->header->chunkData($index, $last),
                );

                if ($chunk === null) {
                    return;
                }

                yield $chunk;
            }
        } finally {
            if (is_resource($this->handle)) {
                fclose($this->handle);
            }
        }
    }

    /**
     * Its bytes whole — for text, which is shown rather than sent — or null where they do not all open.
     *
     * @return string|null
     */
    public function text(): ?string
    {
        $text = '';

        foreach ($this->chunks() as $chunk) {
            $text .= $chunk;
        }

        return strlen($text) === $this->meta->size ? $text : null;
    }
}
