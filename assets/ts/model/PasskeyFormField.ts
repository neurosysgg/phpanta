/**
 * Mirrors PasskeyFormField — the fields a passkey form sends back. The server writes the ceremony;
 * the client fills in what the authenticator answered, each as base64url.
 */
export enum PasskeyFormField {
  Ceremony          = 'ceremony',
  Credential        = 'credential',
  ClientData        = 'client-data',
  AuthenticatorData = 'authenticator-data',
  Signature         = 'signature',
  Key               = 'key',
}
