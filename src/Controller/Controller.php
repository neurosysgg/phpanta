<?php

declare(strict_types=1);

namespace Phpanta\Controller;

use Phpanta\Http\Request;
use Phpanta\Http\Response;

/**
 * The Controller interface. Handles an HTTP request and returns a response.
 */
interface Controller
{
    /**
     * Handles the request and returns a response.
     *
     * @param Request $request The incoming request.
     * @return Response The response to send.
     */
    public function handle(Request $request): Response;
}
