/**
 * Mirrors NeuroSYS\Http\RequestedWith — the value that asks for a fragment rather than a page.
 *
 * Drift and the server answers a SPA fetch with a whole document, which Navigation then writes into
 * <main>. Nothing reports that; the parity test is the only thing standing between us and it.
 */
export enum RequestedWith {
  XmlHttpRequest = 'XMLHttpRequest',
}
