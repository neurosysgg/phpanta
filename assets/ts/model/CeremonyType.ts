/**
 * Mirrors CeremonyType — the two WebAuthn ceremonies, as the client data names them and as a form's
 * data-passkey says which one to run.
 */
export enum CeremonyType {
  Create = 'webauthn.create',
  Get    = 'webauthn.get',
}
