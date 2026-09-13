/**
 * Mirrors NeuroSYS\Http\RequestHeader — the request headers the server reads.
 */
export enum RequestHeader {
  RequestedWith = 'X-Requested-With',

  /**
   * Written by no client code — the browser sends it on its own, from the ETag the server gave it.
   * Named here only so this mirror stays comparable case for case, the way EmbedAttribute.Loaded
   * has a PHP case it is never emitted from.
   */
  IfNoneMatch = 'If-None-Match',

  /**
   * Written by no client code either — a browser sends it when an <audio> element is seeked, and
   * FileResponse answers it with a 206. Here for the same reason IfNoneMatch is: the mirror is
   * compared case for case, so a case on one side only is what fails.
   */
  Range = 'Range',

  /**
   * Written by no client code either — the browser sends it from the visitor's own language
   * settings, and the imprint and the privacy policy order their two halves by it. Here for the
   * same reason IfNoneMatch and Range are: the mirror is compared case for case.
   */
  AcceptLanguage = 'Accept-Language',

  /**
   * Written by no client code either — the browser sends it on its own, and the server reads one
   * cookie out of it, the visitor's chosen language, which outranks Accept-Language. Here for the
   * same reason the three above are: the mirror is compared case for case.
   */
  Cookie = 'Cookie',

  /**
   * Written by no client code either — the browser sends it on its own, and the server takes its
   * path to send a visitor back after a language switch. Here for the same reason the others are.
   */
  Referer = 'Referer',
}
