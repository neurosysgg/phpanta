<?php

declare(strict_types=1);

namespace Phpanta\Exception;

use LogicException;

/**
 * The RequirementException class. Thrown when a requirement is declared with something it cannot
 * check — an extension with no name, a directive with no name, a floor below zero, a version that
 * is not a version.
 *
 * It fires while {@link \Phpanta\Support\RequirementInitialization} is building the declared set,
 * which is the point: a malformed declaration stops the call there, naming the value, instead of
 * reaching a report as a line that always passes or always fails for a reason nobody can see.
 *
 * **Extends `LogicException`**, for {@link ReleaseVerificationException}'s reason: nothing recovers
 * from a requirement written wrong and nothing should try. It is "something in this repository is
 * written wrong, go and fix it", so no construction of a requirement owes an `@throws`.
 *
 * **An unmet requirement is not this.** A host without `ext/dom` is a verdict — a `fail` line and a
 * 503 — and never an exception; see {@link \Phpanta\Model\Health\Requirement}.
 */
class RequirementException extends LogicException implements SiteException
{
}
