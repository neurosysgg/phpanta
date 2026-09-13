<?php

declare(strict_types=1);

namespace Phpanta\Tool\Http;

use Phpanta\Http\Header;
use Phpanta\Http\HeaderName;
use Phpanta\Http\HttpMethod;
use Phpanta\Http\MimeType;
use Phpanta\Http\TopLevelType;
use Phpanta\Support\Collection;
use Phpanta\Support\SearchableCollection;

/**
 * The Request class. One request this tooling is about to send.
 *
 * The site's {@link \Phpanta\Http\Request} names the same thing pointing the other way — one is a
 * request the site *received* and answers, this is one a command *sends* and reads the answer to.
 * Neither is the other's inverse and neither shares a line of code with it; they share a word,
 * because there is only one word.
 *
 * Three factories rather than a constructor, because there are exactly three shapes of request in
 * this repo and each carries its body differently: a bare `GET`, a form-encoded body for the token
 * exchange, and a multipart body for the upload. A fourth shape is a fourth factory, and until
 * something needs one there is no fourth to get wrong.
 *
 * {@link HttpMethod} is the site's enum, reused rather than restated. Its docblock is written about
 * the methods the site *answers*, and the vocabulary is the same one either way round.
 */
final readonly class Request
{
    /**
     * Constructs an instance of {@link self}.
     *
     * @param HttpMethod $method
     * @param Url        $url
     * @param SearchableCollection<Header> $headers Keyed by header name, which is what keeps the
     *                                       last write: a request cannot end up carrying two
     *                                       `Authorization`s because two callers each added one.
     * @param Collection<FormField> $fields The body. Empty for a request that has none.
     * @param bool       $multipart Whether $fields go out as `multipart/form-data`. A
     *                              {@link FilePart} is only meaningful when this is true.
     * @param string|null $raw A body that is bytes rather than fields — see {@link self::raw()}.
     *                         Null for every other shape, which build their body from $fields.
     */
    private function __construct(
        public HttpMethod $method,
        public Url        $url,
        public SearchableCollection $headers,
        public Collection $fields,
        public bool       $multipart,
        private ?string   $raw = null,
    ) {}

    /**
     * A request with no body.
     *
     * @param Url    $url
     * @param Header ...$headers
     * @return self
     */
    public static function get(Url $url, Header ...$headers): self
    {
        return new self(
            HttpMethod::Get,
            $url,
            self::headers(...$headers),
            new Collection(FormField::class),
            false,
        );
    }

    /**
     * A `POST` whose body is form-encoded — the shape every OAuth token request takes.
     *
     * @param Url    $url
     * @param Collection<FormField> $fields
     * @param Header ...$headers
     * @return self
     */
    public static function form(Url $url, Collection $fields, Header ...$headers): self
    {
        return new self(
            HttpMethod::Post,
            $url,
            self::headers(
                new Header(
                    OutboundHeader::ContentType,
                    new MimeType(TopLevelType::Application, 'x-www-form-urlencoded', null),
                ),
                ...$headers,
            ),
            $fields,
            false,
        );
    }

    /**
     * A `POST` whose body is multipart, because one of its fields is a file.
     *
     * No `Content-Type` is set here on purpose: a multipart body carries a boundary, the boundary
     * belongs to whatever assembles the body, and a header written beside it would be a second
     * answer to a settled question — see {@link OutboundHeader::ContentType}.
     *
     * @param Url    $url
     * @param Collection<FormField> $fields
     * @param Header ...$headers
     * @return self
     */
    public static function multipart(Url $url, Collection $fields, Header ...$headers): self
    {
        return new self(HttpMethod::Post, $url, self::headers(...$headers), $fields, true);
    }

    /**
     * A `POST` whose body is bytes rather than fields.
     *
     * The fourth shape, and the class docblock above was already waiting for it: a signed update
     * payload is one opaque stream — magic, a manifest, a signature, a gzipped tar — so there is
     * nothing to encode and nothing to name. It carries `application/octet-stream` because that is
     * what it is, and because a `Content-Type` claiming any structure would be a claim the server
     * does not read and could not check.
     *
     * @param Url    $url
     * @param string $body
     * @param Header ...$headers
     * @return self
     */
    public static function raw(Url $url, string $body, Header ...$headers): self
    {
        return new self(
            HttpMethod::Post,
            $url,
            self::headers(
                new Header(
                    OutboundHeader::ContentType,
                    new MimeType(TopLevelType::Application, 'octet-stream', null),
                ),
                ...$headers,
            ),
            new Collection(FormField::class),
            false,
            $body,
        );
    }

    /**
     * Whether this request carries a body at all.
     *
     * Asked by {@link CurlTransport} instead of "does it have fields", which is not the same
     * question: a {@link self::raw()} request has no fields by construction, so that guard would
     * skip its body and send an empty POST — which the API, doing exactly what it is designed to
     * do, answers like an address that does not exist, the one failure that says nothing about why.
     *
     * The guard itself is still needed: a `GET` must not be given `CURLOPT_POSTFIELDS`, which would
     * turn it into a POST.
     *
     * @return bool
     */
    public function hasBody(): bool
    {
        return $this->raw !== null || !$this->fields->isEmpty();
    }

    /**
     * The body: the raw bytes where a request carries them, the form-encoding of its fields
     * otherwise.
     *
     * @return string
     */
    public function body(): string
    {
        if ($this->raw !== null) {
            return $this->raw;
        }

        $pairs = [];

        foreach ($this->fields->where(static fn(FormField $field): bool => !$field->isFile()) as $field) {
            $pairs[$field->name] = $field->value;
        }

        return http_build_query($pairs);
    }

    /**
     * One header's value, or null where the request does not carry it.
     *
     * Here so that a fake {@link Transport} can assert what went out without reaching into an
     * array by a string literal, which is the spelling mistake this repo types away everywhere
     * else.
     *
     * @param HeaderName $name
     * @return Header|null
     */
    public function header(HeaderName $name): ?Header
    {
        return $this->headers->find($name->headerName());
    }

    /**
     * The headers, keyed by name.
     *
     * @param Header ...$headers
     * @return SearchableCollection<Header>
     */
    private static function headers(Header ...$headers): SearchableCollection
    {
        $collection = new SearchableCollection(Header::class);

        foreach ($headers as $header) {
            $collection = $collection->with($header->name->headerName(), $header);
        }

        return $collection;
    }
}
