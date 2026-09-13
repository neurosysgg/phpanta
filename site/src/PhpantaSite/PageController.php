<?php

declare(strict_types=1);

namespace PhpantaSite;

use Phpanta\Controller\Controller;
use Phpanta\Http\Request;
use Phpanta\Http\Response;
use Phpanta\Http\ViewResponse;

/**
 * One page, in whichever language the request is answered in — which, for an address that names its
 * language, is that one.
 */
final readonly class PageController implements Controller
{
    /**
     * Constructs an instance of {@link self}.
     *
     * @param Page $page
     */
    public function __construct(private Page $page) {}

    /**
     * @param Request $request
     * @return Response
     */
    public function handle(Request $request): Response
    {
        return new ViewResponse($this->page->view());
    }
}
