/**
 * Mirrors PasskeyAttribute — what marks a form a passkey answers before it is sent: which ceremony
 * it runs, the challenge the server minted for it, and what it says when nobody answered.
 */
export enum PasskeyAttribute {
  Ceremony  = 'data-passkey',
  Challenge = 'data-challenge',
  Status    = 'data-passkey-status',
}
