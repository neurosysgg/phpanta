<?php

declare(strict_types=1);

namespace Phpanta\View;

use Phpanta\Text\Language;
use Phpanta\View\Html\Document;

/**
 * The Shell interface. The document a page is rendered inside — the head, the header, the footer.
 *
 * A view is only its own content; everything around it is the site's to draw, and one site's
 * header is nothing like another's. So the framework asks {@link \Phpanta\App::shell()} for this
 * and hands it each view whole, and {@link \Phpanta\Http\ViewResponse} never knows what the page
 * around its view looks like.
 */
interface Shell
{
    /**
     * $view as a whole document, in $language.
     *
     * @param View     $view
     * @param Language $language
     * @return Document
     */
    public function document(View $view, Language $language): Document;
}
