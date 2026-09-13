<?php

declare(strict_types=1);

namespace Phpanta\Http;

/**
 * The TopLevelType enum. The half of a media type before the slash, per the IANA registry.
 *
 * The registry fixes these ten, and an eleventh takes a standards action rather than a commit — so
 * this is one of the genuinely closed vocabularies, which is the whole reason it is an enum and not
 * the left half of a string. Same arrangement as {@link Security\ReferrerPolicy}: only one is used,
 * and the rest are here so switching is a one-token change and a typo is a parse error.
 *
 * The subtype is deliberately not enumerated. That half of the registry holds thousands of entries
 * and gains more continuously, so {@link MimeType} validates its shape instead.
 */
enum TopLevelType: string
{
    /** Everything with no better home — binary formats, JSON, PDF. */
    case Application = 'application';

    case Audio = 'audio';

    /** Reserved by RFC 4735 for documentation. Registering a real type under it is not possible. */
    case Example = 'example';

    case Font = 'font';

    case Image = 'image';

    /** A message, or a piece of one — `message/http`, `message/rfc822`. */
    case Message = 'message';

    /** 3D model data. */
    case Model = 'model';

    /** Several bodies in one, each with a media type of its own — a form upload, a MIME email. */
    case Multipart = 'multipart';

    /** Anything a person can read as characters. Everything a view renders. */
    case Text = 'text';

    case Video = 'video';
}
