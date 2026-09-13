<?php

declare(strict_types=1);

namespace Phpanta\Model\Update;

use NoDiscard;
use Phpanta\Model\Health\HealthFact;
use Phpanta\Model\Health\HealthSection;
use Phpanta\Support\Collection;

/**
 * The ProbeReport class. What `update v1 probe` found, as the endpoint's whole response body.
 *
 * **Facts, not verdicts**, in the shape `capability` answers in: a name in a column and what was
 * measured beside it. Whether a directory rename with a file held open inside it is good enough for
 * a staged push is a question for whoever designs one, and a report that answered it would be
 * deciding a design from inside a response.
 *
 * The one thing it does judge is itself: a probe that could not make its scratch directory, or
 * could not take it away again, is a 500 — the first measured nothing, and the second left
 * something on the server that the operator has to know about.
 */
final readonly class ProbeReport
{
    /**
     * Constructs an instance of {@link self}.
     *
     * @param Collection<HealthFact> $facts In the order they were measured.
     * @param bool $applied False for a dry run, so "nothing measured" cannot read as "nothing found".
     * @param bool $clean False when the probe could not start, or left something behind.
     */
    public function __construct(
        private Collection $facts,
        private bool $applied,
        private bool $clean,
    ) {}

    /**
     * True if the probe ran in a directory of its own and took every trace of itself away.
     *
     * @return bool
     */
    #[NoDiscard('this decides the response status; dropping it reports a littering probe as a 200')]
    public function isClean(): bool
    {
        return $this->clean;
    }

    /**
     * The response body.
     *
     * @return string
     */
    #[NoDiscard('the rendered report; dropping it sends an empty response')]
    public function render(): string
    {
        return HealthSection::document(HealthSection::facts(
            $this->applied ? 'filesystem' : 'filesystem — a dry run, so nothing was written or measured',
            $this->facts,
        ));
    }
}
