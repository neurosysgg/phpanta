<?php

declare(strict_types=1);

namespace Phpanta\Http\Api;

use JsonSerializable;
use NoDiscard;
use Phpanta\Http\HttpStatusCode;
use Phpanta\Model\Health\HealthSection;
use Phpanta\Support\Collection;
use Phpanta\View\Html\Fragment;
use Phpanta\View\Html\Node;

/**
 * The ApiResult class. What an admin action answers, before anybody has decided what form it goes
 * out in.
 *
 * **A handler says what happened; the controller says how.** Every handler used to write its answer
 * as `text/plain` itself, which fixed the one form a caller could get at twenty call sites. Now a
 * handler returns this — a status and the sections of its report — and
 * {@link \Phpanta\Controller\ApiController} writes it as the {@link \Phpanta\Http\Representation}
 * the request asked for: a page in the app's shell for a browser, data for a script. The signing
 * CLI asks for the data and prints {@link self::text()} from it, so the text a terminal reads is
 * still written here, once, and not reassembled on the other side.
 *
 * A refusal is a result too — {@link self::refusal()} — so a verified caller who asked for data
 * gets data even when the answer is no.
 *
 * **A file is the one answer that is not a report**, and {@link self::file()} says so: its bytes are
 * what either kind of caller asked for, so it goes out as the file whatever the request would have
 * read, and has no sections to write.
 */
final readonly class ApiResult implements JsonSerializable
{
    /**
     * Constructs an instance of {@link self}.
     *
     * @param HttpStatusCode $status What the answer's status line says.
     * @param Collection<ResultSection> $sections In the order they are read.
     * @param ResultFile|null $file The file this answers with instead of a report, or null.
     */
    private function __construct(
        public HttpStatusCode $status,
        private Collection $sections,
        public ?ResultFile $file = null,
    ) {}

    /**
     * A result of these sections.
     *
     * @param HttpStatusCode $status
     * @param ResultSection ...$sections
     * @return self
     */
    public static function of(HttpStatusCode $status, ResultSection ...$sections): self
    {
        return new self($status, new Collection(ResultSection::class)->with(...$sections));
    }

    /**
     * A result that is one sentence saying why not — for a caller the gate has already verified,
     * so the sentence may say everything.
     *
     * @param HttpStatusCode $status
     * @param string $message One sentence, with no newline of its own.
     * @return self
     */
    public static function refusal(HttpStatusCode $status, string $message): self
    {
        return self::of($status, HealthSection::lines(null, $message));
    }

    /**
     * A result that is a file's bytes.
     *
     * @param ResultFile $file
     * @return self
     */
    public static function file(ResultFile $file): self
    {
        return new self(HttpStatusCode::Ok, new Collection(ResultSection::class), $file);
    }

    /**
     * The result as the text a terminal reads: each section, a blank line between them, and a
     * newline at the end.
     *
     * @return string
     */
    #[NoDiscard('text() writes the result out and changes nothing, so a dropped result does nothing')]
    public function text(): string
    {
        return HealthSection::document(...$this->sections->toValues());
    }

    /**
     * The result as part of a page: each section, in order.
     *
     * @return Node
     */
    public function node(): Node
    {
        return new Fragment(...$this->sections
            ->map(static fn(ResultSection $section): Node => $section->node())
            ->toValues());
    }

    /**
     * The result as data: the status again, so a script reading a saved body still knows it, and
     * the sections.
     *
     * @return mixed
     */
    public function jsonSerialize(): mixed
    {
        return [
            ResultKey::Status->value   => $this->status->value,
            ResultKey::Sections->value => $this->sections->toValues(),
        ];
    }
}
