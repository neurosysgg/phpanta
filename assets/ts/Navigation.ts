import { ElementId } from './model/ElementId.js';
import { HtmlAttribute } from './model/HtmlAttribute.js';
import { HtmlTag } from './model/HtmlTag.js';
import { LinkAttribute } from './model/LinkAttribute.js';
import { RequestHeader } from './model/RequestHeader.js';
import { RequestedWith } from './model/RequestedWith.js';

/**
 * SPA navigation: intercept internal link clicks, fetch the page as a content fragment, and swap it
 * into #content. Every link is a real href, so direct loads and no-JS behave identically.
 *
 * Only the most recent navigation may write to the page — see the counter below. Everything else
 * here is stateless, which is why that one field is worth reading before changing anything.
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

  /** Starts intercepting link clicks and back/forward. */
  public start(): void {
    document.addEventListener('click', (e) => { this.onClick(e); });
    // pathname only, so a query string or fragment on the entry being returned to is dropped.
    // Nothing this site emits has either — Element refuses a URL that is not a path of ours, and
    // no view writes a `?` or a `#` — so today there is nothing to lose. The day one appears, this
    // wants `location.pathname + location.search + location.hash`, and onClick already does the
    // equivalent by handing go() the whole resolved href.
    window.addEventListener('popstate', () => { void this.go(location.pathname); });
  }

  private onClick(e: MouseEvent): void {
    // Let the browser handle open-in-new-tab/window and non-primary buttons.
    if (e.metaKey || e.ctrlKey || e.shiftKey || e.altKey || 0 !== e.button) return;

    // A click can land on the document itself, and EventTarget has no closest().
    if (!(e.target instanceof Element)) return;

    // The selector is what makes the anchor type true.
    const link = e.target.closest<HTMLAnchorElement>(Navigation.INTERNAL_LINK);

    if (null === link || link.hasAttribute(LinkAttribute.NoSpa)) return;

    // The selector matches the href *attribute*, and `//evil.example/x` starts with a slash exactly
    // as `/releases` does — a protocol-relative URL is a different origin wearing a path's clothes.
    // Everything below uses the *resolved* `link.href`, so the two readings have to be reconciled
    // here rather than assumed equal. Nothing the server emits is protocol-relative, and pushState
    // would throw a SecurityError on a cross-origin URL one line down, so today the consequence is
    // a link that does nothing rather than a hole — but "it throws slightly later" is not a reason,
    // and go() ends in innerHTML. Handing it back to the browser is both safe and correct.
    if (new URL(link.href).origin !== location.origin) return;

    e.preventDefault();
    history.pushState({}, '', link.href);
    void this.go(link.href);
  }

  /**
   * Fetches `url` as a content fragment and swaps it into #content.
   *
   * The assignment at the end is `innerHTML`, and that is the one assumption this file rests on:
   * the fragment is same-origin (onClick refuses anything else, and popstate can only reach a URL
   * the browser already navigated to) and it is built by the server's markup tree, where every
   * value is escaped by Text and every URL attribute is scheme-checked. So the
   * string being parsed here is one this codebase generated, not one it received.
   *
   * Worth stating because it is inherited rather than enforced: the guarantee lives on the server,
   * and this line is where it is spent. Anything that ever puts markup into #content from another
   * source — a different endpoint, a third party, a value not rendered through the tree — reopens
   * DOM XSS here, and nothing in this file would notice.
   */
  private async go(url: string): Promise<void> {
    this.inFlight?.abort();

    const controller = new AbortController();
    const navigation = ++this.navigation;

    this.inFlight = controller;

    try {
      const response = await fetch(url, {
        credentials: 'same-origin',
        headers: { [RequestHeader.RequestedWith]: RequestedWith.XmlHttpRequest },
        signal: controller.signal
      });

      if (navigation !== this.navigation) return;

      if (!response.ok) {
        location.assign(url);
        return;
      }

      const html = await response.text();

      // Checked again after the second await: the body can arrive after a newer click has already
      // started, and by then this response is for a page the visitor has moved on from.
      if (navigation !== this.navigation) return;

      const title = html.match(Navigation.TITLE)?.[1];

      if (title !== undefined) document.title = Navigation.decodeEntities(title);
      else console.warn('No title found in HTML response');

      this.content.innerHTML = html.replace(Navigation.TITLE, '');
      document.dispatchEvent(new Event(Navigation.EVENT));
      window.scrollTo(0, 0);
    } catch {
      // An abort is this file cancelling itself, not a failure — the navigation that replaced this
      // one is already in flight, and handing the browser a URL the visitor has left would undo it.
      if (navigation !== this.navigation) return;

      // pushState already ran, so the URL points at a page the visitor never got.
      // Hand the navigation back to the browser rather than strand them there.
      location.assign(url);
    }
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
