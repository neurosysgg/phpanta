<?php

declare(strict_types=1);

namespace Phpanta\Support;

/**
 * The UrlScheme enum. The schemes a URL an app writes may name.
 *
 * **The trailing colon is part of the value**, the same call
 * {@link \Phpanta\Http\Security\CspScheme} makes and for the same kind of reason: the colon is
 * what separates a scheme from a host, so `mailto` without it is a relative path called `mailto`
 * and matches nothing anybody meant.
 *
 * It lives here rather than in `View/Html/` because a scheme is a fact about a URL and not about
 * markup — the enums it would have sat beside there ({@link \Phpanta\View\Html\LinkRel},
 * {@link \Phpanta\View\Html\MetaName}, {@link \Phpanta\View\Html\ScriptType}) are all vocabularies
 * of HTML itself. Same placement argument {@link Charset} carries, and the same two-reader shape:
 * {@link \Phpanta\View\Html\Element} asks which schemes are allowed, and a view builds a
 * `mailto:` address.
 *
 * Getting one wrong is silent in both directions. A `mailto:` misspelled is a contact link the
 * browser treats as a relative path and answers with the app's own 404; and the allowlist is what
 * stands between an `href` and a `javascript:` URL, which nothing else on the way out would catch —
 * escaping does not touch a single character of it.
 *
 * **Two cases, and what is absent is a decision rather than an omission.** No `http:`, because
 * {@link \Phpanta\Http\Security\StrictTransportSecurity} means an app does not emit one. No
 * `data:`, because a `data:text/html` document runs script in the origin that navigated to it. Both
 * absences are load-bearing — see {@link \Phpanta\View\Html\Element::URL_SCHEMES}, which is the
 * list of what is switched on, as against this, which is the vocabulary it may be written in.
 */
enum UrlScheme: string
{
    /** Everything off-origin an app links to: a file host, a profile somewhere else. */
    case Https = 'https:';

    /** An e-mail address: a contact line, and nowhere else. */
    case Mailto = 'mailto:';

    /**
     * The scheme followed by $target — `mailto:hello@example.com`.
     *
     * A method rather than a concatenation at each call site, for the reason every other builder
     * here is one: `'mailto:' . $address` written in two files is two spellings of one prefix,
     * which is how one of them loses a colon.
     *
     * $target is not validated and deliberately not: what a scheme may be followed by differs per
     * scheme — an address, an origin and a path share no grammar — and the check that matters
     * happens on the way out anyway, in {@link \Phpanta\View\Html\Element::render()}, which is
     * where every URL attribute goes whether it was built here or not.
     *
     * @param string $target What comes after the colon.
     * @return string
     */
    public function url(string $target): string
    {
        return $this->value . $target;
    }
}
