<?php

declare(strict_types=1);

namespace Phpanta;

use BackedEnum;

/**
 * The DataFileName interface. A file an app reads out of `data/`, named rather than spelled.
 *
 * Two vocabularies implement it, for the reason {@link View\Html\TagName} has two: the framework's
 * own files — the credentials and the API key, {@link CredentialFile} — and each site's own enum,
 * which {@link App::ownDataFiles()} lists. {@link App::dataFile()} takes either and
 * {@link App::dataFiles()} lists both, so the health report and the deployment capability describe every file without
 * knowing which side declared it.
 *
 * **The value is the path under `data/`**, which is why this extends `BackedEnum`: a name is a case,
 * and a case has exactly one spelling. A misspelled file is not an error anywhere — every reader
 * collapses a missing file to an empty result, on purpose — so the spelling is the one thing worth
 * having the compiler check.
 */
interface DataFileName extends BackedEnum
{
    /**
     * Whether the repository carries this file, and so whether every clone has it.
     *
     * A tracked file has to be present for the site to be the site, and the health report asks for
     * each; an untracked one has its own reason to be absent, and the capability report says which.
     *
     * @return bool
     */
    public function isTracked(): bool;
}
