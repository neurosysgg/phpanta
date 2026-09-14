/**
 * Mirrors PasskeyAttribute — what marks a form a passkey answers before it is sent: which ceremony
 * it runs, and the challenge the server minted for it.
 */
export enum PasskeyAttribute {
  Ceremony  = 'data-passkey',
  Challenge = 'data-challenge',
}
