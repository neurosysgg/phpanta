/**
 * Mirrors NeuroSYS\View\Html\HtmlAttribute — the standard attributes this site emits.
 *
 * Read here for the link selector Navigation intercepts on, which has to name the same href the
 * server writes. Most cases are server-side only; the parity test is what makes carrying the whole
 * list cheaper than carrying half of one.
 */
export enum HtmlAttribute {
  ClassName = 'class',
  Id        = 'id',
  Lang      = 'lang',
  Title     = 'title',
  Href      = 'href',
  Src       = 'src',
  Rel       = 'rel',
  Target    = 'target',
  Type      = 'type',
  Alt       = 'alt',
  Height    = 'height',
  Width     = 'width',
  Charset   = 'charset',
  Name      = 'name',
  Content   = 'content',
  Controls  = 'controls',
  Preload   = 'preload',
  AriaLabel = 'aria-label',
}
