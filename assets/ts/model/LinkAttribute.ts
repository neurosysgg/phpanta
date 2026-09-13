/**
 * Mirrors NeuroSYS\View\Html\LinkAttribute — the attributes that change how an <a> behaves.
 *
 * One so far, and it is load-bearing: without `data-no-spa` Navigation fetches a download link,
 * gets the 303 and swallows it, and downloads stop working while every page still looks fine.
 */
export enum LinkAttribute {
  NoSpa = 'data-no-spa',
}
