# Language

Every page is written in each language its app offers, at the same address. The request decides
which. Every word a page shows is a `Translatable`, put into that language when the page renders. A
view never names a language.

This page covers the mechanism and how to write for it:

- `Translatable`, `Translation` and `Translated`;
- `Phrase`, `Verbatim` and `Joined`;
- `Languages` and `Request::language()`;
- the tree putting each word into the nearest `lang`.

The examples are `TestApp`'s, or made-up catalogs. A site's own catalogs and its switch are its own,
and are documented with it.

## Which language a request gets

An app offers its languages through `languages()`, a `Languages` whose first language is the
default. `TestApp` offers `new Languages(Language::English, Language::German)`. Each language is
offered once, and offering one twice is refused. `Request::language()` answers, and it is asked once
per request:

1. the **`lang` cookie** (`CookieName::Language`), where it names a language the app offers. That
   is a choice made on this site;
2. else **`Accept-Language`**, through `AcceptedLanguages`, among the languages offered. That is a
   setting made once for every site;
3. else the app's **default**, on a tie or when the header names none of them.

A cookie naming anything else is no choice at all, and falls through. That covers `lang=xx`, and
equally `lang=fr` on an app that does not write French, even though the framework knows the language
exists. `Languages::tryFrom()` answers only for what is offered.

`ViewResponse` puts the answer on the page in three places:

- on `<html lang>`;
- in `Content-Language`;
- in every page's `Vary`, which names `Accept-Language` and `Cookie`.

Every page is written in the language those two headers decide, so a cache that was not told could
hand one visitor another visitor's page. The ETag is the second guard, because two languages are two
bodies.

## How a word finds its language

A `Translatable` carries no language. `Node::render()` carries the language down the tree the way it
carries the depth, and an element with a `lang` names the language for everything under it:

- on a page, that element is `<html lang>`, which the app's `Shell` sets from the language it is
  handed. `TestApp::document()` is the smallest example;
- a fragment has no `<html>`, so `ViewResponse` passes the language to `render()` directly;
- an element with a `lang` of its own keeps it. A `<section lang="de">` holding a document written in
  German stays German on an English page.

A `lang` that names none of the app's languages leaves the language in scope as it was. **Above the
first `lang` there is no language, and a translatable refuses to render.** It throws a
`TranslationException` naming itself, never a default. A default would be an English word on a
German page, with nothing anywhere to say so.

An attribute takes a translatable too (`alt`, `title`, `aria-label`, the meta description), and it
is resolved in its own element's language. Some values have to be put into a language as a whole,
such as JSON crossing to the client in an attribute with translated captions inside. Such a value
implements `Translatable` itself, so it is encoded at render in its element's language, rather than
when the element is built.

## Writing words: the catalog

```php
->containing(PostText::ReadMore)                                   // a case is its words
->attr(HtmlAttribute::Alt, PostText::CoverArt->with(title: $title)) // a phrase with arguments
```

**A catalog is an enum that `use`s `Translated`**, with both languages on each case:

```php
enum PostText: string implements Translatable
{
    use Translated;

    #[Translation(en: 'read more', de: 'weiterlesen')]
    case ReadMore = 'read-more';

    #[Translation(en: '{title} cover art', de: 'Cover von {title}')]
    case CoverArt = 'cover-art';
}
```

- **The backing value is a stable key, never the words.** Two captions may say the same thing, and a
  backed enum's values must be unique. The key is also how the guideline rules see the file: an
  enum is exempt from the bare-string rule, and so are attribute arguments. See
  [guidelines.md](guidelines.md).
- **Any one language is enough, and none may be blank.** `Translation` takes `en:`, `de:`, `fr:`,
  `es:`, `it:` and `nl:`, each optional. A language with no text falls back along the app's
  `Languages` in order, the default first, and then to the first text written. A blank text is
  refused: a language with nothing to say is left out, so that it falls back. A site's suite should
  still hold every catalog case to every language it offers, because a fallback is a word in the
  wrong language on the page.
- **Text is literal until it takes arguments.** `->with(title: …)` binds arguments into a `Phrase`,
  and only then is the text an ICU message: `{title}`, `{count, plural, one {# comment} other {#
  comments}}`, and numbers in the language's own style (`1.000` in German, `1,000` in English). That
  is what `ext/intl` is for.
- **`Verbatim`** is text that is the same in every language, such as a title or a name. **`Joined`**
  is several texts as one value, for the two places that need one: a `<title>` (`a page — a site`)
  and an attribute.

A site may keep an index: one class whose constants each name a catalog enum. PHP resolves a class
constant fetch on a string, so `Words::Posts::ReadMore` *is* `PostText::ReadMore`. That arrangement
is the site's, not the framework's. The framework asks only that a word be a `Translatable`.

A plain-text response is not a tree, so a controller puts its words into the request's language
itself: `PostText::ReadMore->in($request->language())`. The framework's own few words sit in
`FrameworkText` and are written the same way.

## Words that belong to one entry

A catalog case is for words the code writes. Words that belong to one entry of a data file, such as
a post's summary, belong to the entry, and they can take one of two forms:

- **The entry can name a catalog case.** Both languages then sit side by side in `src/` and ship with
  the code.
- **The entry can construct a `Translation` inline**, as
  `summary: new Translation(en: '…', de: '…')`. That is the same class the attribute is.

**Words that must not be public never become a catalog case.** `src/` is code, a repository is often
public, and a case would name what the entry is about. Write those words inline, in a data file the
repository does not track.

An enum whose backing value is a name the tooling matches on can be translated on itself. It
`use`s `Translated` and carries a `#[Translation]` on each case, while the backing value stays the
key. A proper name that reads the same everywhere stays a plain value, or becomes a `Verbatim`.

## The switch

The framework reads the cookie, and setting it is a site's job. A switch is a link per offered
language, each named in that language itself (`Language::endonym()`). Each link points to an address
whose controller answers with a 303, a `Set-Cookie: lang=…`, and `Cache-Control: no-store, private`.
Any switch owes three things:

- **Its links carry `data-no-spa` wherever the switch sits outside `#content`.** Navigation swaps
  only the fragment, and everything around the fragment has to come back in the new language too.
- **Back is the `Referer`'s path, and only its path.** Drop the host, so the redirect cannot leave the
  site, and still put the path to `Element::staysOnThisOrigin()`, because `//evil.example` is a path
  that names another host. No referrer, a refused one, or the switch's own address goes home.
- **The cookie holds only an offered language's tag, and is set only on the click.** Whether it
  needs consent is the site's question for its privacy policy, answered in every language the site
  offers.

## The client

The client writes a few words of its own, such as a consent notice or an iframe title. It reads the
language off `<html lang>` through `pageLanguage(offered)` in
[`model/Language.ts`](../assets/ts/model/Language.ts), a mirror of `Language`. `offered` is the
site's own list, its default first, stated once on the client beside the other facts it shares with
the server, and checked against `languages()`. The answer is always one of `offered`, and the
fallback is its first, the site's default. A page the server sent always states a language, so the
fallback is only for a document that did not come from it, which in practice is a test's document.

Each element keeps its words in a `Record` over the languages the site offers, not over every
`Language`. A language the site gains without its words there is then a compile error, rather than
an English gate on a German page. A language the framework gains costs the element nothing.

## What checks it

- **`TextTest`**, in the framework's suite, pins these behaviours:
  - `Translation` falls back along the app's languages, then to the first text written, and
    refuses a translation with no text or a blank one;
  - every framework catalog case is written in every language `TestApp` offers;
  - unbound text stays literal;
  - a catalog case with no `#[Translation]` is loud;
  - `Phrase` formats plurals and numbers by the language's rules (`1.000`), and an ICU message it
    cannot format is loud;
  - `Verbatim` is the same in every language, and `Joined` puts each part into the language first.
- **A site's own suite holds its catalogs**, and this is the check worth writing first:
  - every case has a `#[Translation]`;
  - each offered language parses as an ICU message and names the same arguments as the default;
  - each offered language is written, asking `has()` of `languages()->offered()` rather than of
    `Language::cases()`, which lists languages the site never writes;
  - every enum under `src/` that uses `Translated` is reachable from the site's index, so no catalog
    goes unchecked;
  - no view passes a word straight to `containing()`, `alt`, `title` or `aria-label` as a literal.
- **The scope rules** are pinned in the suite of the site the framework grew in, and have no
  framework test of their own yet. Those rules are inheritance, a `lang` narrowing the scope, a
  foreign `lang` keeping it, a translated attribute, and the refusal.

## Adding a word

Add a case on the right catalog, with both languages, and put the case at the call site. If the
words depend on something the view knows, name it in the message (`{title}`) and bind it with
`->with()`.

## Adding a language

The framework knows six: English, German, French, Spanish, Italian and Dutch. An app offering one
of those skips the first two steps.

1. A `Language` case, and its `endonym()`; the same case in `assets/ts/model/Language.ts`.
2. A parameter on `Translation` (`pl:`), and its arm in `written()`.
3. The app offering it, in `languages()`, and in the client's list of the languages it offers.
4. Its words on every catalog case. A site's catalog check lists each one missing. The framework's
   own words, in `FrameworkText`, are written in English and German; in another language they fall
   back until someone writes them.
5. Its words in each client `Record`. `tsc` lists each one missing.

A site whose default is not English writes its default first, `new Languages(Language::German,
Language::English)`, and nothing else changes: the fallback, the tie in `Accept-Language` and the
client's fallback all follow it.

A document written separately in each language rather than translated, such as a legal text, gains a
third version only by someone writing it. That is a decision, not a translation.

## Traps

- **`Translatable` is asked before `BackedEnum`.** A catalog case is both, and read as an enum it
  would render its key: `cover-art` in an alt text. `Element::attr()` orders its arms that way.
- **Render a view with a language.** `$view->content()->render()` throws on the first translated
  word. A test writes `->render(0, Language::English)`.
- **A language the framework knows and an app does not offer is answered as if it were nothing**,
  whether it arrives in the cookie or in `Accept-Language`.
- **A data file naming a new catalog case needs the case deployed first.** A push ships `src/` and
  never `data/`. Ship the code that declares the case, then the data file that names it.
