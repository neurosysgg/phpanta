<?php

declare(strict_types=1);

namespace Phpanta\Http;

use Phpanta\Support\BareArray;

/**
 * The ServerParameters class. The server variables a request arrived with, and the one reader of
 * them.
 *
 * `$_SERVER` is a superglobal, so a class that reads it directly can be handed no other request:
 * a test that wanted a POST with a credential had to overwrite the superglobal, build the request,
 * and put it back. This holds the map instead — {@link self::fromGlobals()} for the request the
 * process was started for, `new ServerParameters([...])` for any other — and {@link Request::from()}
 * reads a request out of whichever it is given.
 *
 * It is asked in the two vocabularies the keys come in: a {@link ServerVariable} for a name the
 * framework spells outright, and a {@link RequestHeader} for a header, whose key PHP derives — see
 * {@link RequestHeader::serverKey()}.
 */
final readonly class ServerParameters
{
    /** @var array<array-key, mixed> */
    #[BareArray(
        'the door: $_SERVER as PHP hands it over — a map of whatever the SAPI set, typed by nothing, '
        . 'which this class exists to be the one reader of',
    )]
    private array $values;

    /**
     * Constructs an instance of {@link self}.
     *
     * @param array<array-key, mixed> $values The server variables, keyed as `$_SERVER` keys them.
     */
    #[BareArray('the door: the same map as self::$values, on its way in')]
    public function __construct(array $values)
    {
        $this->values = $values;
    }

    /**
     * The server variables this process was started with.
     *
     * @return self
     */
    public static function fromGlobals(): self
    {
        return new self($_SERVER);
    }

    /**
     * $variable's value, or null if it did not arrive.
     *
     * Null for a key that is absent *and* for one holding something other than a string. Callers
     * supply their own default, because the right one differs: a missing method reads as `GET`, a
     * missing target as `/`, and a missing header as `''`.
     *
     * @param ServerVariable $variable
     * @return string|null
     */
    public function string(ServerVariable $variable): ?string
    {
        return $this->value($variable->value);
    }

    /**
     * One request header's value, or `''` if it did not arrive.
     *
     * @param RequestHeader $header
     * @return string
     */
    public function header(RequestHeader $header): string
    {
        return $this->value($header->serverKey()) ?? '';
    }

    /**
     * @param string $key
     * @return string|null
     */
    private function value(string $key): ?string
    {
        return isset($this->values[$key]) && is_string($this->values[$key]) ? $this->values[$key] : null;
    }
}
