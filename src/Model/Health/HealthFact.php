<?php

declare(strict_types=1);

namespace Phpanta\Model\Health;

use JsonSerializable;
use Phpanta\Http\Api\ResultKey;
use Phpanta\View\Html\Element;
use Phpanta\View\Html\HtmlTag;

/**
 * The HealthFact class. One thing the `capability` or `health` service has to say, and the one line
 * it says it on.
 *
 * A class rather than the `array{string, string}` it would otherwise be, for
 * {@link \Phpanta\Model\Api\VerifiedRequest}'s reason: a two-slot tuple is destructured in the one
 * place that reads it, where `[$name, $value] = $pair` only reads correctly if you already know
 * the answer. Two named properties need no excuse and nothing remembered.
 *
 * **Its whole behaviour is one column.** A report is read by running an eye down the values, and
 * that works only if the sections of one response align with each other rather than each with
 * itself — so the column has a floor here that every section starts from, and a section widens it
 * only for a name that would otherwise overrun. See {@link HealthSection::facts()}.
 *
 * The indent is deliberately **not** here: a fact renders its own line and {@link HealthSection}
 * places it, which is what lets a section of log lines sit at the same indent without a second
 * class knowing the number.
 */
final readonly class HealthFact implements JsonSerializable
{
    /**
     * How wide the name column is at least.
     *
     * Sized for the names a report of an installation's own facts has — `max_execution_time`, at
     * eighteen, is the longest a health check prints. `capability v1 settings` lists every
     * directive the engine has, and those run to nearly forty, which is why a section may widen
     * this rather than let three hundred lines each overrun by a different amount.
     */
    public const int COLUMN = 20;

    /**
     * What a fact with nothing to say says.
     *
     * The same dash {@link \Phpanta\Service\Api\UpdateVersion} writes for a serial no push has
     * ever set, and for the same reason: an empty value in a column of values is indistinguishable
     * from a line that failed to render.
     *
     * **Asked as `=== ''` and not as `?:`, which is where that class can be copied and this one
     * cannot.** A serial is a `time()` and is never `'0'`, so the falsy test is safe there. Half
     * the values here are php.ini directives, and `max_execution_time` is **`'0'`** on a runtime
     * with no limit at all — a real answer, and the most interesting one that directive has.
     * `?:` reported it as nothing, which was this report's first bug and was visible in its first
     * run.
     */
    private const string NOTHING = '-';

    /**
     * Constructs an instance of {@link self}.
     *
     * @param string $name What is being reported. Wherever the fact has a vocabulary of its own
     *                     this is that vocabulary's backing value rather than a literal — a
     *                     {@link PhpSetting}, a {@link PhpExtension}, a {@link \Phpanta\DataFileName}
     *                     — so a name in the report is a name in the code.
     * @param string $value Its value, or `''` where there is none to be had. Empty and absent
     *                      collapse the way {@link \Phpanta\Support\File::read()} collapses them:
     *                      to whoever is reading, both mean this did not tell us anything.
     * @param Verdict|null $verdict What a health check made of it, or null for a plain fact.
     */
    public function __construct(
        public string   $name,
        public string   $value,
        public ?Verdict $verdict = null,
    ) {}

    /**
     * The fact's line: the name in its column, then the verdict where there is one, then the value.
     *
     * **The verdict comes first after the name**, because it is the one column worth reading down:
     * the value varies in width from `1` to an absolute path, so anything after it is ragged, and
     * the thing a reader scans a report for must not be.
     *
     * A name longer than the column is not cut down: it pushes its own value across by however much
     * it overruns. That is the right failure for a report — one ragged line, rather than a name
     * truncated into a different name.
     *
     * @param int $column How wide the name column is; {@link HealthSection} passes its own.
     * @return string
     */
    public function render(int $column = self::COLUMN): string
    {
        return str_pad($this->name, $column) . ' '
            . ($this->verdict === null ? '' : sprintf('%-4s  ', $this->verdict->label()))
            . $this->shown();
    }

    /**
     * The fact as data: its name and value, and its verdict where it has one. The value is written
     * as it was given, `''` included — the dash is for a column of text, and data can say empty.
     *
     * @return mixed
     */
    public function jsonSerialize(): mixed
    {
        $fact = [ResultKey::Name->value => $this->name, ResultKey::Value->value => $this->value];

        return $this->verdict === null ? $fact : [...$fact, ResultKey::Verdict->value => $this->verdict->value];
    }

    /**
     * The fact as a table row: the name, the verdict where there is one, the value.
     *
     * @return Element
     */
    public function row(): Element
    {
        $cells = [new Element(HtmlTag::Td)->containing($this->name)];

        if ($this->verdict !== null) {
            $cells[] = new Element(HtmlTag::Td)->containing($this->verdict->label());
        }

        $cells[] = new Element(HtmlTag::Td)->containing($this->shown());

        return new Element(HtmlTag::Tr)->containing(...$cells);
    }

    /**
     * The value as a column of values shows it: a dash for nothing.
     *
     * @return string
     */
    private function shown(): string
    {
        return $this->value === '' ? self::NOTHING : $this->value;
    }
}
