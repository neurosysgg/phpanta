<?php

declare(strict_types=1);

namespace Phpanta\Test;

use Phpanta\App;
use Phpanta\Http\Answer;
use Phpanta\Http\FileEntryKey;
use Phpanta\Http\FormEncoding;
use Phpanta\Http\HttpMethod;
use Phpanta\Http\MultipartParameters;
use Phpanta\Http\Parameter;
use Phpanta\Http\Request;
use Phpanta\Http\RequestHeader;
use Phpanta\Http\ServerParameters;
use Phpanta\Http\ServerVariable;
use Phpanta\Support\File;

/**
 * A request a test builds, and the answer the booted app gives it — the in-process client.
 *
 * ```php
 * $answer = TestRequest::to(HttpMethod::Post, '/')->answer();
 *
 * self::assertSame(HttpStatusCode::MethodNotAllowed, $answer->status());
 * ```
 *
 * It builds the server variables a real server would hand PHP, keyed through the framework's own
 * {@link ServerVariable} and {@link RequestHeader::serverKey()} rather than retyped, and reads a
 * {@link Request} out of them with {@link Request::from()} — the same path a real request takes, so
 * what is tested is the parsing as well as the answer. Nothing touches `$_SERVER`.
 *
 * {@link self::answer()} is {@link App::handle()}: the app's layers, the router, the controller and
 * the security headers, everything but the send. What it cannot show is the server around it — a
 * body PHP drops for a HEAD, a module that compresses, a header `.htaccess` adds — which is what an
 * end-to-end check over real HTTP is still for. An answer to a HEAD for a page therefore still
 * carries the page here, as it does on its way to the server.
 */
final readonly class TestRequest
{
    /**
     * @param array<string, string>               $server
     * @param string|null                         $body
     * @param array<string, string>               $fields A multipart body's fields, as `$_POST` holds them.
     * @param array<string, array<string, mixed>> $files  Its files, as `$_FILES` holds them.
     */
    private function __construct(
        private array $server,
        private ?string $body = null,
        private array $fields = [],
        private array $files = [],
    ) {}

    /**
     * A GET for $target.
     *
     * @param string $target The request target as a client sends it: a path, a query, anything.
     * @return self
     */
    public static function get(string $target): self
    {
        return self::to(HttpMethod::Get, $target);
    }

    /**
     * $method for $target.
     *
     * The method may be a string, because a request can arrive with a verb {@link HttpMethod} does
     * not name — `BREW` — and how the framework answers one is worth a test.
     *
     * @param HttpMethod|string $method
     * @param string            $target
     * @return self
     */
    public static function to(HttpMethod|string $method, string $target): self
    {
        return new self([
            ServerVariable::RequestMethod->value => $method instanceof HttpMethod ? $method->value : $method,
            ServerVariable::RequestUri->value    => $target,
        ]);
    }

    /**
     * This request with $header set to $value.
     *
     * @param RequestHeader $header
     * @param string        $value
     * @return self
     */
    public function with(RequestHeader $header, string $value): self
    {
        return new self([...$this->server, $header->serverKey() => $value], $this->body, $this->fields, $this->files);
    }

    /**
     * This request with $variable set to $value — a server variable no header derives, such as an
     * `Authorization` or a `DOCUMENT_ROOT`.
     *
     * @param ServerVariable $variable
     * @param string         $value
     * @return self
     */
    public function withServer(ServerVariable $variable, string $value): self
    {
        return new self([...$this->server, $variable->value => $value], $this->body, $this->fields, $this->files);
    }

    /**
     * This request carrying Basic credentials, the way Apache hands them to PHP once it has
     * decoded them.
     *
     * @param string $user
     * @param string $password
     * @return self
     */
    public function withCredentials(string $user, string $password): self
    {
        return $this->withServer(ServerVariable::AuthUser, $user)->withServer(ServerVariable::AuthPassword, $password);
    }

    /**
     * This request with $body, which {@link Request::body()} then answers instead of `php://input`.
     *
     * @param string $body
     * @return self
     */
    public function withBody(string $body): self
    {
        return new self($this->server, $body, $this->fields, $this->files);
    }

    /**
     * This request as a multipart form, sending $value as $parameter — what PHP would have parsed
     * into `$_POST`.
     *
     * @param Parameter $parameter
     * @param string    $value
     * @return self
     */
    public function withField(Parameter $parameter, string $value): self
    {
        return new self(
            self::multipart($this->server),
            $this->body,
            [...$this->fields, (string) $parameter->value => $value],
            $this->files,
        );
    }

    /**
     * This request as a multipart form, sending $file as $parameter under $clientName — what PHP
     * would have kept in a temporary file and named in `$_FILES`. The test owns $file, and removes it.
     *
     * @param Parameter $parameter
     * @param File      $file
     * @param string    $clientName What the browser says the file was called.
     * @param int       $error      PHP's `UPLOAD_ERR_*` code: anything but OK sends no file.
     * @return self
     */
    public function withUpload(Parameter $parameter, File $file, string $clientName, int $error = UPLOAD_ERR_OK): self
    {
        $kept = $error === UPLOAD_ERR_OK;

        return new self(self::multipart($this->server), $this->body, $this->fields, [
            ...$this->files,
            (string) $parameter->value => [
                FileEntryKey::Name->value    => $clientName,
                FileEntryKey::TmpName->value => $kept ? $file->path : '',
                FileEntryKey::Error->value   => $error,
                FileEntryKey::Size->value    => $kept ? $file->size() : 0,
            ],
        ]);
    }

    /**
     * This request as a multipart form, sending each of $files as $parameter — one file input that
     * took several, which PHP keeps as a list: `$_FILES['name']['error'][0]` is the first file's error.
     * Each is sent under its own name. The test owns the files, and removes them.
     *
     * @param Parameter $parameter
     * @param File      ...$files
     * @return self
     */
    public function withUploads(Parameter $parameter, File ...$files): self
    {
        $entry = [
            FileEntryKey::Name->value    => [],
            FileEntryKey::TmpName->value => [],
            FileEntryKey::Error->value   => [],
            FileEntryKey::Size->value    => [],
        ];

        foreach ($files as $file) {
            $entry[FileEntryKey::Name->value][]    = $file->name();
            $entry[FileEntryKey::TmpName->value][] = $file->path;
            $entry[FileEntryKey::Error->value][]   = UPLOAD_ERR_OK;
            $entry[FileEntryKey::Size->value][]    = $file->size();
        }

        return new self(
            self::multipart($this->server),
            $this->body,
            $this->fields,
            [...$this->files, (string) $parameter->value => $entry],
        );
    }

    /**
     * The request itself, for a test that hands it to a controller or a gate directly.
     *
     * @return Request
     */
    public function request(): Request
    {
        return Request::from(
            new ServerParameters($this->server),
            $this->body,
            new MultipartParameters($this->fields, $this->files),
        );
    }

    /**
     * $server, saying its body is a multipart form.
     *
     * @param array<string, string> $server
     * @return array<string, string>
     */
    private static function multipart(array $server): array
    {
        return [
            ...$server,
            ServerVariable::ContentType->value => FormEncoding::Multipart->value . '; boundary=phpanta',
        ];
    }

    /**
     * What the booted app answers this request with.
     *
     * @return Answer
     */
    public function answer(): Answer
    {
        return App::current()->handle($this->request());
    }
}
