<?php

declare(strict_types=1);

namespace Phpanta\Http;

/**
 * The Response interface. Represents an HTTP response that can be sent to the client.
 */
interface Response
{
    /**
     * Sends the response to the client.
     *
     * @param Request $request
     * @return void
     */
    public function send(Request $request): void;
}
