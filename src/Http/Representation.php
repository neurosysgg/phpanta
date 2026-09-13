<?php

declare(strict_types=1);

namespace Phpanta\Http;

/**
 * The Representation enum. The forms an answer that has more than one can take.
 *
 * Backed by the media type each one is sent as, because that is the name a client asks for it by —
 * `Accept: application/json` names {@link self::Json} and nothing else, so the value a caller writes
 * and the value an {@link AcceptedTypes} compares are the same string.
 *
 * **Only two, and the order of the cases is not a preference.** Which one a request gets when it
 * does not say is written at the call site, as the first argument to
 * {@link AcceptedTypes::preferred()}, the way a page's default language is written as the first
 * argument to {@link AcceptedLanguages::preferred()}. A third representation is a case here and a
 * branch wherever an answer is written out; a type nobody wrote a case for is a `406`, which is the
 * honest answer to a question this cannot answer yet.
 */
enum Representation: string
{
    /** A page, in the app's own shell — what a browser gets. */
    case Html = 'text/html';

    /** The same answer as data — what a script gets, when it asks for it. */
    case Json = 'application/json';

    /**
     * The type this representation is sent as, with the charset a text type carries.
     *
     * @return MimeType
     */
    public function mimeType(): MimeType
    {
        return match ($this) {
            self::Html => MimeType::html(),
            self::Json => MimeType::json(),
        };
    }
}
