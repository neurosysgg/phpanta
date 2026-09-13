<?php

declare(strict_types=1);

namespace Phpanta\Tool\Http;

use CURLFile;
use Phpanta\Http\Header;

/**
 * The CurlTransport class. Every network request this repo makes, in one place.
 *
 * One class holds the calls, so the options are stated once and a machine without the extension
 * fails the same way everywhere rather than differently at each call site. A site's verify script
 * can pin it — a `curl_` anywhere else under `tools/lib/` fails the build.
 *
 * **The site itself makes no outbound request at all**, which is worth saying because it is a
 * property rather than an accident: `public/index.php` answers requests and never issues one. This
 * class is tooling, it runs on a laptop, and nothing under `src/` can reach it.
 *
 * Three options are set that a default would otherwise decide, and each is a decision:
 *
 * - **No total timeout, but a stall does end the transfer.** A large upload over a domestic uplink
 *   is minutes of legitimate transfer, so any wall-clock limit generous enough to be safe is too
 *   generous to be useful. `CURLOPT_LOW_SPEED_LIMIT`/`_TIME` say the real thing instead: a transfer
 *   moving less than a byte a second for a minute has stopped, however long it has been running.
 * - **Redirects are not followed.** An API that answers a token exchange with a 302 is an API
 *   whose answer we should read, not one to chase — and following one would replay the request,
 *   credentials and body included, at an address the far end chose.
 * - **Certificates are verified**, stated rather than inherited. It is curl's default and it is the
 *   line a credential rides on; leaving it implicit invites someone to turn it off while debugging
 *   and never turn it back on.
 */
final readonly class CurlTransport implements Transport
{
    /** Long enough for a slow DNS answer, short enough that an unreachable host is not a hang. */
    private const int CONNECT_TIMEOUT = 15;

    /** Seconds under {@link self::STALL_FLOOR} before a transfer counts as dead. */
    private const int STALL_TIMEOUT = 60;

    /** Bytes per second. One, because the question is "moving at all", not "moving fast". */
    private const int STALL_FLOOR = 1;

    /**
     * @param Request $request
     * @return Response
     * @throws TransportException if the extension is missing, the URL cannot be handled, or the
     *                            transfer never produced a response.
     */
    public function send(Request $request): Response
    {
        if (!extension_loaded('curl')) {
            throw new TransportException(
                'ext/curl is not loaded, so this command cannot make a request. It is the one '
                . 'extension the tooling needs that the site does not — nothing under src/ ever '
                . 'makes an outbound request.',
            );
        }

        $handle = curl_init($request->url->render());

        if ($handle === false) {
            throw new TransportException(sprintf('curl cannot handle the URL %s.', $request->url->render()));
        }

        curl_setopt_array($handle, $this->options($request));

        $body   = curl_exec($handle);
        $status = curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $error  = curl_error($handle);

        if ($body === false) {
            throw new TransportException(sprintf(
                '%s %s never produced a response: %s',
                $request->method->value,
                $request->url->render(),
                $error !== '' ? $error : 'curl gave no reason',
            ));
        }

        return new Response((int) $status, (string) $body);
    }

    /**
     * The handle's options: the fixed ones, the headers, and the body if there is one.
     *
     * @param Request $request
     * @return array<int, mixed>
     */
    private function options(Request $request): array
    {
        $options = [
            CURLOPT_RETURNTRANSFER  => true,
            CURLOPT_CUSTOMREQUEST   => $request->method->value,
            CURLOPT_HTTPHEADER      => $this->headers($request),
            CURLOPT_FOLLOWLOCATION  => false,
            CURLOPT_SSL_VERIFYPEER  => true,
            CURLOPT_SSL_VERIFYHOST  => 2,
            CURLOPT_CONNECTTIMEOUT  => self::CONNECT_TIMEOUT,
            CURLOPT_TIMEOUT         => 0,
            CURLOPT_LOW_SPEED_LIMIT => self::STALL_FLOOR,
            CURLOPT_LOW_SPEED_TIME  => self::STALL_TIMEOUT,
        ];

        if (!$request->hasBody()) {
            return $options;
        }

        // A multipart body is handed over as an array so that curl assembles it and streams each
        // file off disk; a form body is a string this already knows how to write. Either way the
        // body is built in one place, which is what keeps FilePart free of a curl type.
        $options[CURLOPT_POSTFIELDS] = $request->multipart ? $this->parts($request) : $request->body();

        return $options;
    }

    /**
     * The multipart fields, as curl wants them: an array, with each file as a `CURLFile`.
     *
     * @param Request $request
     * @return array<string, string|CURLFile>
     */
    private function parts(Request $request): array
    {
        $parts = [];

        foreach ($request->fields as $field) {
            $parts[$field->name] = $field->value instanceof FilePart
                ? new CURLFile($field->value->file->path, $field->value->type->render(), $field->value->filename)
                : $field->value;
        }

        return $parts;
    }

    /**
     * The headers, in the `Name: value` form curl takes — which is {@link Header::line()}.
     *
     * @param Request $request
     * @return list<string>
     */
    private function headers(Request $request): array
    {
        return $request->headers->map(static fn(Header $header): string => $header->line())->toValues();
    }
}
