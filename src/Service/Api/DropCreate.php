<?php

declare(strict_types=1);

namespace Phpanta\Service\Api;

use Phpanta\Exception\FilesystemException;
use Phpanta\Http\Api\ApiHandler;
use Phpanta\Http\Api\ApiResult;
use Phpanta\Http\HttpStatusCode;
use Phpanta\Http\Upload;
use Phpanta\Model\Drop\DropConfig;
use Phpanta\Model\Drop\DropManifest;
use Phpanta\Model\Drop\DropMeta;
use Phpanta\Model\Drop\DropTerms;
use Phpanta\Model\Health\HealthSection;
use Phpanta\Model\Machine\LinkSection;
use Phpanta\Model\Machine\Measure;
use Phpanta\Service\Drop\DropStore;
use Phpanta\Support\Charset;
use Phpanta\Support\Collection;
use Phpanta\Support\DropPath;
use Phpanta\Text\AdminText;

/**
 * The DropCreate class. `drop v1 create`: a text or a file, sealed and kept, and the link that opens
 * it — shown once, here, and kept nowhere.
 *
 * **What is kept is, in order: the file a browser sent, the body a signed call carries, or the text
 * field.** A drop with a name is a file, saved under it — the file's own, unless `filename` says
 * another — and one without is text, which must be UTF-8 and is shown where it is revealed. It is at
 * most what the deployment's `data/drop.json` lets it be, for at most as long.
 *
 * A write, unless it is a dry run. The link is the answer's last section: a page shows it as a link
 * to copy, and the signing command prints it whole.
 */
final readonly class DropCreate implements ApiHandler
{
    /**
     * Constructs an instance of {@link self}.
     *
     * @param DropStore          $store
     * @param DropConfig         $config
     * @param DropManifest       $manifest
     * @param string             $body     What a signed call carries; `''` for a browser's.
     * @param Collection<Upload> $uploads  What a browser sent; none for a signed call.
     * @param int|null           $now      A test seam.
     */
    public function __construct(
        private DropStore    $store,
        private DropConfig   $config,
        private DropManifest $manifest,
        private string       $body,
        private Collection   $uploads,
        private ?int         $now = null,
    ) {}

    /**
     * @return bool
     */
    public function isWrite(): bool
    {
        return $this->manifest->apply;
    }

    /**
     * @return ApiResult
     */
    public function handle(): ApiResult
    {
        if ($this->uploads->count() > 1) {
            return ApiResult::refusal(HttpStatusCode::UnprocessableContent, 'a drop is one file: send one at a time');
        }

        $upload  = $this->uploads->first();
        $payload = match (true) {
            $upload !== null   => $upload->contents($this->config->maxBytes + 1),
            $this->body !== '' => $this->body,
            default            => $this->manifest->text,
        };
        $name    = $this->manifest->filename !== '' ? $this->manifest->filename : $upload?->clientName();

        $refusal = match (true) {
            $payload === null => 'the file sent could not be read',
            $payload === ''   => 'there is nothing to drop: send some text, or a file',
            strlen($payload) > $this->config->maxBytes => sprintf(
                'a drop is %s at most here, and this is %s',
                Measure::bytes($this->config->maxBytes),
                Measure::bytes(strlen($payload)),
            ),
            $name !== null && !DropMeta::isName($name) => sprintf(
                'a file is saved under one segment of UTF-8, and %s is not one: give it a filename',
                json_encode($name, JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            ),
            $name === null && !mb_check_encoding($payload, Charset::Utf8->value) =>
                'text is UTF-8, and these bytes are not: give them a filename and they are kept as a file',
            $this->lifetime() > $this->config->maxLifetime => sprintf(
                'a drop is kept for %s at most here',
                Measure::duration($this->config->maxLifetime),
            ),
            default => null,
        };

        if ($refusal !== null || $payload === null) {
            return ApiResult::refusal(
                $payload !== null && strlen($payload) > $this->config->maxBytes
                    ? HttpStatusCode::ContentTooLarge
                    : HttpStatusCode::UnprocessableContent,
                (string) $refusal,
            );
        }

        $now   = $this->now ?? time();
        $lines = [
            sprintf('%s — %s', $name ?? 'text', Measure::bytes(strlen($payload))),
            DropTerms::gone($now + $this->lifetime()),
            DropTerms::opens($this->manifest->once),
            DropTerms::needs($this->manifest->password !== ''),
        ];

        if (!$this->manifest->apply) {
            return ApiResult::of(
                HttpStatusCode::Ok,
                HealthSection::lines(null, 'a dry run: no drop was made', ...$lines),
            );
        }

        try {
            $token = $this->store->create(
                $payload,
                $name,
                $this->manifest->once,
                $this->manifest->password,
                $this->lifetime(),
                $now,
            );
        } catch (FilesystemException $e) {
            return ApiResult::refusal(
                HttpStatusCode::InternalServerError,
                'the drop was not kept: ' . $e->getMessage(),
            );
        }

        return ApiResult::of(
            HttpStatusCode::Created,
            HealthSection::lines(null, 'kept — the link below opens it, and is kept nowhere else', ...$lines),
            new LinkSection(AdminText::DropLink, DropPath::Index->link($token)),
        );
    }

    /**
     * How long the drop is kept: what the manifest says, or the deployment's default.
     *
     * @return int
     */
    private function lifetime(): int
    {
        return $this->manifest->lifetime ?? $this->config->defaultLifetime();
    }
}
