import { CodeTag } from '../../model/CodeTag.js';

/**
 * <code-block language="php"> — one code sample: <code-line>s, and in them the tokens a reader looks
 * for, each an element named for what it is.
 *
 * The server writes the whole tree (PhpantaSite\SampleLanguage), highlighted, so the page reads the
 * same with no script at all. What this side adds is what registering buys: the tags are known to
 * the browser, and each of the others says where it belongs — a <code-line> inside a <code-block>, a
 * token inside a <code-line> — and throws where it does not, rather than rendering as an inert box
 * nothing styles.
 */
export class CodeBlock extends HTMLElement {}

customElements.define(CodeTag.Block, CodeBlock);
