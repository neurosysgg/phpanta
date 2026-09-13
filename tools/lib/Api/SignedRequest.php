<?php

declare(strict_types=1);

namespace Phpanta\Tool\Api;

use BackedEnum;
use JsonException;
use Phpanta\Http\Api\ApiAction;
use Phpanta\Http\Api\ApiService;
use Phpanta\Http\Api\ApiVersion;
use Phpanta\Http\Header;
use Phpanta\Http\HttpMethod;
use Phpanta\Http\MimeType;
use Phpanta\Support\ApiPath;
use Phpanta\Tool\Cli\UsageException;
use Phpanta\Tool\Http\OutboundHeader;
use Phpanta\Tool\Http\Request;
use Phpanta\Tool\Http\Url;

/**
 * The SignedRequest class. One call to `/api`, signed and ready to send.
 *
 * It is the tooling half of {@link \Phpanta\Service\ApiGate}: it writes the manifest that gate
 * reads, and the two agree because **both sides use the same vocabulary rather than a copy of it**.
 * The path comes from {@link ApiPath::Api}, the scheme from {@link \Phpanta\Http\AuthScheme}, the
 * method from the action's own {@link ApiAction::method()} — so there is no spelling of an address,
 * a token or a verb that exists only on this side and could drift from the other.
 *
 * **What it signs is exactly what it sends.** The URL is built from the path this puts in the
 * manifest, in that order, so the two cannot disagree by construction. That matters because
 * {@link ApiPath::to()} `rawurlencode`s each value and is therefore *not* the inverse of
 * {@link \Phpanta\Support\Route::matches()} — for every service, version and action spelled in
 * `[a-z0-9]` it is exactly the identity, and for one that ever is not, signing the encoded form and
 * sending the encoded form still agree.
 *
 * The body is bound by digest rather than carried inside the credential, which is what lets a read
 * be signed on the same terms as a push: `sha256('')` over no bytes at all is a claim like any
 * other. See {@link \Phpanta\Model\Api\ApiEnvelope}.
 */
final readonly class SignedRequest
{
    /**
     * Builds the outbound request.
     *
     * @param Url $base Where the deployment lives — an origin, not an endpoint. The path is this
     *                  class's to compute, which is the whole point of the action being typed.
     * @param ApiService $service
     * @param ApiVersion $version
     * @param ApiAction&BackedEnum $action The action. Every implementation is a backed enum, and the
     *                                     intersection is what lets this read both halves — the
     *                                     method from the interface, the segment from the case.
     * @param string $body The request body, or `''` for a read.
     * @param array<string, bool|int|string> $fields The action's own manifest fields, on top of the
     *                                               envelope's. A door: this is what `json_encode`
     *                                               takes, and nothing past it is an array.
     * @param PrivateKey $key
     * @return Request
     *
     * @throws UsageException if the key cannot sign, or the manifest cannot be encoded.
     */
    public static function build(
        Url $base,
        ApiService $service,
        ApiVersion $version,
        ApiAction&BackedEnum $action,
        string $body,
        array $fields,
        PrivateKey $key,
    ): Request {
        $path = ApiPath::Api->to($service->value, $version->value, (string) $action->value);

        // The manifest's bytes are what gets signed and what the server checks the signature
        // against, so they are built once and passed along as a string. Re-encoding the parsed form
        // on either side would be a second spelling of one fact, and JSON has more than one way to
        // write the same object.
        try {
            $manifest = json_encode([
                'serial' => time(),
                'method' => $action->method()->value,
                'path'   => $path,
                'digest' => hash('sha256', $body),
                'size'   => strlen($body),
                ...$fields,
            ], JSON_THROW_ON_ERROR);
        } catch (JsonException $cause) {
            throw new UsageException('could not encode the manifest: ' . $cause->getMessage());
        }

        // rtrim, because a base given with a trailing slash would otherwise produce `//api/…`,
        // which Uri::parse reads as an authority — the first segment would become a host and
        // the request would go somewhere else entirely. Measured; see ApiEnvelope.
        $url        = new Url(rtrim($base->render(), '/') . $path);
        $credential = new Header(
            OutboundHeader::Authorization,
            new SignedCredential($manifest, $key->sign($manifest)),
        );

        // The answer as data, which ResultReader turns back into the text the command prints — the
        // server's default is a page, which is for a browser.
        $accept = new Header(OutboundHeader::Accept, MimeType::json());

        // get() for a read and raw() for a write, rather than one factory taking a method: the
        // outbound Request has always chosen its shape by which constructor is called, and a read
        // must send no Content-Type for a body it does not have.
        return $action->method() === HttpMethod::Get
            ? Request::get($url, $credential, $accept)
            : Request::raw($url, $body, $credential, $accept);
    }
}
