<?php

declare(strict_types=1);

namespace PhpantaSite;

use Phpanta\View\Html\Fragment;
use Phpanta\View\Html\Sentence;
use PhpantaSite\Text\GettingStartedText;
use PhpantaSite\Text\SiteText;

/**
 * Getting started: the layout, vendoring, the app, a page, building, deploying.
 */
final class GettingStartedView extends ProseView
{
    /**
     * @return Page
     */
    public function page(): Page
    {
        return Page::GettingStarted;
    }

    /**
     * @return Fragment
     */
    protected function body(): Fragment
    {
        return new Fragment(
            Prose::title(SiteText::GettingStarted),
            Prose::lede(GettingStartedText::Lede),
            Prose::heading(GettingStartedText::Layout),
            Prose::sample(CodeSample::Layout),
            Prose::heading(GettingStartedText::Vendoring),
            Prose::sample(CodeSample::Vendoring),
            Prose::paragraph(new Sentence(
                GettingStartedText::VendoringText,
                framework: Prose::code('phpanta/'),
                add: Prose::code('git add phpanta'),
            )),
            Prose::heading(GettingStartedText::TheApp),
            Prose::paragraph(new Sentence(GettingStartedText::TheAppText, app: Prose::code('Phpanta\App'))),
            Prose::sample(CodeSample::TheApp),
            Prose::paragraph(new Sentence(GettingStartedText::Derived, data: Prose::code('data/'))),
            Prose::heading(GettingStartedText::APage),
            Prose::paragraph(new Sentence(
                GettingStartedText::APageText,
                path: Prose::code('Path'),
                viewResponse: Prose::code('ViewResponse'),
                navigation: Prose::code('Navigation'),
            )),
            Prose::sample(CodeSample::APage),
            Prose::heading(GettingStartedText::Building),
            Prose::sample(CodeSample::Building),
            Prose::paragraph(new Sentence(
                GettingStartedText::BuildingText,
                composer: Prose::code('composer.json'),
                psr4: Prose::code('psr-4'),
            )),
            Prose::heading(GettingStartedText::Deploying),
            Prose::paragraph(new Sentence(
                GettingStartedText::DeployingText,
                frameworkSource: Prose::code('phpanta/src/'),
                frameworkAutoload: Prose::code('phpanta/autoload.php'),
                source: Prose::code('src/'),
                autoload: Prose::code('autoload.php'),
                push: Prose::code('PushUpdate'),
            )),
            Prose::sample(CodeSample::Export),
            Prose::paragraph(GettingStartedText::Exported),
        );
    }
}
