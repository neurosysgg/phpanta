import { CodeTag } from '../../model/CodeTag.js';
import { NestedElement } from '../../phpanta/elements/NestedElement.js';
import { CodeLine } from './CodeLine.js';

/**
 * A token — <code-keyword>, <code-string>, <code-comment> and the rest — inside its <code-line>.
 *
 * The tokens differ in nothing but their names, which is what the stylesheet reads, so each is
 * registered here as a class of its own (a constructor is registered once) rather than a file of
 * its own: every tag the mirror names that is not the block or a line.
 */
export abstract class CodeToken extends NestedElement {
  protected parent(): CustomElementConstructor { return CodeLine; }
}

const STRUCTURE: readonly CodeTag[] = [CodeTag.Block, CodeTag.Line];

for (const tag of Object.values(CodeTag)) {
  if (!STRUCTURE.includes(tag)) customElements.define(tag, class extends CodeToken {});
}
