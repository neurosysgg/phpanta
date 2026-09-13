/**
 * Mirrors MimeType's essences — what a response says it is, before any parameter.
 *
 * Navigation reads Html to decide whether a response is a page it can swap in; anything else goes
 * to the browser. PHP has no enum to compare this with — MimeType is a class, because it carries a
 * charset a case could not — so the parity test asks MimeType::html() for its essence() instead.
 */
export enum MediaType {
  Html = 'text/html',
}
