<?php

declare(strict_types=1);

namespace Phpanta\Http;

use Phpanta\Exception\SecurityPolicyException;

/**
 * The BasicChallenge class. The `WWW-Authenticate` value every gate answers a 401 with.
 *
 * `Basic realm="…"`, and the quotes around the realm are grammar rather than decoration — the same
 * reason {@link ETag} owns its own. What makes it worth a type beyond that is the realm itself:
 * **the browser keys stored credentials by realm**, so two challenges differing by a character are
 * two separate password prompts to the same visitor, and neither would look wrong on its own.
 *
 * Only `Basic` is offered because only Basic is used, and because the scheme is not a detail to
 * pass in: a `Digest` or `Bearer` challenge has a different grammar and would be a different named
 * constructor rather than a different string. It is an {@link AuthScheme} case all the same — this
 * is the half that *writes* the token and {@link Request::fromGlobals()} is the half that reads it
 * back, and one spelling is what keeps them agreeing. See docs/security.md.
 *
 * **The realm is checked**, as every other {@link HeaderValue} that carries something other than a
 * fixed vocabulary is — {@link Location} refuses anything but an absolute `https://` URL,
 * {@link MimeType} refuses a malformed subtype, {@link Security\CspHost} refuses anything but a
 * bare origin. It matters most where a caller builds the realm out of a **URL segment**: a gate a
 * site builds on {@link \Phpanta\Service\Auth} may name each protected page's realm after its own
 * slug, because that is what keeps one page's saved password from being volunteered to another
 * page's prompt. A `"` in that slug would close the quoted-string early and leave the rest of it as
 * trailing rubbish in a response header, on a route that exists to give nothing away.
 *
 * Two things worth knowing about how far that can go and why it is still worth a check here:
 *
 * - **It is not header injection.** PHP's `header()` refuses a value containing CR or LF, and no
 *   request line can carry a raw one, so the worst available outcome is a malformed challenge
 *   rather than a second header.
 * - **A host may protect against it, and that is not something this code owns.** A shared host's
 *   proxy can percent-encode a non-`pchar` byte before PHP sees the target, so the realm arrives
 *   well-formed there while the same request through a bare Apache 2.4 does not. That is exactly
 *   the stance {@link Request::rawAuthorization()} already takes about the header every gate
 *   depends on: a shared host's behaviour is not a guarantee this code may rest on, and the
 *   failure is silent in both directions.
 *
 * The check throws, so it reports a realm built wrong *by the code* — the mistake a caller can
 * make. What a *visitor* can send is dealt with one layer out, by the caller encoding the slug —
 * `rawurlencode()` leaves nothing `qdtext` refuses — so a hostile target is still answered with a
 * 401 rather than with the 500 this would otherwise turn it into. Two layers, then: the encoding
 * answers the visitor, and the constructor catches the caller that forgot it.
 */
final readonly class BasicChallenge implements HeaderValue
{
    /**
     * What a realm may be made of: RFC 9110 §5.6.4's `qdtext`, one or more.
     *
     * HTAB, SP, `!`, `#`–`[`, `]`–`~` — which is every printable ASCII character except the two a
     * quoted-string cannot carry unescaped, `"` and `\`. `obs-text` is deliberately left out even
     * though the grammar permits it: RFC 7617 §2.2 says a realm should be US-ASCII, and every realm
     * a gate builds is {@link \Phpanta\App::name()}, perhaps with a slug after it.
     *
     * **The `+` rather than `*` is part of the check.** `realm=""` is legal and meaningless — an
     * empty realm keys every credential on the origin together, which is precisely the failure this
     * class's docblock is about, so it is refused rather than rendered.
     *
     * Escaping is *not* the alternative to this. A quoted-pair would let a `"` through as `\"`, and
     * that is the wrong answer for a value nothing here has a reason to put one in: refusing says
     * where the mistake is, where escaping would quietly render a realm nobody meant.
     *
     * It carries no `#[BareString]`, unlike {@link Location::URL_PATTERN}, and the difference is
     * exactly the rule: that pattern is a second copy of one a site may write for its own data, and
     * so a word written in two classes, where this one lives here and nowhere else. `GuidelineTest` said so
     * — an excuse was written for it first and the test refused it as an excuse for nothing.
     */
    private const string REALM_PATTERN = '/\A[\t\x20\x21\x23-\x5B\x5D-\x7E]+\z/';

    /**
     * Constructs an instance of {@link self}.
     *
     * @param string $realm What the browser labels and keys the saved credentials by. A gate a site
     *                      builds may pass the app's name plus a slug of its own, encoded; a name
     *                      nobody has checked goes through {@link self::encoding()} instead.
     *
     * @throws SecurityPolicyException if it is empty or holds anything but `qdtext`.
     */
    public function __construct(private string $realm)
    {
        if (preg_match(self::REALM_PATTERN, $this->realm) !== 1) {
            throw new SecurityPolicyException(sprintf(
                "A Basic realm is one or more of RFC 9110's qdtext — printable ASCII without '\"' "
                . "or '\\', or a space or tab — and never empty. Got '%s'.",
                $this->realm,
            ));
        }
    }

    /**
     * A challenge whose realm is $text with every byte `qdtext` refuses percent-encoded, `%` itself
     * included — so `Café "Lab"` is the realm `Caf%C3%A9 %22Lab%22`, and a plain name is itself.
     *
     * For a realm this code did not choose: the admin gate's is {@link \Phpanta\App::name()}, which a
     * site writes for its page titles and may well spell with an accent. Refusing that in the
     * constructor would make every 401 a 500, on the one door that exists to answer 401s. Still
     * refused when empty, which no encoding can repair.
     *
     * @param string $text
     * @return self
     *
     * @throws SecurityPolicyException if $text is empty.
     */
    public static function encoding(string $text): self
    {
        return new self(preg_replace_callback(
            '/[^\t\x20\x21\x23\x24\x26-\x5B\x5D-\x7E]/',
            static fn(array $byte): string => sprintf('%%%02X', ord($byte[0])),
            $text,
        ));
    }

    /**
     * Returns the header value: `Basic realm="example"`.
     *
     * @return string
     */
    public function render(): string
    {
        return AuthScheme::Basic->value . ' realm="' . $this->realm . '"';
    }
}
