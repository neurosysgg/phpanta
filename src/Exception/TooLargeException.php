<?php

declare(strict_types=1);

namespace Phpanta\Exception;

/**
 * The TooLargeException class. Thrown when what a request sent is larger than it may be — a form over
 * `post_max_size`, which PHP empties in silence, or a file over `upload_max_filesize`.
 *
 * **An {@link InputException}, answered with a 413 rather than a 400**: the request was readable,
 * only too large, and a sender told so can send less. {@link \Phpanta\Router} catches this one first.
 * A form field whose one file was too large is not a 413 at all — {@link \Phpanta\Form\Form::read()}
 * shows it beside the field, where the visitor can choose another.
 */
class TooLargeException extends InputException
{
}
