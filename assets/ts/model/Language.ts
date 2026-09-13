/**
 * Mirrors Language — every language the framework knows, of which a site offers some.
 *
 * The server decides which one a page is in and states it on <html lang>; the client reads it there
 * rather than deciding again, so words an element writes for itself are in the page's language too.
 */
export enum Language {
  English = 'en',
  German = 'de',
  French = 'fr',
  Spanish = 'es',
  Italian = 'it',
  Dutch = 'nl',
}

/**
 * The page's language among `offered`, as <html lang> states it — the first of them where it states
 * none of these.
 *
 * `offered` is the site's own list, its default first, as its `languages()` gives it on the server.
 * Narrowing to it is what lets an element keep its words in a Record over the languages the site
 * offers rather than every language the framework knows: the answer is always a key the Record
 * has, and a language the site gains without its words there is a compile error. The fallback is
 * the site's default for the reason it is on the server; a page the server sent always states one
 * of its own, so it is for a document that did not come from it, which in practice is a test's.
 */
export function pageLanguage<L extends Language>(offered: readonly [L, ...L[]]): L {
  const stated = document.documentElement.lang;

  return offered.find((language) => language === stated) ?? offered[0];
}
