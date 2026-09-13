/**
 * Mirrors NeuroSYS\View\Html\HtmlTag — the standard elements this site emits.
 *
 * The client creates most of these: the gate builds a <p>, a <button> and a <small>, the player an
 * <iframe> and a <div>, and Navigation a <textarea> to decode entities out of a title.
 */
export enum HtmlTag {
  Html     = 'html',
  Head     = 'head',
  Meta     = 'meta',
  Link     = 'link',
  Title    = 'title',
  Script   = 'script',
  Body     = 'body',
  Header   = 'header',
  Nav      = 'nav',
  Main     = 'main',
  Footer   = 'footer',
  Section  = 'section',
  H1       = 'h1',
  H2       = 'h2',
  H3       = 'h3',

  /** h4, ul, li and em are the privacy policy's; nothing client-side creates one. */
  H4       = 'h4',

  P        = 'p',
  Ul       = 'ul',
  Li       = 'li',
  Br       = 'br',
  A        = 'a',
  Img      = 'img',
  Button   = 'button',
  Span     = 'span',
  Small    = 'small',
  Strong   = 'strong',
  Em       = 'em',
  Div      = 'div',

  /** The demo page's player. Server-side only — it is native precisely so no client code is needed. */
  Audio    = 'audio',

  Iframe   = 'iframe',

  /** What <demo-waveform> draws on. Client-created only, the way Textarea is. */
  Canvas   = 'canvas',

  Textarea = 'textarea',
  Table    = 'table',
  Tr       = 'tr',
  Td       = 'td',
}
