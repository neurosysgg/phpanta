<?php

declare(strict_types=1);

namespace PhpantaSite;

use Phpanta\View\Html\Element;

/**
 * The code samples the pages show. Code, so the same in every language; set as text inside the
 * {@link CodeTag} elements, so escaped by the tree like any other.
 */
enum CodeSample: string
{
    case Layout     = 'layout';
    case Vendoring  = 'vendoring';
    case TheApp     = 'the-app';
    case APage      = 'a-page';
    case Building   = 'building';
    case Export     = 'export';
    case TheRequest = 'the-request';
    case Layers     = 'layers';

    /**
     * What the sample is written in.
     *
     * @return SampleLanguage
     */
    public function language(): SampleLanguage
    {
        return match ($this) {
            self::TheApp, self::APage                     => SampleLanguage::Php,
            self::Vendoring, self::Building, self::Export => SampleLanguage::Shell,
            self::Layout, self::TheRequest, self::Layers  => SampleLanguage::Tree,
        };
    }

    /**
     * The sample as it is shown: a `<code-block>` of its text, highlighted as its language.
     *
     * @return Element
     */
    public function highlighted(): Element
    {
        return $this->language()->highlight($this->text());
    }

    /**
     * The sample's text.
     *
     * @return string
     */
    public function text(): string
    {
        // A sample's lines are the sample's: the two trees below are shown as they are, so a line of
        // one is as long as it needs to be, and wrapping it for the linter would change the page.
        // phpcs:disable Generic.Files.LineLength.TooLong
        return match ($this) {
            self::Layout => <<<'TEXT'
                site/
                ├── autoload.php        requires phpanta/autoload.php, maps the site's namespace, boots the app
                ├── composer.json       psr-4: the site's namespace → src/<Ns>/, and Phpanta\ → phpanta/src/
                ├── public/index.php    a last-resort handler, then <Site>::current()->run()
                ├── src/<Ns>/           the app class, controllers, views, models, words
                ├── data/               what the site reads at runtime, outside the webroot
                ├── assets/ts/          the site's TypeScript; assets/ts/phpanta links to the framework's
                ├── assets/css/         the site's stylesheet, as one @import manifest
                └── phpanta/            the framework, as a git submodule
                TEXT,
            self::Vendoring => <<<'TEXT'
                git submodule add ../phpanta.git phpanta
                ln -s ../../phpanta/assets/ts assets/ts/phpanta
                git config submodule.recurse true
                git config push.recurseSubmodules on-demand
                TEXT,
            self::TheApp => <<<'TEXT'
                final class Site extends App
                {
                    public function name(): string            { return 'Acme'; }
                    public function above(): Directory        { return new Directory(dirname(__DIR__, 2)); }
                    public function languages(): Languages    { return new Languages(Language::English); }
                    public function shell(): Shell            { return new Layout(); }
                    public function vocabulary(): Vocabulary  { return Vocabulary::standard(); }
                    public function buildId(): string         { return AssetManifest::SCRIPT; }

                    public function routes(): Collection
                    {
                        return new Collection(Route::class)->with(
                            new Route(AcmePath::Home, fn() => new HomeController()),
                        );
                    }

                    public function notFound(Request $request): Response
                    {
                        return new ViewResponse(new NotFoundView(), HttpStatusCode::NotFound);
                    }

                    protected function ownDataFiles(): Collection
                    {
                        return new Collection(DataFileName::class);
                    }
                }
                TEXT,
            self::APage => <<<'TEXT'
                final class HomeView extends View
                {
                    public function pageTitle(): Translatable
                    {
                        return self::title();
                    }

                    public function content(): Node
                    {
                        return new Element(HtmlTag::P)->containing(AcmeText::Hello);
                    }
                }
                TEXT,
            self::Building => <<<'TEXT'
                tsc                                     # assets/ts/ → public/assets/js/
                node phpanta/tools/build-css.mjs        # assets/css/main.css → public/assets/css/style.css
                node phpanta/tools/build-assets.mjs     # the stamped AssetManifest the shell reads
                node phpanta/tools/build-prod.mjs       # build/dist/ — bundled, minified, no maps
                php -S localhost:8080 -t public phpanta/tools/dev-router.php
                TEXT,
            self::Export => <<<'TEXT'
                php phpanta/tools/export.php --out build/pages --base /phpanta/
                TEXT,
            self::TheRequest => <<<'TEXT'
                public/index.php
                  ├─ set_exception_handler(…)       the last resort, depending on nothing
                  └─ Site::current()->run()
                       ├─ ErrorLog::install(…)        every diagnostic into data/logs/
                       ├─ SecurityHeaders::send()     CSP, HSTS, Permissions-Policy, COOP, CORP — before anything can fail
                       ├─ Request::fromGlobals()      $_SERVER → a typed, readonly Request
                       ├─ App::handle()               the request → an Answer, sending nothing
                       │    ├─ App::layers()            what stands around every route: a layer answers, or hands on
                       │    ├─ Router::dispatch()       the path → a route → its method gate → its layers → a controller
                       │    └─ Response::answer()       status, headers and body — the security headers first
                       └─ Answer::send()              the one place anything is sent
                TEXT,
            self::Layers => <<<'TEXT'
                src/
                ├── App.php         what a site tells the framework about itself
                ├── Router.php      URL → controller, and nothing else
                ├── Controller/     the Controller interface, and the API's
                ├── Http/           Request, Input, Upload, Session, Answer, the responses, every header typed; Api/, Security/
                ├── View/           View, Shell; Html/ — the markup tree, MarkupParser, Vocabulary
                ├── Form/           a form as an enum of fields, its rules, a submission read and re-rendered
                ├── Data/           SQLite through PDO: statements, typed rows, transactions, migrations
                ├── Text/           Translatable, Translation, Language, Languages, an address per language
                ├── Support/        Collection, File, Directory, Path and Route, Throttle, PublicKey, TarArchive
                ├── Model/          Api/ (a signed call), Update/ (a push, the release it replaced), Health/, Passkey/
                ├── Service/        Auth, Login, ApiGate, UpdateApplier, ReleaseRecord; Layer/, Passkey/, the API's handlers
                └── Exception/      SiteException and every condition under it
                TEXT,
        };
    }
}
