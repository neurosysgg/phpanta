<?php

declare(strict_types=1);

namespace Phpanta\Model\Machine;

use Phpanta\Http\ContentDisposition;
use Phpanta\Http\MimeType;
use Phpanta\Http\TopLevelType;
use Phpanta\Support\File;

/**
 * The MediaKind enum. What a file is to a browser — something it shows, plays or reads, or something
 * only to be saved — and so how the `machine` service answers with it.
 *
 * **Shown where it lands only by kind, and only these kinds.** A file the service serves is whatever
 * is on the disk, and a browser that renders one renders it in the admin's own origin. So a picture,
 * a recording, a film, text and a PDF are shown inline, each typed as what its extension says — and
 * `nosniff`, sent with every answer, keeps the browser from reading it as anything else. Everything
 * else goes out as `application/octet-stream`, to be saved: an HTML file or an SVG, which could carry
 * script, is never a page here. Text is always `text/plain`, whatever it is the source of.
 *
 * A file with no extension this knows is text if its first bytes are UTF-8 with no NUL among them —
 * `/etc/hostname`, a `README` — and something to save otherwise.
 */
enum MediaKind: string
{
    case Image = 'image';
    case Audio = 'audio';
    case Video = 'video';
    case Text  = 'text';
    case Pdf   = 'pdf';
    case Other = 'other';

    /** How much of a file is read to decide whether it is text. */
    private const int SNIFF = 4096;

    /**
     * What $file is, by its extension — or by its first bytes where the extension says nothing.
     *
     * @param File $file
     * @return self
     */
    public static function of(File $file): self
    {
        $extension = MediaExtension::tryFrom($file->extension());

        if ($extension !== null) {
            return $extension->kind();
        }

        $start = $file->read(self::SNIFF);

        return $start !== null && !str_contains($start, "\0") && mb_check_encoding(self::whole($start), 'UTF-8')
            ? self::Text
            : self::Other;
    }

    /**
     * The type $file goes out as, being of this kind.
     *
     * @param File $file
     * @return MimeType
     */
    public function type(File $file): MimeType
    {
        $subtype = MediaExtension::tryFrom($file->extension())?->subtype();

        return match ($this) {
            self::Image => new MimeType(TopLevelType::Image, (string) $subtype, null),
            self::Audio => new MimeType(TopLevelType::Audio, (string) $subtype, null),
            self::Video => new MimeType(TopLevelType::Video, (string) $subtype, null),
            self::Text  => MimeType::plainText(),
            self::Pdf   => new MimeType(TopLevelType::Application, MediaExtension::Pdf->subtype(), null),
            self::Other => MediaExtension::octetStream(),
        };
    }

    /**
     * Whether a browser is let show a file of this kind where it lands.
     *
     * @return bool
     */
    public function isShown(): bool
    {
        return $this !== self::Other;
    }

    /**
     * How $file goes out when it is asked for to be shown: inline where its kind is, saved otherwise.
     *
     * @param File $file
     * @return ContentDisposition
     */
    public function disposition(File $file): ContentDisposition
    {
        return $this->isShown() ? ContentDisposition::inline() : ContentDisposition::attachment($file->name());
    }

    /**
     * $start without a character the read may have cut in half at its end.
     *
     * @param string $start
     * @return string
     */
    private static function whole(string $start): string
    {
        return strlen($start) < self::SNIFF ? $start : mb_strcut($start, 0, strlen($start) - 3, 'UTF-8');
    }
}
