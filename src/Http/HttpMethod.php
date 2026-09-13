<?php

declare(strict_types=1);

namespace Phpanta\Http;

/**
 * The HttpMethod enum. The HTTP methods this site recognises.
 *
 * Recognising one is not the same as accepting it: the site is read-only, so everything but
 * {@link self::isReadOnly()} is refused with a 405, and {@link Allow::readOnly()} derives that
 * refusal's `Allow` header from the same predicate so the two cannot disagree. A method that is not
 * a case here — a typo, a WebDAV verb, anything — is not read-only either, which is why
 * {@link Request::method()} is nullable rather than defaulting to GET.
 */
enum HttpMethod: string
{
    case Get     = 'GET';
    case Head    = 'HEAD';
    case Post    = 'POST';
    case Put     = 'PUT';
    case Patch   = 'PATCH';
    case Delete  = 'DELETE';
    case Options = 'OPTIONS';
    case Trace   = 'TRACE';

    /**
     * True if the method only reads.
     *
     * @return bool
     */
    public function isReadOnly(): bool
    {
        return match ($this) {
            self::Get, self::Head => true,
            default               => false,
        };
    }
}
