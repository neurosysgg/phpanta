import { CodeTag } from '../../model/CodeTag.js';
import { NestedElement } from '../../phpanta/elements/NestedElement.js';
import { CodeBlock } from './CodeBlock.js';

/** <code-line> — one line of a sample, inside its <code-block>. */
export class CodeLine extends NestedElement {
  protected parent(): CustomElementConstructor { return CodeBlock; }
}

customElements.define(CodeTag.Line, CodeLine);
