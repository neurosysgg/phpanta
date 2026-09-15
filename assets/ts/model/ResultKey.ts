/**
 * Mirrors the part of ResultKey the client reads — where an admin answer keeps its sections, and
 * where the machine service's section keeps its counters.
 *
 * Partial, like ResponseHeader: the parity test checks that every case here is a PHP case with the
 * same value.
 */
export enum ResultKey {
  Sections = 'sections',
  Counters = 'counters',
}
