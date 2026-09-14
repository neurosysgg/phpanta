import { CeremonyType } from './model/CeremonyType.js';
import { HtmlAttribute } from './model/HtmlAttribute.js';
import { HtmlTag } from './model/HtmlTag.js';
import { PasskeyAttribute } from './model/PasskeyAttribute.js';
import { PasskeyFormField } from './model/PasskeyFormField.js';

/**
 * A form a passkey answers before it is sent — the admin's unlock, its registration, and each write.
 *
 * The server renders an ordinary form, marked with the ceremony to run and the challenge it minted,
 * and checks everything; this only stands between the button and the post. A submit of such a form is
 * held, the browser's authenticator is asked — `navigator.credentials.get()` to sign the challenge,
 * `create()` to make a key over it — and what it answered is written into the form as base64url, under
 * the names the server reads. Then the form is sent the way it was going to be, by the same button, so
 * a write's "dry run" and "apply" stay two different posts.
 *
 * **Started once, for the whole document, whatever is on it.** A passkey form can arrive after the
 * page loaded — a navigation swaps the admin in without running the entry script again — so this
 * listens to every submit and asks each whether its form is one, rather than looking for forms when
 * it starts. A browser with no credentials to ask sends the form as it is, and the server refuses it.
 *
 * **Nothing is sent that the authenticator did not answer.** A ceremony the visitor cancels, that
 * times out, or that the authenticator refuses — or a challenge that does not decode — leaves the
 * form as it was, and the button can be pressed again; the server would refuse a post without the
 * answer anyway, and this spares the trip. The form says so, in the words the server wrote into it
 * for that, since this module has none of its own. While the authenticator is asked the form's
 * buttons are disabled, and a second submit meanwhile starts no second ceremony.
 *
 * **ES256 only, a key kept on the device, and the person verified** — what the server accepts, and
 * nothing it would then have to refuse. The relying party is left to the browser, which takes the
 * page's own host: the one the server checks the answer against. No WebCrypto, and no request of its
 * own, so the page's `default-src 'self'` needs nothing added.
 */
export class Passkey {
  /** A form a passkey answers. */
  private static readonly FORM = `${HtmlTag.Form}[${PasskeyAttribute.Ceremony}]`;

  /** COSE's number for ECDSA over P-256 with SHA-256 — the one algorithm the server verifies. */
  private static readonly ES256 = -7;

  /** How many random bytes a registration's user handle is; the server never reads it. */
  private static readonly HANDLE = 16;

  /** What a registered key is called in the device's own list of passkeys, beside the host. */
  private static readonly ACCOUNT = 'admin';

  /** Forms whose ceremony has been answered, so the one submit that sends them goes through. */
  private readonly answered = new WeakSet<Element>();

  /** Forms whose authenticator is being asked, so a second submit meanwhile asks nothing. */
  private readonly asking = new WeakSet<Element>();

  private constructor() {}

  /**
   * Holds each passkey form's submit — on this page, or on one a navigation brings in — until the
   * authenticator has answered it. Called once per document, by the entry script.
   */
  public static start(): void {
    const passkey = new Passkey();

    document.addEventListener('submit', (e) => { void passkey.onSubmit(e); });
  }

  /**
   * A submit: let through where it is no passkey form's, where the browser has nothing to ask, or
   * where its answer is already written — held and asked for otherwise.
   *
   * The hold is the first thing done, before anything is awaited, because a listener can only stop a
   * submit while the event is still being dispatched.
   */
  private async onSubmit(e: SubmitEvent): Promise<void> {
    const form        = e.target instanceof Element ? e.target.closest<HTMLFormElement>(Passkey.FORM) : null;
    const credentials = navigator.credentials as CredentialsContainer | undefined;

    if (form === null || credentials === undefined || this.answered.delete(form)) return;

    e.preventDefault();

    if (this.asking.has(form)) return;

    this.asking.add(form);
    Passkey.waiting(form, true);

    const answer = await Passkey.ceremony(form, credentials);

    this.asking.delete(form);
    Passkey.waiting(form, false);
    Passkey.unanswered(form, answer === null);

    if (answer === null) return;

    answer.forEach((value, field) => { Passkey.fill(form, field, value); });
    this.send(form, e.submitter);
  }

  /** Disables the form's buttons while its authenticator is asked, and enables them again after. */
  private static waiting(form: Element, waiting: boolean): void {
    form.querySelectorAll(HtmlTag.Button).forEach((button) => {
      button.toggleAttribute(HtmlAttribute.Disabled, waiting);
    });
  }

  /** Shows, or hides again, what the server wrote into the form to say nobody answered. */
  private static unanswered(form: Element, show: boolean): void {
    form.querySelector(`[${PasskeyAttribute.Status}]`)?.toggleAttribute(HtmlAttribute.Hidden, !show);
  }

  /**
   * Sends `form` by `submitter`, marked as answered so the submit this raises goes through.
   *
   * A method of its own rather than inline, because the compiled module is type-checked again as
   * JavaScript by the site that tests it, where the form type the query above names is gone and only
   * a parameter is left untyped enough to call `requestSubmit()` on.
   */
  private send(form: HTMLFormElement, submitter: HTMLElement | null): void {
    this.answered.add(form);
    form.requestSubmit(submitter);
  }

  /** What the authenticator answered the form's ceremony, or null where it did not. */
  private static async ceremony(
    form: Element,
    credentials: CredentialsContainer,
  ): Promise<ReadonlyMap<PasskeyFormField, string> | null> {
    try {
      const challenge = Passkey.bytes(form.getAttribute(PasskeyAttribute.Challenge) ?? '');

      return form.getAttribute(PasskeyAttribute.Ceremony) === CeremonyType.Create
        ? await Passkey.create(credentials, challenge)
        : await Passkey.get(credentials, challenge);
    } catch {
      // Cancelled, timed out or refused — or a challenge that is not base64url, which atob() throws
      // on: the form stays as it was, to be tried again.
      return null;
    }
  }

  /** An enrolled key's signature over the challenge. */
  private static async get(
    credentials: CredentialsContainer,
    challenge: Uint8Array<ArrayBuffer>,
  ): Promise<ReadonlyMap<PasskeyFormField, string> | null> {
    const credential = await credentials.get({
      publicKey: { challenge, userVerification: 'required' },
    }) as PublicKeyCredential | null;

    if (credential === null) return null;

    const response = credential.response as AuthenticatorAssertionResponse;

    return new Map([
      [PasskeyFormField.Credential, credential.id],
      [PasskeyFormField.ClientData, Passkey.text(response.clientDataJSON)],
      [PasskeyFormField.AuthenticatorData, Passkey.text(response.authenticatorData)],
      [PasskeyFormField.Signature, Passkey.text(response.signature)],
    ]);
  }

  /** A new key, made over the challenge, and its public half as the server reads it. */
  private static async create(
    credentials: CredentialsContainer,
    challenge: Uint8Array<ArrayBuffer>,
  ): Promise<ReadonlyMap<PasskeyFormField, string> | null> {
    const credential = await credentials.create({
      publicKey: {
        challenge,
        rp: { name: location.hostname },
        user: {
          id: crypto.getRandomValues(new Uint8Array(Passkey.HANDLE)),
          name: Passkey.ACCOUNT,
          displayName: Passkey.ACCOUNT,
        },
        pubKeyCredParams: [{ type: 'public-key', alg: Passkey.ES256 }],
        authenticatorSelection: { residentKey: 'required', userVerification: 'required' },
      },
    }) as PublicKeyCredential | null;

    const response = credential?.response as AuthenticatorAttestationResponse | undefined;
    const key      = response?.getPublicKey() ?? null;

    if (credential === null || response === undefined || key === null) return null;

    return new Map([
      [PasskeyFormField.Credential, credential.id],
      [PasskeyFormField.ClientData, Passkey.text(response.clientDataJSON)],
      [PasskeyFormField.AuthenticatorData, Passkey.text(response.getAuthenticatorData())],
      [PasskeyFormField.Key, Passkey.text(key)],
    ]);
  }

  /** Writes `value` into the form's hidden field `field`, adding the field where it has none. */
  private static fill(form: Element, field: PasskeyFormField, value: string): void {
    let input = form.querySelector(`${HtmlTag.Input}[${HtmlAttribute.Name}="${field}"]`);

    if (input === null) {
      input = document.createElement(HtmlTag.Input);
      input.setAttribute(HtmlAttribute.Type, 'hidden');
      input.setAttribute(HtmlAttribute.Name, field);
      form.append(input);
    }

    // The attribute, not the property: a hidden field's value is its attribute, and setting it works
    // on any element the query found without first asking what kind it is.
    input.setAttribute(HtmlAttribute.Value, value);
  }

  /** The bytes a base64url string stands for. */
  private static bytes(base64url: string): Uint8Array<ArrayBuffer> {
    const base64 = base64url.replace(/-/g, '+').replace(/_/g, '/');
    const padded = base64.padEnd(Math.ceil(base64.length / 4) * 4, '=');

    return Uint8Array.from(atob(padded), (character) => character.charCodeAt(0));
  }

  /** `buffer` as base64url, with no padding — how the server reads every field. */
  private static text(buffer: ArrayBuffer): string {
    let binary = '';

    for (const byte of new Uint8Array(buffer)) binary += String.fromCharCode(byte);

    return btoa(binary).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
  }
}
