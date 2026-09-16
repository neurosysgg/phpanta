import { DropField } from '../model/DropField.js';
import { DropTag } from '../model/DropTag.js';
import { HtmlAttribute } from '../model/HtmlAttribute.js';
import { HtmlTag } from '../model/HtmlTag.js';

/**
 * <drop-reveal><p><label>… <input name="token"></label></p></drop-reveal>
 *
 * Around the token's field on the page a drop's link opens. The token rides after the link's `#`,
 * which a browser never sends, so the server cannot write it in: this reads it from the address,
 * fills the field in, hides it, and takes the token out of the address again — so it is not left in
 * the browser's history, or in a copy somebody makes of the address bar. Without this module the
 * field is there to paste the token into; with nothing after the `#`, it is left as the server wrote
 * it — empty, or holding the token of a post refused for its password.
 */
export class DropReveal extends HTMLElement {
  connectedCallback(): void {
    const token = location.hash.slice(1);
    const input = this.querySelector(`${HtmlTag.Input}[${HtmlAttribute.Name}="${DropField.Token}"]`);

    if (token === '' || input === null) return;

    // The attribute, not the property — Passkey.fill()'s reason: a field's value as the form sends it,
    // on whatever element the query found.
    input.setAttribute(HtmlAttribute.Value, token);
    this.toggleAttribute(HtmlAttribute.Hidden, true);
    history.replaceState(history.state, '', location.pathname + location.search);
  }
}

customElements.define(DropTag.Reveal, DropReveal);
