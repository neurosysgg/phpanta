/**
 * Mirrors the part of ResponseHeader the client reads — one header, so far.
 *
 * Unlike RequestHeader this is not the whole enum. RequestHeader's mirror carries the headers no
 * client code writes so that the two compare case for case; nearly all of the fourteen a response
 * sends are read by the browser alone, and carrying them here would be a list nothing uses. The
 * parity test checks instead that every case here is a PHP case with the same value.
 */
export enum ResponseHeader {
  ContentType = 'Content-Type',
}
