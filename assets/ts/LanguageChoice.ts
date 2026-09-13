import { HtmlAttribute } from './model/HtmlAttribute.js';
import { HtmlTag } from './model/HtmlTag.js';
import { LinkRel } from './model/LinkRel.js';

/**
 * Which language a visitor lands in, on a host that cannot choose for them.
 *
 * A server chooses from the `lang` cookie and `Accept-Language`, and a static host can do neither:
 * it serves the file it was asked for. A site whose languages have addresses of their own states a
 * page's address in each language in its head — `<link rel="alternate" hreflang="de" href="…">` —
 * and this makes, once and on the client, the choice the server would have made:
 *
 * - **An address that names its language is never redirected.** `/rules.de.html` is a link to the
 *   German page, and whoever shared it meant German. Nor can this ever send a visitor round in a
 *   circle, since every address it sends one to names its language.
 * - **A plain address goes to the language the visitor chose with the page's own switch**, which is
 *   remembered across visits;
 * - **else, once, to the first language their browser asks for that the page offers** — what
 *   `Accept-Language` would have done — and never again, so a visitor who then goes back to the
 *   default language is left there.
 *
 * Every address comes from the page's own links, never built from the root, so a site exported
 * under a base path needs nothing of this. The choice is kept in `localStorage`, which a private
 * window or a blocked site may refuse; it then lasts as long as the page does, and nothing breaks.
 *
 * The one cost is a moment, on a first visit in another language, where the default's page shows:
 * this runs once the body has been parsed. The server's answer has no such moment, and a static host
 * cannot give one.
 */
export class LanguageChoice {
  /** The key a choice made with the switch is kept under. */
  private static readonly CHOSEN = 'phpanta:language';

  /** The key that says the browser's language has been followed once. */
  private static readonly FOLLOWED = 'phpanta:language-followed';

  /** A page's address in one language, as its head states it. */
  private static readonly ALTERNATE =
    `${HtmlTag.Link}[${HtmlAttribute.Rel}~="${LinkRel.Alternate}"][${HtmlAttribute.HrefLang}]`;

  /** A link to the page in a language it names — the switch. */
  private static readonly SWITCH = `${HtmlTag.A}[${HtmlAttribute.HrefLang}]`;

  /** Everything after a language tag's primary subtag: `-DE` in `de-DE`. */
  private static readonly SUBTAGS = /-.*$/s;

  private constructor(private readonly addresses: ReadonlyMap<string, URL>) {}

  /**
   * The choice for this document, or null for one that states its address in fewer than two
   * languages — a page with nothing to choose between, or a site that negotiates on its server.
   */
  public static forDocument(): LanguageChoice | null {
    const addresses = new Map<string, URL>();

    document.querySelectorAll<HTMLLinkElement>(LanguageChoice.ALTERNATE).forEach((link) => {
      LanguageChoice.keep(addresses, link);
    });

    return addresses.size < 2 ? null : new LanguageChoice(addresses);
  }

  /**
   * Keeps a stated address as the choice reads it: by its language, lower-cased.
   *
   * A method of its own rather than inline, because the compiled module is type-checked again as
   * JavaScript by the site that tests it, where the element type the query above names is gone and
   * only a parameter is left untyped enough to read `hreflang` from.
   */
  private static keep(addresses: Map<string, URL>, link: HTMLLinkElement): void {
    addresses.set(link.hreflang.toLowerCase(), new URL(link.href));
  }

  /** Remembers what the switch is used for, then goes to the language this visitor reads. */
  public start(): void {
    document.addEventListener('click', (e) => { this.onClick(e); });
    this.arrive();
  }

  /**
   * A click on the switch is a choice: it is remembered, and the click goes on to do what it would
   * have — whatever handles links, Navigation or the browser, is not this file's business.
   */
  private onClick(e: MouseEvent): void {
    if (!(e.target instanceof Element)) return;

    const chosen = e.target.closest<HTMLAnchorElement>(LanguageChoice.SWITCH)?.hreflang.toLowerCase();

    if (chosen !== undefined && this.addresses.has(chosen)) {
      LanguageChoice.write(LanguageChoice.CHOSEN, chosen);
    }
  }

  /** Goes to the page in the visitor's language, where it is not the one showing. */
  private arrive(): void {
    for (const address of this.addresses.values()) {
      if (address.pathname === location.pathname) return;
    }

    const language = this.chosen() ?? this.followed();
    const target   = language === null || language === document.documentElement.lang
      ? undefined
      : this.addresses.get(language);

    if (target === undefined) return;

    const url = new URL(target);

    url.hash = location.hash;

    // replace(), not assign(): the plain address was never a page the visitor meant to keep, and
    // back should not return them to it only to be sent on again.
    location.replace(url.href);
  }

  /** The language the visitor chose with the switch, if it is one this page is in. */
  private chosen(): string | null {
    const chosen = LanguageChoice.read(LanguageChoice.CHOSEN);

    return chosen !== null && this.addresses.has(chosen) ? chosen : null;
  }

  /**
   * The first language the browser asks for that this page is in — asked only once, ever, so that
   * a visitor the browser's language was wrong for is not sent back to it on every plain address.
   */
  private followed(): string | null {
    if (LanguageChoice.read(LanguageChoice.FOLLOWED) !== null) return null;

    LanguageChoice.write(LanguageChoice.FOLLOWED, '1');

    for (const tag of navigator.languages) {
      const primary = tag.toLowerCase().replace(LanguageChoice.SUBTAGS, '');

      if (this.addresses.has(primary)) return primary;
    }

    return null;
  }

  /** What is kept under `key`, or null where nothing is or storage is refused. */
  private static read(key: string): string | null {
    try {
      return localStorage.getItem(key);
    } catch {
      return null;
    }
  }

  /** Keeps `value` under `key`, where storage allows it; where it does not, the page forgets. */
  private static write(key: string, value: string): void {
    try {
      localStorage.setItem(key, value);
    } catch {
      // Refused — a private window, or a site whose storage is blocked. The choice lasts this page.
    }
  }
}
