/**
 * Mirrors HtmlTag — the standard elements a page built on the framework emits.
 *
 * Most are the server's alone. The framework's own client creates two: Navigation a <div> for its
 * live region and a <textarea> to decode entities out of a title. A site's elements may create more.
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

  /** Native media. Server-side only — it is native precisely so no client code is needed. */
  Audio    = 'audio',
  Video    = 'video',

  Iframe   = 'iframe',

  /** What an element that draws, draws on. Client-created only: a view emits the element, which makes this. */
  Canvas   = 'canvas',

  /** Written by a view where text is shown for copying, and made by Navigation to decode entities. */
  Textarea = 'textarea',
  Pre      = 'pre',

  /** Written by the server; the machine's live readings set its value. */
  Meter    = 'meter',
  Table    = 'table',
  Tr       = 'tr',
  Th       = 'th',
  Td       = 'td',
  Tbody    = 'tbody',

  /** The form and its controls are the server's Form's to write; nothing client-side creates one. */
  Form     = 'form',
  Input    = 'input',
  Label    = 'label',
  Select   = 'select',
  Option   = 'option',
  Fieldset = 'fieldset',
  Legend   = 'legend',
}
