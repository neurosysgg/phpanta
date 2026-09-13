import { ElementId } from './model/ElementId.js';
import { HtmlAttribute } from './model/HtmlAttribute.js';
import { HtmlTag } from './model/HtmlTag.js';
import { LinkAttribute } from './model/LinkAttribute.js';
import { MediaType } from './model/MediaType.js';
import { RequestHeader } from './model/RequestHeader.js';
import { RequestedWith } from './model/RequestedWith.js';
import { ResponseHeader } from './model/ResponseHeader.js';

/** What a response puts on the page. A null title is one the response did not carry. */
interface Page {
  readonly title: string | null;
  readonly content: string;
}

/**
 * What this file keeps on a history entry: a key naming the entry, and where the page was scrolled
 * when the visitor left it — absent until they have.
 */
interface Entry {
  readonly key: string;
  readonly scrollY?: number;
}

/**
 * SPA navigation: intercept internal link clicks, fetch the page as a content fragment, and swap it
 * into #content. Every link is a real href, so direct loads and no-JS behave identically.
 *
 * Only the most recent navigation may write to the page — see the counter below. The rest of the
 * state is what a page load would have kept for free: where each history entry was scrolled, and
 * which entry and which document the visitor is on.
 */
export class Navigation {
  /**
   * Fired on `document` once #content has been replaced.
   *
   * Private, and reachable only through onNavigate(), so the name exists in one file and a typo
   * cannot break a listener in silence. Custom elements do not need it — the browser upgrades those
   * on its own. It is for anything that is not an element.
   */
  private static readonly EVENT = 'phpanta:navigate';

  /**
   * An anchor pointing somewhere on this site.
   *
   * Built from the same tag and attribute names the server writes rather than spelled out, so the
   * selector cannot go on matching nothing after a rename it was never told about.
   */
  private static readonly INTERNAL_LINK = `${HtmlTag.A}[${HtmlAttribute.Href}^="/"]`;

  /**
   * The <title> ViewResponse leads a fragment with.
   *
   * Named from HtmlTag for the same reason as the selector above, and deliberately not global —
   * the same expression is used to read the title and then to strip it, and a /g regex carries
   * lastIndex between those two calls.
   */
  private static readonly TITLE = new RegExp(
    `<${HtmlTag.Title}>([\\s\\S]*?)</${HtmlTag.Title}>`,
  );

  /**
   * How a whole document starts, as the markup tree's Doctype writes it.
   *
   * What tells the two answers apart: a server running the framework reads X-Requested-With and
   * sends a fragment, while a static host has no headers to read and sends the page itself.
   */
  private static readonly DOCUMENT = /^\s*<!doctype html/i;

  /**
   * A media type's parameters — `; charset=utf-8` — which say how the body is encoded rather than
   * what it is, so they go before the type is compared.
   */
  private static readonly PARAMETERS = /;[\s\S]*/;

  /**
   * Which navigation is the current one.
   *
   * Every call to go() takes the next number and checks it is still the holder before it touches
   * the DOM. Two quick clicks otherwise race: pushState has already put the second URL in the
   * address bar, and whichever response happens to land last wins the content — so a slow first
   * click beating a fast second one leaves the page showing one thing and the URL saying another,
   * with nothing anywhere reporting it.
   *
   * A counter rather than only an AbortController, because aborting races too: a fetch can resolve
   * before the abort is observed, and the assignment below would still run. The abort is worth
   * having as well — it stops a body nobody will read being downloaded — but the number is what
   * makes the guarantee.
   */
  private navigation = 0;

  /** Aborts the request the previous navigation is still waiting on, if there is one. */
  private inFlight: AbortController | null = null;

  /**
   * Where each entry this document has shown was left scrolled, by the entry's key.
   *
   * The entry carries its own position too, but only when this file could write to it while the
   * visitor was still there — before a click moves off it, and as the document is unloaded. Back and
   * forward move off an entry before anything here hears of it, so the position of a page left that
   * way is kept only here, and it is what the forward button returns to.
   */
  private readonly positions = new Map<string, number>();

  /** The key of the entry the visitor is on. */
  private key = '';

  /**
   * The document #content shows, or the one a navigation in flight is bringing: path and query, no
   * fragment. What tells back or forward between two fragments of one page — which changes no
   * content — from back or forward to another page.
   */
  private shown = '';

  /** The live region a swap's new title is read out through. */
  private readonly announcer = Navigation.liveRegion();

  private constructor(private readonly content: HTMLElement) {}

  /**
   * Builds a Navigation for this document, or null when there is no #content to swap into.
   *
   * Returning null rather than throwing is the point: with no listeners registered every link
   * stays a plain href, which lands the visitor on the same page by the browser's own route.
   */
  public static forDocument(): Navigation | null {
    const content = document.getElementById(ElementId.Content);

    return null === content ? null : new Navigation(content);
  }

  /** Runs `handler` every time #content is replaced. */
  public static onNavigate(handler: () => void): void {
    document.addEventListener(Navigation.EVENT, handler);
  }

  /** Starts intercepting link clicks and back/forward, and takes over restoring the scroll. */
  public start(): void {
    // The browser restores an entry's scroll the moment it is traversed to, which is before the
    // page that belongs to it has been fetched — so it would scroll the page being left. This file
    // restores it instead, once the content has arrived.
    history.scrollRestoration = 'manual';

    // Focusable by script and not by Tab, so a swap can move focus here without adding a tab stop.
    this.content.tabIndex = -1;
    document.body.append(this.announcer);

    this.shown = Navigation.documentOf(location.href);
    this.adopt();

    // A reload, or a return from another site, lands on an entry this file wrote on the way out, and
    // goes back to where it was left. Failing that, to the element the fragment names: manual
    // restoration tells the browser to leave the scroll alone on a reload, and it then skips the
    // fragment too — so `/rules#five-habits`, reloaded, would otherwise open at the top.
    Navigation.land(location.hash, Navigation.entryOf(history.state)?.scrollY);

    document.addEventListener('click', (e) => { this.onClick(e); });
    window.addEventListener('popstate', () => { this.onPopState(); });
    window.addEventListener('pagehide', () => { this.remember(true); });
  }

  private onClick(e: MouseEvent): void {
    // Something else — a handler of the page's own — has already decided what this click does.
    if (e.defaultPrevented) return;

    // Let the browser handle open-in-new-tab/window and non-primary buttons.
    if (e.metaKey || e.ctrlKey || e.shiftKey || e.altKey || 0 !== e.button) return;

    // A click can land on the document itself, and EventTarget has no closest().
    if (!(e.target instanceof Element)) return;

    // The selector is what makes the anchor type true.
    const link = e.target.closest<HTMLAnchorElement>(Navigation.INTERNAL_LINK);

    if (null === link || link.hasAttribute(LinkAttribute.NoSpa)) return;

    // A download saves the response rather than showing it, and a target opens it somewhere other
    // than this page. Both are the browser's to do, and neither leaves anything here to swap.
    if (link.hasAttribute(HtmlAttribute.Download) || !Navigation.opensHere(link)) return;

    // The selector matches the href *attribute*, and `//evil.example/x` starts with a slash exactly
    // as `/posts` does — a protocol-relative URL is a different origin wearing a path's clothes.
    // Everything below uses the *resolved* `link.href`, so the two readings have to be reconciled
    // here rather than assumed equal. Nothing the server emits is protocol-relative, and pushState
    // would throw a SecurityError on a cross-origin URL one line down, so today the consequence is
    // a link that does nothing rather than a hole — but "it throws slightly later" is not a reason,
    // and go() ends in innerHTML. Handing it back to the browser is both safe and correct.
    const url = new URL(link.href);

    if (url.origin !== location.origin) return;

    // A fragment of the page already showing is a scroll, not a navigation: the browser moves to it
    // and makes the history entry itself, and a fetch would replace the page it is scrolling.
    if (url.hash !== '' && Navigation.documentOf(url.href) === Navigation.documentOf(location.href)) {
      return;
    }

    e.preventDefault();
    this.remember(true);
    this.key = Navigation.freshKey();
    history.pushState({ key: this.key } satisfies Entry, '', url.href);
    void this.go(url.href, undefined);
  }

  /**
   * Back or forward. The entry being left has already been left, so its position can only go into
   * memory; the one arrived at is fetched and scrolled to where it was left — unless it is the page
   * already showing, reached through a fragment, when only the scroll moves.
   */
  private onPopState(): void {
    this.remember(false);
    this.adopt();

    const position = this.positions.get(this.key) ?? Navigation.entryOf(history.state)?.scrollY;

    if (Navigation.documentOf(location.href) !== this.shown) {
      void this.go(location.href, position);
      return;
    }

    Navigation.land(new URL(location.href).hash, position);
  }

  /**
   * Fetches `url` as a content fragment and swaps it into #content, then scrolls to `position` —
   * where the entry was left, when it is one the visitor is returning to.
   *
   * The assignment at the end is `innerHTML`, and that is the one assumption this file rests on:
   * the fragment is same-origin (onClick refuses anything else, and popstate can only reach a URL
   * the browser already navigated to) and it is built by the server's markup tree, where every
   * value is escaped by Text and every URL attribute is scheme-checked. So the
   * string being parsed here is one this codebase generated, not one it received.
   *
   * A static export's page is the same markup, written to disk by the same tree, so taking #content
   * out of a whole document spends the same guarantee and no other.
   *
   * Worth stating because it is inherited rather than enforced: the guarantee lives on the server,
   * and this line is where it is spent. Anything that ever puts markup into #content from another
   * source — a different endpoint, a third party, a value not rendered through the tree — reopens
   * DOM XSS here, and nothing in this file would notice.
   */
  private async go(url: string, position: number | undefined): Promise<void> {
    this.inFlight?.abort();

    const controller = new AbortController();
    const navigation = ++this.navigation;

    this.inFlight = controller;
    this.shown = Navigation.documentOf(url);

    try {
      const response = await fetch(url, {
        credentials: 'same-origin',
        headers: { [RequestHeader.RequestedWith]: RequestedWith.XmlHttpRequest },
        signal: controller.signal
      });

      if (navigation !== this.navigation) return;

      // A 404, or a file a route answers with, is the browser's to show. Every fallback here is
      // replace() rather than assign(): the entry for this URL already exists — pushState made it,
      // or back and forward arrived on it — and assign() would put a second one behind it.
      if (!response.ok || !Navigation.isPage(response)) {
        location.replace(url);
        return;
      }

      const html = await response.text();

      // Checked again after the second await: the body can arrive after a newer click has already
      // started, and by then this response is for a page the visitor has moved on from.
      if (navigation !== this.navigation) return;

      const page = Navigation.page(html);

      // A whole page with no #content has nothing to swap in; the browser can still show it.
      if (page === null) {
        location.replace(url);
        return;
      }

      if (page.title !== null) document.title = page.title;
      else console.warn('No title found in HTML response');

      this.content.innerHTML = page.content;
      document.dispatchEvent(new Event(Navigation.EVENT));
      this.arrive(new URL(url).hash, position);
    } catch {
      // An abort is this file cancelling itself, not a failure — the navigation that replaced this
      // one is already in flight, and handing the browser a URL the visitor has left would undo it.
      if (navigation !== this.navigation) return;

      // The entry already points at a page the visitor never got.
      // Hand the navigation back to the browser rather than strand them there.
      location.replace(url);
    }
  }

  /**
   * Leaves the visitor where a page load would have: focus on the new content rather than on a link
   * that has gone, the new title read out, and the page scrolled to where the entry was left, else
   * to the element the fragment names, else to the top.
   */
  private arrive(hash: string, position: number | undefined): void {
    // preventScroll, because the scroll is decided below and focusing would first pull #content's
    // top into view.
    this.content.focus({ preventScroll: true });
    this.announcer.textContent = document.title;

    if (!Navigation.land(hash, position)) window.scrollTo(0, 0);
  }

  /**
   * Keeps where the current entry is scrolled: in memory always, and on the entry itself as well
   * when it is still the current one — before a click moves off it, and as the document is
   * unloaded — so that a reload, or a return from another site, finds it there.
   */
  private remember(onEntry: boolean): void {
    this.positions.set(this.key, window.scrollY);

    if (onEntry) history.replaceState({ key: this.key, scrollY: window.scrollY } satisfies Entry, '');
  }

  /**
   * Takes the key of the entry the visitor is on, giving it one first if it has none — the
   * document's first entry, or one the browser made for a fragment.
   */
  private adopt(): void {
    const entry = Navigation.entryOf(history.state);

    if (entry !== null) {
      this.key = entry.key;
      return;
    }

    this.key = Navigation.freshKey();
    history.replaceState({ key: this.key } satisfies Entry, '');
  }

  /**
   * Scrolls to `position` if there is one, else to the element `hash` names. False when there is
   * neither, which leaves the choice to the caller.
   */
  private static land(hash: string, position: number | undefined): boolean {
    if (position !== undefined) {
      window.scrollTo(0, position);
      return true;
    }

    const target = Navigation.fragmentTarget(hash);

    target?.scrollIntoView();

    return target !== null;
  }

  /**
   * The element a fragment names: by the fragment as written, then percent-decoded — the order the
   * browser looks in, so `#caf%C3%A9` finds `id="café"`. An empty fragment names nothing, since no
   * element has an empty id.
   */
  private static fragmentTarget(hash: string): Element | null {
    const fragment = hash.slice(1);

    return document.getElementById(fragment) ?? Navigation.decodedTarget(fragment);
  }

  /** The element a fragment names once decoded, or null for one that does not decode. */
  private static decodedTarget(fragment: string): Element | null {
    try {
      return document.getElementById(decodeURIComponent(fragment));
    } catch {
      return null;
    }
  }

  /**
   * Whether a link opens in this page: no target, or `_self` — the HTML keyword, which browsers
   * compare without regard to case. Anything else is another window, and the browser's.
   */
  private static opensHere(link: HTMLAnchorElement): boolean {
    const target = link.target.toLowerCase();

    return target === '' || target === '_self';
  }

  /**
   * Whether a response is a page, and so something #content can hold. A route may answer with a
   * file, and its bytes written into the page would be noise where the browser would show it.
   */
  private static isPage(response: Response): boolean {
    const type = response.headers.get(ResponseHeader.ContentType) ?? '';

    return type.replace(Navigation.PARAMETERS, '').trim().toLowerCase() === MediaType.Html;
  }

  /** An entry's state read as this file writes it, or null for one it did not write. */
  private static entryOf(state: unknown): Entry | null {
    if (typeof state !== 'object' || state === null) return null;

    const { key, scrollY } = state as Record<string, unknown>;

    if (typeof key !== 'string') return null;

    return typeof scrollY === 'number' ? { key, scrollY } : { key };
  }

  /**
   * A key for a new entry. Random rather than counted, because entries outlive the document that
   * made them — a reload would start the count again under the same history — and not
   * `crypto.randomUUID()`, which a page served over plain HTTP does not have.
   */
  private static freshKey(): string {
    return Math.random().toString(36).slice(2);
  }

  /** The document a URL names: its path and query, without the fragment. */
  private static documentOf(url: string): string {
    const { pathname, search } = new URL(url);

    return pathname + search;
  }

  /**
   * The region a swap's new title is announced through.
   *
   * A page load is announced by the browser and a swap is not, so without this a screen reader
   * says nothing when the page changes under it. `polite`, so the title waits for whatever is being
   * read. Out of sight the way text for assistive technology alone is — out of the layout and
   * clipped to nothing, never `display: none` or `hidden`, which would hide it from them too. Styled
   * through the CSSOM, which the CSP's `style-src` does not govern; a `style` attribute would need
   * `'unsafe-inline'`.
   */
  private static liveRegion(): HTMLElement {
    const region = document.createElement(HtmlTag.Div);

    region.setAttribute(HtmlAttribute.AriaLive, 'polite');
    Object.assign(region.style, {
      position: 'absolute',
      width: '1px',
      height: '1px',
      overflow: 'hidden',
      clipPath: 'inset(50%)',
      whiteSpace: 'nowrap',
    });

    return region;
  }

  /**
   * What a response puts on the page — its title, and what goes into #content — or null for a whole
   * document with no #content to take.
   *
   * A fragment is led by its <title>, which is read and then stripped. A whole document — what a
   * static host serves, since it cannot answer X-Requested-With — is parsed, and the two are taken
   * out of it; its title arrives decoded, because the parser has already read the entities.
   */
  private static page(html: string): Page | null {
    if (!Navigation.DOCUMENT.test(html)) {
      const title = html.match(Navigation.TITLE)?.[1];

      return {
        title: title === undefined ? null : Navigation.decodeEntities(title),
        content: html.replace(Navigation.TITLE, ''),
      };
    }

    const parsed  = new DOMParser().parseFromString(html, 'text/html');
    const content = parsed.getElementById(ElementId.Content);

    if (content === null) return null;

    return { title: parsed.title === '' ? null : parsed.title, content: content.innerHTML };
  }

  /**
   * The fragment's <title> is HTML-escaped by ViewResponse, so it has to be decoded before it
   * reaches document.title — otherwise a track called "rock & roll" shows up in the tab as
   * "rock &amp; roll".
   */
  private static decodeEntities(text: string): string {
    const el = document.createElement(HtmlTag.Textarea);
    el.innerHTML = text;

    return el.value;
  }
}
