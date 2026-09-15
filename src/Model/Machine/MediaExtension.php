<?php

declare(strict_types=1);

namespace Phpanta\Model\Machine;

use Phpanta\Http\MimeType;
use Phpanta\Http\TopLevelType;

/**
 * The MediaExtension enum. The extensions the `machine` service knows the kind of, and the subtype
 * each is sent as.
 *
 * What is not here is saved rather than shown — see {@link MediaKind}. The formats are the ones a
 * browser plays or shows without a plugin, and the text extensions are the ones a machine is full of.
 */
enum MediaExtension: string
{
    case Png  = 'png';
    case Jpg  = 'jpg';
    case Jpeg = 'jpeg';
    case Gif  = 'gif';
    case Webp = 'webp';
    case Avif = 'avif';
    case Bmp  = 'bmp';
    case Ico  = 'ico';

    case Mp3  = 'mp3';
    case M4a  = 'm4a';
    case Aac  = 'aac';
    case Flac = 'flac';
    case Wav  = 'wav';
    case Ogg  = 'ogg';
    case Oga  = 'oga';
    case Opus = 'opus';
    case Weba = 'weba';

    case Mp4  = 'mp4';
    case M4v  = 'm4v';
    case Webm = 'webm';
    case Ogv  = 'ogv';
    case Mov  = 'mov';
    case Mkv  = 'mkv';

    case Pdf  = 'pdf';

    case Txt  = 'txt';
    case Md   = 'md';
    case Log  = 'log';
    case Csv  = 'csv';
    case Json = 'json';
    case Xml  = 'xml';
    case Yml  = 'yml';
    case Yaml = 'yaml';
    case Toml = 'toml';
    case Ini  = 'ini';
    case Conf = 'conf';
    case Cfg  = 'cfg';
    case Env  = 'env';
    case Sh   = 'sh';
    case Zsh  = 'zsh';
    case Bash = 'bash';
    case Py   = 'py';
    case Php  = 'php';
    case Js   = 'js';
    case Mjs  = 'mjs';
    case Ts   = 'ts';
    case Css  = 'css';
    case Html = 'html';
    case Htm  = 'htm';
    case Svg  = 'svg';
    case C    = 'c';
    case H    = 'h';
    case Cpp  = 'cpp';
    case Rs   = 'rs';
    case Go   = 'go';
    case Java = 'java';
    case Sql  = 'sql';
    case Service = 'service';
    case Desktop = 'desktop';

    /**
     * What a file with this extension is.
     *
     * An SVG and an HTML file are text here: shown as their source, never as a picture or a page,
     * since either could carry script.
     *
     * @return MediaKind
     */
    public function kind(): MediaKind
    {
        return match ($this) {
            self::Png, self::Jpg, self::Jpeg, self::Gif,
            self::Webp, self::Avif, self::Bmp, self::Ico => MediaKind::Image,
            self::Mp3, self::M4a, self::Aac, self::Flac, self::Wav, self::Ogg, self::Oga, self::Opus,
            self::Weba => MediaKind::Audio,
            self::Mp4, self::M4v, self::Webm, self::Ogv, self::Mov, self::Mkv => MediaKind::Video,
            self::Pdf => MediaKind::Pdf,
            default   => MediaKind::Text,
        };
    }

    /**
     * The subtype a file with this extension is sent as, under its kind's type.
     *
     * @return string
     */
    public function subtype(): string
    {
        return match ($this) {
            self::Jpg, self::Jpeg        => 'jpeg',
            self::Ico                    => 'vnd.microsoft.icon',
            self::Mp3                    => 'mpeg',
            self::M4a, self::Mp4, self::M4v => 'mp4',
            self::Ogg, self::Oga, self::Opus, self::Ogv => 'ogg',
            self::Weba, self::Webm       => 'webm',
            self::Mov                    => 'quicktime',
            self::Mkv                    => 'x-matroska',
            default                      => $this->value,
        };
    }

    /**
     * What a file of no kind a browser shows goes out as: bytes, to be saved.
     *
     * @return MimeType
     */
    public static function octetStream(): MimeType
    {
        return new MimeType(TopLevelType::Application, 'octet-stream', null);
    }
}
