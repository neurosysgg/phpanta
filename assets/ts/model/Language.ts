/**
 * Mirrors NeuroSYS\Text\Language — the languages the site is written in.
 *
 * The server decides which one a page is in and states it on <html lang>; the client reads it there
 * rather than deciding again, so words an element writes for itself are in the page's language too.
 */
export enum Language {
  English = 'en',
  German = 'de',
}

/**
 * The page's language, as <html lang> states it — English where it states none of this site's.
 *
 * English for the reason it is the server's fallback: it is the site's own language. A page the
 * server sent always states one of its own; the fallback is for a document that did not come from
 * it, which in practice is a test's.
 */
export function pageLanguage(): Language {
  const stated = document.documentElement.lang;

  return (Object.values(Language) as string[]).includes(stated) ? (stated as Language) : Language.English;
}
