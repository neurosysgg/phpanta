/**
 * The framework's mirrored enums against their PHP originals.
 *
 * assets/ts/model/ states again, in TypeScript, names and values the framework's PHP states first:
 * the tags and attributes the client reads, the headers it sends and reads, the passkey form's
 * fields. A case added, removed, renamed or re-valued on one side is a client that reads nothing,
 * with nothing in any console — so each is compared here, case for case and in order.
 *
 * Read from the TypeScript source rather than from compiled modules, because a checkout of the
 * framework compiles nothing: a site compiles these into its own tree, and its own suite compares
 * that copy too. `npm test` runs this; from a site's root it is
 * `node --test 'phpanta/test/js/*.test.mjs'`. Nothing here needs `npm install`.
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { readFileSync, readdirSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join, resolve } from 'node:path';

const ROOT  = resolve(dirname(fileURLToPath(import.meta.url)), '../..');
const MODEL = join(ROOT, 'assets/ts/model');

/**
 * Runs a PHP snippet against the framework's own autoloader and parses what it echoes as JSON.
 *
 * @param {string} code
 * @returns {any}
 */
function php(code) {
  return JSON.parse(execFileSync('php', ['-r', `require '${ROOT}/autoload.php'; ${code}`], { encoding: 'utf8' }));
}

/**
 * A PHP enum's cases, as [name, value] pairs in declaration order.
 *
 * @param {string} phpEnum
 * @returns {[string, string][]}
 */
const phpCases = (phpEnum) =>
  php(`echo json_encode(array_map(fn ($c) => [$c->name, $c->value], ${phpEnum}::cases()));`);

/**
 * A TypeScript string enum's members, as [name, value] pairs in declaration order, read from its
 * source: every `Name = 'value',` line inside `export enum Name {…}`.
 *
 * @param {string} name
 * @returns {[string, string][]}
 */
function mirrored(name) {
  const source = readFileSync(join(MODEL, `${name}.ts`), 'utf8');
  const body   = new RegExp(`export enum ${name} \\{([\\s\\S]*?)\\n\\}`).exec(source)?.[1];

  assert.ok(body !== undefined, `${name}.ts declares no enum ${name}`);

  return [...body.matchAll(/^\s*(\w+)\s*=\s*'([^']*)',?\s*$/gm)].map(([, member, value]) => [
    /** @type {string} */ (member),
    /** @type {string} */ (value),
  ]);
}

/** Every mirror that repeats a PHP enum whole: its file's name, and the enum it mirrors. */
const MIRRORED = [
  ['CeremonyType', 'Phpanta\\Model\\Passkey\\CeremonyType'],
  ['ElementId', 'Phpanta\\View\\Html\\ElementId'],
  ['HtmlAttribute', 'Phpanta\\View\\Html\\HtmlAttribute'],
  ['HtmlTag', 'Phpanta\\View\\Html\\HtmlTag'],
  ['Language', 'Phpanta\\Text\\Language'],
  ['LinkAttribute', 'Phpanta\\View\\Html\\LinkAttribute'],
  ['LinkRel', 'Phpanta\\View\\Html\\LinkRel'],
  ['PasskeyAttribute', 'Phpanta\\View\\Html\\PasskeyAttribute'],
  ['PasskeyFormField', 'Phpanta\\Http\\PasskeyFormField'],
  ['RegionAttribute', 'Phpanta\\View\\Html\\RegionAttribute'],
  ['RequestedWith', 'Phpanta\\Http\\RequestedWith'],
  ['RequestHeader', 'Phpanta\\Http\\RequestHeader'],
];

for (const [name, phpEnum] of MIRRORED) {
  test(`${name} mirrors ${phpEnum}`, () => {
    assert.deepEqual(mirrored(name), phpCases(phpEnum));
  });
}

/**
 * ResponseHeader, mirrored in part: the client reads few of the headers a response carries, and
 * carrying the rest would be a list nothing uses. So every case the mirror has is a PHP case of the
 * same name and value, and a PHP case the client does not read is no drift.
 */
test('ResponseHeader mirrors the part of Phpanta\\Http\\ResponseHeader the client reads', () => {
  const all  = new Map(phpCases('Phpanta\\Http\\ResponseHeader'));
  const mine = mirrored('ResponseHeader');

  assert.deepEqual(mine, mine.map(([member]) => [member, all.get(member)]));
});

/**
 * MediaType, which has no PHP enum to mirror: MimeType is a class, because it carries a charset.
 * What Navigation compares a response with is the essence of the type a page is sent as.
 */
test('MediaType mirrors the essence of Phpanta\\Http\\MimeType::html()', () => {
  assert.deepEqual(
    mirrored('MediaType'),
    [['Html', php('echo json_encode(Phpanta\\Http\\MimeType::html()->essence());')]],
  );
});

/** A mirror added to assets/ts/model/ without a line here would be compared by nothing. */
test('every mirror in assets/ts/model/ is compared', () => {
  assert.deepEqual(
    readdirSync(MODEL).filter((file) => file.endsWith('.ts')).map((file) => file.slice(0, -3)).sort(),
    [...MIRRORED.map(([name]) => name), 'MediaType', 'ResponseHeader'].sort(),
  );
});
