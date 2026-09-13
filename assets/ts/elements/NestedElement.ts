/**
 * Base for a tag that only means anything inside a particular element.
 *
 * <terminal-key> outside a <terminal-field> is not a smaller mistake than a misspelled tag — it is
 * the same mistake, and it fails the same silent way: an inert inline box, styled by a selector that
 * no longer matches, with nothing in the console. So each of these says what it belongs inside, and
 * says so where it is defined rather than in a comment.
 *
 * The check is "somewhere inside", not "directly under", because a card's tags sit inside the
 * anchor that has to stay a real link — <download-card> wraps <a> wraps <download-label>.
 */
export abstract class NestedElement extends HTMLElement {
  /** The element this one has to sit inside. */
  protected abstract parent(): CustomElementConstructor;

  connectedCallback(): void {
    const expected = this.parent();

    // Typed as Element rather than HTMLElement on purpose: narrowing the negative branch of an
    // `instanceof HTMLElement` leaves `never`, and the walk stops compiling.
    let node: Element | null = this.parentElement;

    while (node !== null) {
      if (node instanceof expected) return;

      node = node.parentElement;
    }

    throw new Error(
      `<${this.localName}> must be inside <${NestedElement.tagOf(expected)}>, but is not.`,
    );
  }

  /** The registered tag for a constructor, for the message. getName() is recent; fall back to it. */
  private static tagOf(constructor: CustomElementConstructor): string {
    return customElements.getName?.(constructor) ?? constructor.name;
  }
}
