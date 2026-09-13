/**
 * Mirrors NeuroSYS\View\Html\ElementId — the id attributes the site assigns.
 *
 * Navigation looks Content up to decide whether to intercept links at all, so a drift here switches
 * the SPA off silently and every page still works.
 */
export enum ElementId {
  Content = 'content',
}
