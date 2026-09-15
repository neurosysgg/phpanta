import { HtmlAttribute } from '../model/HtmlAttribute.js';
import { MachineAttribute } from '../model/MachineAttribute.js';
import { MachineTag } from '../model/MachineTag.js';

/**
 * <machine-filter hidden><input type="search"></machine-filter>
 *
 * A box over a directory the admin's machine service lists: what is typed into it hides every entry
 * whose name does not hold it. The server writes it hidden, since without this module it could do
 * nothing, and this shows it; each entry's row carries its name in `data-entry`, lower-cased, so
 * nothing here reads a cell. A row that is no entry — the way up — is never hidden.
 */
export class MachineFilter extends HTMLElement {
  /** Held as one reference, so the listener that is added is the one that is taken away. */
  private readonly typed = (event: Event): void => { this.filter((event.target as HTMLInputElement).value); };

  connectedCallback(): void {
    this.removeAttribute(HtmlAttribute.Hidden);
    this.addEventListener('input', this.typed);
  }

  disconnectedCallback(): void {
    this.removeEventListener('input', this.typed);
  }

  /** Hides each entry beside this one whose name does not hold `value`, and shows the rest. */
  private filter(value: string): void {
    const wanted = value.trim().toLowerCase();

    (this.parentNode as ParentNode).querySelectorAll(`[${MachineAttribute.Entry}]`).forEach((row) => {
      row.toggleAttribute(HtmlAttribute.Hidden, !(row.getAttribute(MachineAttribute.Entry) as string).includes(wanted));
    });
  }
}

customElements.define(MachineTag.Filter, MachineFilter);
