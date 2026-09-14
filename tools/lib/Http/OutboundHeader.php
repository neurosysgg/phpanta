<?php

declare(strict_types=1);

namespace Phpanta\Tool\Http;

use Phpanta\Http\HeaderName;

/**
 * The OutboundHeader enum. The request headers this tooling sends.
 *
 * The framework has three header-name enums already and this is a fourth direction rather than a fourth
 * enum's worth of new idea: {@link \Phpanta\Http\SecurityHeader} and
 * {@link \Phpanta\Http\ResponseHeader} are headers the site *sends* on a response,
 * {@link \Phpanta\Http\RequestHeader} is the ones it *reads* off a request, and these are the ones
 * a command *sends* on a request of its own. The three that are sent are {@link HeaderName}s, so
 * nothing has to learn a second way to spell a header name; the one that is only read is not.
 *
 * It is not a case on `RequestHeader` because a header the site reads and a header a tool sends
 * are different facts that happen to share a spelling — and a site that mirrors `RequestHeader` into
 * its client-side model would otherwise have to mirror a case no client code ever reads.
 *
 * Exhaustive of what actually goes out. A case nobody writes is a name with nothing on the other
 * end of it, which is the thing every enum here exists to prevent.
 */
enum OutboundHeader: string implements HeaderName
{
    /**
     * What the API is asked to answer with.
     *
     * A provider's documented value can carry the charset — `application/json; charset=utf-8` — so
     * this is not a {@link \Phpanta\Http\MimeType} rendered on the fly: it is the string the
     * provider's own examples send, and it is the provider's to change.
     */
    case Accept = 'Accept';

    /**
     * The bearer credential.
     *
     * Its scheme is the provider's — some say `OAuth` rather than `Bearer` — so the API client a site
     * builds on {@link Transport} fills it in, and nothing here assumes one.
     */
    case Authorization = 'Authorization';

    /**
     * Written only for the form-encoded token exchange.
     *
     * A multipart body carries a boundary, and the boundary is chosen by whatever assembles the
     * body — curl, here — so a `Content-Type` written by hand beside one would be a second answer
     * to a question already answered, and the wrong answer whenever the two disagreed.
     */
    case ContentType = 'Content-Type';

    /**
     * @return string
     */
    public function headerName(): string
    {
        return $this->value;
    }
}
