<?php

declare(strict_types=1);

namespace Phpanta\Tool\Http;

use NoDiscard;
use Phpanta\Http\Header;
use Phpanta\Http\HeaderValue;
use Phpanta\Http\ResponseHeader;
use Phpanta\Support\SearchableCollection;

/**
 * The CookieJar class. The cookies a server has set, as a browser keeps them for one origin, and the
 * `Cookie` header that sends them back.
 *
 * Only as much of a browser as a command that plays one needs: a cookie is its name and its value,
 * a later one of the same name replaces it, and one set to expire is gone. Paths, domains and
 * lifetimes are not kept — every request goes to the one origin the command was pointed at.
 * Immutable, so a command can keep a copy from before an answer and send it again, the way a copied
 * cookie is sent.
 */
final readonly class CookieJar implements HeaderValue
{
    /** A `Set-Cookie` that ends the cookie rather than setting it. */
    private const string EXPIRED = '/;\s*max-age=0\s*(?:;|\z)/i';

    /**
     * Constructs an instance of {@link self}.
     *
     * @param SearchableCollection<string> $cookies Each value by its cookie's name; '' for one that ended.
     */
    private function __construct(private SearchableCollection $cookies) {}

    /**
     * A jar holding nothing, as a browser arrives.
     *
     * @return self
     */
    public static function empty(): self
    {
        return new self(new SearchableCollection('string'));
    }

    /**
     * This jar, with every cookie $response set or ended.
     *
     * @param Response $response
     * @return self
     */
    #[NoDiscard('keeping() returns the jar with the answer\'s cookies in it; the one it was called on is unchanged')]
    public function keeping(Response $response): self
    {
        $cookies = $this->cookies;

        foreach ($response->values(ResponseHeader::SetCookie) as $set) {
            $pair  = explode(';', $set, 2)[0];
            $name  = trim(explode('=', $pair, 2)[0]);
            $value = trim(explode('=', $pair, 2)[1] ?? '');

            $cookies = $cookies->with($name, preg_match(self::EXPIRED, $set) === 1 ? '' : $value);
        }

        return new self($cookies);
    }

    /**
     * Whether the jar holds no cookie to send.
     *
     * @return bool
     */
    public function isEmpty(): bool
    {
        return $this->render() === '';
    }

    /**
     * The `Cookie` header sending these back.
     *
     * @return Header
     */
    public function header(): Header
    {
        return new Header(OutboundHeader::Cookie, $this);
    }

    /**
     * `name=value; name=value`, every cookie that has not ended.
     *
     * @return string
     */
    public function render(): string
    {
        $pairs = '';

        foreach ($this->cookies as $name => $value) {
            if ($value !== '') {
                $pairs .= ($pairs === '' ? '' : '; ') . $name . '=' . $value;
            }
        }

        return $pairs;
    }
}
