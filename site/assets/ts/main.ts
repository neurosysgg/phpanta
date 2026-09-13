import { Navigation } from './phpanta/Navigation.js';

/**
 * The site's entry point — the only <script> the layout loads.
 *
 * All it does is start SPA navigation. On GitHub Pages each fetch comes back as a whole exported
 * page, and Navigation takes its #content and title out of it; under `npm run site:dev` the server
 * answers with a fragment instead. The pages are the same either way.
 */
Navigation.forDocument()?.start();
