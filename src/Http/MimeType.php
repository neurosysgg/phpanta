<?php

declare(strict_types=1);

namespace Phpanta\Http;

use Phpanta\Exception\MimeTypeException;
use Phpanta\Support\Charset;

/**
 * The MimeType class. What a response body is: a type, a subtype, and the encoding it is in.
 *
 * A class rather than an enum because the value carries a parameter — the same reasoning
 * {@link Security\StrictTransportSecurity} records for carrying a number. An enum could hold
 * `text/html` only as one opaque string with `; charset=utf-8` stapled onto every case, and what is
 * being modelled is the type *and its charset*, which a case cannot hold. So the parts are typed and
 * separate here, the way {@link Header} is a {@link HeaderName} beside a value rather than one
 * string carrying both.
 *
 * The charset is still the half that earns it. A browser told a document's bytes are text but not
 * which encoding has to decide for itself; `X-Content-Type-Options: nosniff` stops it guessing the
 * *type*, and nothing stops it guessing the encoding.
 *
 * Every response sends its `Content-Type` rather than inheriting PHP's `default_mimetype` and
 * `default_charset` ini settings. Those happen to be right, but that is a fact about the runtime,
 * not about this code — and it matters most for the AJAX fragment, which carries no charset
 * declaration of its own, so the header is all a browser has to go on.
 */
final readonly class MimeType implements HeaderValue
{
    /**
     * A subtype: an alphanumeric first character, then alphanumerics and the three separators real
     * subtypes use — `svg+xml`, `vnd.api+json`, `x-www-form-urlencoded` — up to the 127 characters
     * the registry allows.
     *
     * Narrower than RFC 9110's token grammar on purpose, the way {@link Security\CspHost} is
     * narrower than a URL. A token may legally hold half a dozen punctuation characters that no
     * registered subtype has ever used; a value carrying one is a paste that went wrong, and there
     * is more to be had from saying so than from accepting it.
     */
    private const string SUBTYPE_PATTERN = '#^[a-z0-9][a-z0-9+._-]{0,126}\z#i';

    /**
     * Constructs an instance of {@link self}.
     *
     * @param TopLevelType $type    The half before the slash. `text` for everything sent here.
     * @param string       $subtype The half after it, alone: `html`, not `text/html` and not
     *                              `html; q=1`.
     * @param Charset|null $charset The encoding, rendered as the `charset` parameter. Defaults to
     *                              the site's one {@link Charset}, because every body sent here is
     *                              text and a body of text with no stated encoding is the mistake
     *                              this class exists to prevent. Null for a type that has no
     *                              encoding to declare, which is most of them — `image/png` is
     *                              bytes, not characters.
     *
     * @throws MimeTypeException if $subtype is not a subtype.
     */
    public function __construct(
        public TopLevelType $type,
        public string       $subtype,
        public ?Charset     $charset = Charset::Utf8,
    ) {
        $this->verify();
    }

    /**
     * A page, or the fragment of one {@link ViewResponse} sends the SPA router.
     *
     * @return self
     */
    public static function html(): self
    {
        return new self(TopLevelType::Text, 'html');
    }

    /**
     * A 405 refusal, or a download that has no file behind it yet.
     *
     * @return self
     */
    public static function plainText(): self
    {
        return new self(TopLevelType::Text, 'plain');
    }

    /**
     * A demo's audio, named by its file extension.
     *
     * The charset is explicitly null and that is the interesting half: every other body this site
     * sends is text, and this one is samples. A `charset` parameter on `audio/mpeg` is not merely
     * redundant, it is a claim about bytes that have no characters in them.
     *
     * The set is a `match` rather than a lookup that falls back, because a fallback here is the
     * failure this class exists to prevent: `application/octet-stream` on an MP3 is a file the
     * browser downloads instead of playing, with `nosniff` alongside it forbidding the browser from
     * working out that we were wrong. Staged audio is MP3; the rest are the formats
     * `tools/stage-demo.php` copies through rather than re-encoding.
     *
     * @param string $extension The file's extension, without the dot. Case is not significant.
     * @return self
     *
     * @throws MimeTypeException if nothing here knows what that extension holds.
     */
    public static function forAudio(string $extension): self
    {
        $subtype = match (strtolower($extension)) {
            'mp3'          => 'mpeg',
            'm4a', 'mp4'   => 'mp4',
            'flac'         => 'flac',
            'wav'          => 'wav',
            'ogg'          => 'ogg',
            'opus'         => 'opus',
            default        => throw new MimeTypeException(sprintf(
                "MimeType::forAudio() has no type for '%s'. Add the case rather than falling back: "
                . 'an unrecognised audio file typed as octet-stream downloads instead of playing, '
                . 'and nosniff stops the browser correcting us.',
                $extension,
            )),
        };

        return new self(TopLevelType::Audio, $subtype, null);
    }

    /**
     * Returns the `Content-Type` value: the type, and the encoding if there is one to declare.
     *
     * @return string
     */
    public function render(): string
    {
        $essence = $this->type->value . '/' . $this->subtype;

        return $this->charset === null ? $essence : $essence . '; charset=' . $this->charset->value;
    }

    /**
     *
     * @return void
     * @throws MimeTypeException
     */
    private function verify(): void
    {
        if (preg_match(self::SUBTYPE_PATTERN, $this->subtype) !== 1) {
            throw new MimeTypeException(sprintf(
                "MimeType::\$subtype must be a bare subtype like 'html', got '%s'. "
                . 'It is the half after the slash and carries nothing else: no slash of its own, '
                . 'and no parameter — the charset is a separate argument.',
                $this->subtype,
            ));
        }
    }
}
