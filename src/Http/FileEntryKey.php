<?php

declare(strict_types=1);

namespace Phpanta\Http;

/**
 * The FileEntryKey enum. The keys of one `$_FILES` entry that {@link MultipartParameters} reads.
 *
 * PHP's names, typed for the reason {@link ServerVariable} is: a misspelled key reads as absent,
 * and absent is an answer — an `error` read under the wrong name is a file that seems to have
 * arrived with no fault and no bytes.
 */
enum FileEntryKey: string
{
    /** What the browser said the file was called — see {@link Upload::clientName()}. */
    case Name = 'name';

    /** Where PHP put the file: a temporary file of its own naming. */
    case TmpName = 'tmp_name';

    /** PHP's `UPLOAD_ERR_*` code for the file — an array of them, for a list of files. */
    case Error = 'error';

    /** How many bytes arrived. */
    case Size = 'size';
}
