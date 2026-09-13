<?php

declare(strict_types=1);

namespace Phpanta\Http;

use NoDiscard;
use Phpanta\Model\Health\ByteFloor;
use Phpanta\Model\Health\PhpSetting;
use Phpanta\Model\Health\Requirement;
use Phpanta\Model\Health\SettingRequirement;
use Phpanta\Model\Health\Toggle;
use Phpanta\Support\Collection;
use Phpanta\Support\Diagnostics;
use Phpanta\Support\File;

/**
 * The Upload class. One file a form sent: what the browser called it, and where PHP put it.
 *
 * ```php
 * $upload = $request->upload(AvatarField::Image);                  // ?Upload, or a 400 or a 413
 * $kept   = $upload?->keepAs($app->data()->directory('avatars')->file($id . '.png'));
 * ```
 *
 * **Everything about it but its bytes is the browser's word.** {@link self::clientName()} is what
 * the sender says the file was called. PHP keeps only the last segment of a path sent there, and
 * the rest is any name at all — a dotfile, `index.php`, one a site already keeps — so it is shown,
 * never used as a name on disk. The type a browser claims is not read at all: a file is whatever
 * its bytes are, and a site that cares checks them. See docs/security.md.
 *
 * **PHP deletes the file when the request ends**, so an upload a page wants is kept with
 * {@link self::keepAs()} before it answers, and kept once: keeping moves it.
 */
final readonly class Upload
{
    /**
     * Constructs an instance of {@link self}.
     *
     * @param string $clientName What the browser called it — see {@link self::clientName()}.
     * @param File   $file       Where PHP put it: a temporary file of PHP's own naming.
     * @param int    $size       How many bytes arrived.
     */
    public function __construct(private string $clientName, private File $file, private int $size) {}

    /**
     * What the host needs so that a file up to $maxBytes arrives at all — for a site that takes
     * uploads to list in its own requirements, since the framework's floor cannot know it does.
     *
     * Three settings, because each refuses a file in its own way: `file_uploads` off drops every
     * file, `upload_max_filesize` below the size refuses that file, and `post_max_size` below it
     * empties the whole form — which {@link Request::form()} answers with a 413, rather than a
     * form that seems to have sent nothing.
     *
     * @param int $maxBytes The largest file the site takes.
     * @return Collection<Requirement>
     */
    #[NoDiscard('requirements() builds declarations and registers nothing; only ownRequirements() reaches health v1')]
    public static function requirements(int $maxBytes): Collection
    {
        return new Collection(Requirement::class)->with(
            new SettingRequirement(PhpSetting::FileUploads->value, Toggle::On),
            new SettingRequirement(PhpSetting::UploadMaxFilesize->value, new ByteFloor($maxBytes)),
            // 0 is post_max_size's own spelling of no limit — see the framework's floor.
            new SettingRequirement(PhpSetting::PostMaxSize->value, new ByteFloor($maxBytes, 0)),
        );
    }

    /**
     * What the browser said the file was called, as it said it — shown to a visitor, never used as a
     * path or a name on disk, which is what it would be written to reach.
     *
     * @return string
     */
    public function clientName(): string
    {
        return $this->clientName;
    }

    /**
     * How many bytes arrived.
     *
     * @return int
     */
    public function size(): int
    {
        return $this->size;
    }

    /**
     * The file's bytes, at most $limit of them — or null where it cannot be read, having been kept
     * elsewhere already.
     *
     * @param int|null $limit
     * @return string|null
     */
    #[NoDiscard('contents() only reads; a call whose result goes nowhere read nothing')]
    public function contents(?int $limit = null): ?string
    {
        return $this->file->read($limit);
    }

    /**
     * Keeps the file as $target, replacing whatever is there.
     *
     * **Moved in two steps, and the order is the argument.** PHP's temporary file sits wherever the
     * host keeps them — often another filesystem, where a `rename()` is a copy — so it is moved first
     * to a temporary name *beside* $target, which may be slow and may be seen half-written by
     * nothing, and then renamed onto $target, which is atomic within that directory. A reader of
     * $target sees the old file or the new one, as {@link File::write()} promises.
     *
     * **Never `move_uploaded_file()`**, whose one addition is to ask `is_uploaded_file()` first.
     * That question guards a temporary name a request could write, which none can: the name is PHP's,
     * read from `$_FILES` by {@link MultipartParameters}. And the question is always no under the CLI,
     * which would leave the one path every upload takes in production as the one no test can reach.
     *
     * **It creates no directory** — {@link File}'s rule, and the caller's decision.
     *
     * @param File     $target
     * @param int|null $mode   The kept file's permissions, or null to keep the ones PHP gave it,
     *                         which are its owner's alone.
     * @return bool False if it could not be kept — $target's directory missing or not writable,
     *              $target a directory, or the file kept already — and the file is then where it
     *              was, for the page to keep elsewhere or to let PHP delete.
     */
    #[NoDiscard('keepAs() reports whether the file was kept; a call whose result goes nowhere may have lost it')]
    public function keepAs(File $target, ?int $mode = null): bool
    {
        $temporary = $target->temporarySibling()->path;

        $kept = Diagnostics::muted(fn(): bool => rename($this->file->path, $temporary)
            && ($mode === null || chmod($temporary, $mode))
            && rename($temporary, $target->path));

        if (!$kept) {
            // Back where PHP put it, if it got as far as beside $target; nothing to do if it did not.
            Diagnostics::muted(fn(): bool => rename($temporary, $this->file->path));
        }

        return $kept;
    }
}
