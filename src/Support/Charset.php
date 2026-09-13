<?php

declare(strict_types=1);

namespace Phpanta\Support;

/**
 * The Charset enum. The encoding this site is written in.
 *
 * One case, like {@link \Phpanta\Http\RequestedWith} — it exists to make the encoding a type, not
 * to offer a choice. Before it, `utf-8` was written in three places in two shapes: the
 * `Content-Type` parameter, the third argument to the site's only escaping call, and the
 * charset meta tag in the document head. Nothing connected them, and the failure mode is a
 * quiet one — a document whose header names one encoding and whose head names another is decoded
 * by whichever the browser decides to believe.
 *
 * It lives here rather than in Http because both {@link \Phpanta\Http\MimeType} and
 * {@link \Phpanta\View\Html\Text} read it, and the markup tree has no other reason to know
 * anything about HTTP.
 *
 * **Two forms, because the two kinds of reader write a charset name differently.** Both accept
 * either — the header parameter is case-insensitive by spec and PHP's encoding names are too — so
 * the split is convention rather than correctness, and keeping both is what leaves every byte the
 * site already emits exactly as it was.
 */
enum Charset: string
{
    /** The header parameter form, lowercase as a `Content-Type` conventionally carries it. */
    case Utf8 = 'utf-8';

    /**
     * IANA's preferred name: what a document declares, and what the escaper in
     * {@link \Phpanta\View\Html\Text} is told to read its input as.
     *
     * A `match` rather than a `strtoupper()`, so each case states its own name. The uppercasing is
     * a coincidence of this one — IANA's preferred name for `windows-1252` is the lowercase form —
     * and a second case added later should have to write its answer down rather than inherit a
     * rule that was never true.
     *
     * @return string
     */
    public function canonical(): string
    {
        return match ($this) {
            self::Utf8 => 'UTF-8',
        };
    }
}
