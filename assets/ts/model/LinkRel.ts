/**
 * Mirrors LinkRel — the link relationships the site writes, in the server's order.
 *
 * Read here for `alternate`, which LanguageChoice finds a page's other languages by. The rest ride
 * along for the reason HtmlAttribute's do: the parity test compares the whole list.
 */
export enum LinkRel {
  Stylesheet    = 'stylesheet',
  ModulePreload = 'modulepreload',
  NoOpener      = 'noopener',
  NoReferrer    = 'noreferrer',
  External      = 'external',
  Alternate     = 'alternate',
  NoFollow      = 'nofollow',
}
