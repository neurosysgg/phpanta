/**
 * Mirrors HtmlAttribute — the standard attributes a page built on the framework emits.
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
  HrefLang  = 'hreflang',
  Src       = 'src',
  Rel       = 'rel',
  Target    = 'target',
  Type      = 'type',
  Download  = 'download',
  Alt       = 'alt',
  Height    = 'height',
  Width     = 'width',
  Charset   = 'charset',
  Name      = 'name',
  Content   = 'content',
  Controls  = 'controls',
  Preload   = 'preload',
  AriaLabel = 'aria-label',
  AriaLive  = 'aria-live',

  /** The form's attributes. Server-side only, like most of this list. */
  AriaDescribedBy = 'aria-describedby',
  Action          = 'action',
  Method          = 'method',
  Enctype         = 'enctype',
  Value           = 'value',
  For             = 'for',
  Required        = 'required',
  MaxLength       = 'maxlength',
  Autocomplete    = 'autocomplete',
  Checked         = 'checked',
  Selected        = 'selected',
  Readonly        = 'readonly',
  Disabled        = 'disabled',
  Hidden          = 'hidden',
}
