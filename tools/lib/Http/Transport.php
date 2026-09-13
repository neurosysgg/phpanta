<?php

declare(strict_types=1);

namespace Phpanta\Tool\Http;

/**
 * The Transport interface. Something that can send a {@link Request} and hand back a
 * {@link Response}.
 *
 * One method, and it exists to be replaced. {@link CurlTransport} is the only implementation that
 * touches a network; a test hands the client an implementation that answers from an array and
 * records what it was asked — the same arrangement {@link \Phpanta\Tool\Cli\Output} has with its
 * two streams, and for the same reason: the interesting half of an API client is *what it sends*,
 * and that half cannot be asserted against a method that ends in a socket.
 *
 * It is deliberately not a general HTTP abstraction. Three shapes of request go out of this repo
 * — a form-encoded token exchange, a multipart upload, and the bare `GET` {@link
 * \NeuroSYS\Tool\SoundCloud\Client::track()} reads a secret token back with — and this is the
 * interface that carries exactly those. They are the three {@link Request} has a factory for, and
 * a fourth shape means a fourth factory rather than a wider interface here.
 */
interface Transport
{
    /**
     * Sends a request and returns what came back.
     *
     * A response is a response whatever its status: a 401 is something the caller has to read, not
     * something this may throw over. Only a transfer that never produced one — no route, no TLS, a
     * connection that died mid-body — is a {@link TransportException}.
     *
     * @param Request $request
     * @return Response
     * @throws TransportException if the request never produced a response at all.
     */
    public function send(Request $request): Response;
}
