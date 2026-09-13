# Language

The mechanism is the framework's — `Translatable`, `Translation` and `Translated`, `Phrase`,
`Languages`, `Request::language()`, and the tree putting each word into the nearest `lang`. The
examples are neuro.SYS's: its catalogs under `Texts`, its footer switch and its legal pages are the
site's own, and stand here for any site's.

Every page is written in each language its site offers — neuro.SYS's are English and German — at
the same address. The request decides which, and
every word a page shows is a `Translatable` that is put into that language when the page renders. A
view never names a language. This page is how that works and how to write for it; the legal pages'
own arrangement — both halves, always — is in [architecture.md](https://github.com/neurosysgg/neurosys-webspace/blob/master/docs/architecture.md#language).

## Which language a request gets

`Request::language()` answers, and it is asked once per request:

1. the **`lang` cookie**, where it names a language this site has — a choice made on this site;
2. else **`Accept-Language`**, through `AcceptedLanguages` — a setting made once for every site;
3. else **English**, the site's own language.

A cookie naming anything else (`lang=xx`) is no choice at all and falls through. `ViewResponse` puts
the answer on `<html lang>`, sends it as `Content-Language`, and names `Accept-Language` and `Cookie`
in every page's `Vary` — every page is written in the language those two decide, so a cache that
was not told could hand one visitor another's page. The ETag is the second guard: two languages are
two bodies.

## How a word finds its language

A `Translatable` carries no language. `Node::render()` carries the language down the tree the way
it carries the depth, and an element with a `lang` names it for everything under it:

- on a page that element is `<html lang>`, which `Layout::wrap()` sets from the request;
- a fragment has no `<html>`, so `ViewResponse` passes the language to `render()` directly;
- the German half of a legal document is `<section lang="de">`, and stays German on an English page.

A `lang` that names none of this site's languages leaves the language in scope as it was. **Above
the first `lang` there is no language, and a translatable refuses to render** — a
`TranslationException` naming it, never a default. The default would be an English word on a German
page, with nothing anywhere to say so.

An attribute takes a translatable too — `alt`, `title`, `aria-label`, the meta description — and is
resolved in its own element's language. A terminal's rows cross to the client as JSON, and their
captions are translated, so `TerminalFields` encodes them at render rather than when the terminal is
built.

## Writing words: the catalog

```php
->containing(Texts::Releases::Downloads)                                   // a case is its words
->attr(HtmlAttribute::Alt, Texts::Releases::CoverArt->with(title: $title)) // a phrase with arguments
->containing(Texts::Stats::Total, $count)                                  // words beside data
```

**`Texts` is the index**, one constant per section of the site — `Layout`, `Home`, `Terminal`,
`Releases`, `Demo`, `Stats`, `Errors`, `Profiles`, `Keys` — each naming a catalog enum. PHP resolves
a class constant on a string, so `Texts::Releases::Downloads` *is* `ReleaseText::Downloads`. Its
constants are not upper case, and `phpcs.xml.dist` exempts `Texts.php` and `ReleaseText.php` by name:
they are steps of a path a reader skims, not values to notice.

**A catalog is an enum that `use`s `Translated`**, with both languages on each case:

```php
enum ReleaseText: string implements Translatable
{
    use Translated;

    #[Translation(en: 'downloads', de: 'downloads')]
    case Downloads = 'downloads';

    #[Translation(en: '{title} cover art', de: 'cover von {title}')]
    case CoverArt = 'cover-art';
}
```

- **The backing value is a stable key, never the words.** Two captions may say the same thing, and a
  backed enum's values must be unique. The key is also how GuidelineTest's rules see the file: an
  enum is exempt from the bare-string rule, and attribute arguments are too.
- **English is required; German falls back to it** — but `TranslationTest` fails any catalog case
  without its German, except a release description's.
- **Text is literal until it takes arguments.** `->with(title: …)` binds arguments into a `Phrase`,
  and only then is the text an ICU message: `{title}`, `{count, plural, one {# Download} other {#
  Downloads}}`, and numbers in the language's own style — `1.000` in German, `1,000` in English. That
  is what ext/intl is for.
- **`Verbatim`** is text that is the same in every language — a title, a name. **`Joined`** is
  several texts as one value, for the two places that need one: a `<title>` (`section — neuro.SYS`)
  and an attribute.

A plain-text response is not a tree, so a controller puts its words into the request's language
itself: `Texts::Errors::NotYetAvailable->in($request->language())`.

## Words that belong to one entry

- **A release's description** is a case of `ReleaseDescription`, reached as
  `Texts::Releases::Descriptions::Ill` — backing value the slug — and `data/releases.php` names it:
  `description: Texts::Releases::Descriptions::Ill`. It lives in `src/` so both languages sit side
  by side and ship with a push. German may fall back here: a description is written by whoever
  releases the track, possibly before the German exists. A plain string still works and reads the
  same in both languages, which is what the staging tool writes (`description: ''`).
- **A demo's description is never a catalog case.** `src/` is public, and a case would name an
  unreleased track. It is written inline in the gitignored `data/demos.php`:
  `description: new Translation(en: '…', de: '…')`.
- **A release's key** is translated on `MusicalKey` itself — `Fis-Dur`, `dis-Moll`, `H` for the
  English B — while its backing value stays the English name the tools match on. Genres and formats
  are proper names and stay as they are.

## The switch

The footer names every language in itself — `english · deutsch` — the page's own as text and the
others as links to `/language/{language}`. `LanguageController` answers with a 303, a
`Set-Cookie: lang=de; Path=/; Max-Age=31536000; SameSite=Lax; Secure; HttpOnly`, and
`Cache-Control: no-store, private`. The links carry `data-no-spa`: the header and footer are outside
the fragment Navigation swaps, and they have to come back in the new language too.

**Back is the `Referer`'s path, and only its path.** The host is dropped, so the redirect cannot
leave the site; the path is still put to `Element::staysOnThisOrigin()`, because `//evil.example` is a
path that names another host. No referrer, a refused one, or a switch itself goes home.

The cookie is set only on that click and holds only `de` or `en`. The privacy policy names it in
both languages, as storage strictly necessary for a service the visitor asked for (§ 25 Abs. 2 Nr. 2
TDDDG). See [security.md](https://github.com/neurosysgg/neurosys-webspace/blob/master/docs/security.md#the-language-cookie).

## The client

The client writes a few words of its own — the consent gate, a player's iframe title. It reads the
language off `<html lang>` through `pageLanguage()` in `model/Language.ts`, a mirror of `Language`
compared case for case by `enum-parity.test.mjs`, falling back to English where the page states none
of the site's. Each element keeps its words in a `Record<Language, …>`, so a language the server
gains without its words there is a compile error rather than an English gate on a German page.

## What checks it

- **`TranslationTest`** reads the index, and every catalog a catalog names in turn: every case has a
  `#[Translation]`, both languages parse as ICU messages and name the same arguments, and German is
  written. It also walks `src/` for every enum that uses `Translated` and fails on one the index
  cannot reach, so a catalog cannot be left out of the index and go unchecked. And it reads every
  view's tokens for a word written as a literal — a string with a letter in it, passed straight to
  `containing()` or as an `alt`, `title` or `aria-label` — so a word nobody translated fails here.
- **`HtmlTest`** pins the scope: inheritance, a `lang` narrowing it, a foreign `lang` keeping it, a
  translated attribute, a translated child keeping its element on one line, and the refusal.
- **`TextTest`** pins `Translation`, `Phrase` (plurals, `1.000`), `Verbatim` and `Joined`.
- **The verify script** asks the running server: German to a German browser and to a German cookie,
  on the home page, a release, the 404 and a fragment; `Vary` and `Content-Language` on every page;
  the switch's cookie, its way back, and its refusal of a path that is another host.

## Adding a word

A case on the right catalog, with both languages, and the case at the call site. If the words depend
on something the view knows, name it in the message — `{title}` — and bind it with `->with()`.
`TranslationTest` reports a case missing its German, or naming different arguments in the two.

## Adding a language

1. A `Language` case, and its `endonym()`; the same case in `phpanta/assets/ts/model/Language.ts`.
2. A parameter on `Translation` — `fr:` — and its arm in `pattern()` and `has()`.
3. Its words on every catalog case: `TranslationTest` lists each one missing.
4. Its words in each client `Record`: `npm run check` lists each one missing.
5. `Request::language()`'s `preferred()` call, which names every language on offer.

The legal pages are the exception. They are written in each language rather than translated, and a
third half is a legal decision, not a translation.

## Traps

- **`Translatable` is asked before `BackedEnum`.** A catalog case is both; read as an enum, it would
  render its key — `cover-art` in an alt text. `Element::attr()` orders its arms that way.
- **Render a view with a language.** `$view->content()->render()` throws on the first translated
  word; a test writes `->render(0, Language::English)`.
- **The verify script's "no markup from a string" grep reads comments too.** An apostrophe followed
  on the same line by a `<tag` — "the page's language off `<html lang>`" — fails it. Reword the line.
- **`data/releases.php` naming a new description case needs `./deploy.sh`**, which ships `data/`; a
  push ships only `src/`, where the case lives. Push first, then deploy.
