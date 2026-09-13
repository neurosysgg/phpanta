<?php

declare(strict_types=1);

namespace Phpanta\Http;

/**
 * The FormEncoding enum. How a form packs what it sends — the two bodies {@link Request::form()}
 * reads, and what a `<form enctype>` says.
 *
 * **Each case is a {@link MimeType}, spelled once.** The backing value is the type's essence —
 * what a request's `Content-Type` is compared with and what the attribute renders — and
 * {@link self::mimeType()} derives the type from it rather than writing it a second time, with
 * {@link MimeType::fromEssence()} checking that it is one.
 *
 * A form that holds a file input and does not say {@link self::Multipart} sends the file's *name*
 * as a text field and never the file: no error, and the upload a page waits for is simply absent.
 * {@link \Phpanta\Form\Form::render()} writes it wherever a field is a file, so no page has to.
 */
enum FormEncoding: string
{
    /** The default: `name=value` pairs, which is how every form without a file sends. */
    case UrlEncoded = 'application/x-www-form-urlencoded';

    /** One part per field, which is the only way a file is sent. */
    case Multipart = 'multipart/form-data';

    /**
     * The type a body of this encoding is sent as. No charset: neither type defines one.
     *
     * @return MimeType
     */
    public function mimeType(): MimeType
    {
        return MimeType::fromEssence($this->value);
    }
}
