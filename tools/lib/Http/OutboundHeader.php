<?php

declare(strict_types=1);

namespace Phpanta\Tool\Http;

use Phpanta\Http\HeaderName;

/**
 * The OutboundHeader enum. The request headers this tooling sends.
 *
 * The site has two header-name enums already and this is a third direction rather than a fourth
 * enum's worth of new idea: {@link \Phpanta\Http\SecurityHeader} and
 * {@link \Phpanta\Http\ResponseHeader} are headers the site *sends* on a response,
 * {@link \Phpanta\Http\RequestHeader} is the ones it *reads* off a request, and these are the ones
 * a command *sends* on a request of its own. All four are {@link HeaderName}s, so nothing has to
 * learn a second way to spell a header name.
 *
 * It is not a case on the site's `RequestHeader` for two reasons, either of which is enough: that
 * enum is mirrored in `assets/ts/model/` and compared case for case by `enum-parity.test.mjs`, so a
 * case with no client-side reader fails a test that is right to fail; and a header the site reads
 * and a header a tool sends are different facts that happen to share a spelling.
 *
 * Exhaustive of what actually goes out. A case nobody writes is a name with nothing on the other
 * end of it, which is the thing every enum here exists to prevent.
 */
enum OutboundHeader: string implements HeaderName
{
    /**
     * What the API is asked to answer with.
     *
     * SoundCloud's documented value carries the charset — `application/json; charset=utf-8` — so
     * this is not a {@link \Phpanta\Http\MimeType} rendered on the fly: it is the string the
     * provider's own examples send, and it is the provider's to change.
     */
    case Accept = 'Accept';

    /**
     * The bearer credential.
     *
     * SoundCloud's scheme is `OAuth`, not `Bearer` — see
     * `Client`, which is the only thing that fills this in.
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
