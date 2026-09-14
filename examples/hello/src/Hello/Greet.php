<?php

declare(strict_types=1);

namespace Hello;

use Phpanta\Controller\Controller;
use Phpanta\Http\Request;
use Phpanta\Http\Response;
use Phpanta\Http\ViewResponse;

/**
 * The one controller: whoever the address names, greeted — or the world.
 */
final readonly class Greet implements Controller
{
    /**
     * @param string|null $name
     */
    public function __construct(private ?string $name = null) {}

    /**
     * @param Request $request
     * @return Response
     */
    public function handle(Request $request): Response
    {
        return new ViewResponse(new Greeting($this->name));
    }
}
