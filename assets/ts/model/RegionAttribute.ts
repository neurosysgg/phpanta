/**
 * Mirrors RegionAttribute — the attributes that mark a part of the shell for the client.
 *
 * One so far: `data-language-bound`, which Navigation reads to know what a navigation into another
 * language replaces besides #content.
 */
export enum RegionAttribute {
  LanguageBound = 'data-language-bound',
}
