<?php

declare(strict_types=1);

namespace PhpantaSite;

use Phpanta\Controller\Controller;
use Phpanta\Exception\AppException;
use Phpanta\Http\Request;
use Phpanta\Http\Response;
use Phpanta\Http\ViewResponse;

/**
 * One page of prose, read out of `data/`.
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
     *
     * @throws AppException if the page's file is missing — every page is part of the repository, so
     *                      a missing one is a broken checkout, and exporting an empty page would hide it.
     */
    public function handle(Request $request): Response
    {
        $html = Site::current()->dataFile($this->page)->read() ?? throw new AppException(sprintf(
            'data/%s is missing, so %s has nothing to show.',
            $this->page->value,
            $this->page->path()->value,
        ));

        return new ViewResponse(new PageView($this->page, $html));
    }
}
