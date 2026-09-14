// The site's vocabulary: one import per module, each registering the tags it defines.
import './elements/code/CodeBlock.js';
import './elements/code/CodeLine.js';
import './elements/code/CodeToken.js';

import { LanguageChoice } from './phpanta/LanguageChoice.js';
import { Navigation } from './phpanta/Navigation.js';

/**
 * The site's entry point — the only <script> the layout loads.
 *
 * The imports above register the code samples' elements, which guard where they stand; the pages
 * are written whole by the server, so nothing else waits on them.
 *
 * First the language: on GitHub Pages a plain address, `/phpanta/rules`, is the English page, and
 * LanguageChoice sends a visitor who chose German — or whose browser asks for it, once — to
 * `rules.de.html`. Then SPA navigation: each fetch comes back as a whole exported page, and
 * Navigation takes its #content and title out of it, and its header and footer too when it is in
 * the other language; under `npm run site:dev` the server answers with a fragment instead. The
 * pages are the same either way.
 */
LanguageChoice.forDocument()?.start();
Navigation.forDocument()?.start();
