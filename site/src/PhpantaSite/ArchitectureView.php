<?php

declare(strict_types=1);

namespace PhpantaSite;

use Phpanta\View\Html\Element;
use Phpanta\View\Html\Fragment;
use Phpanta\View\Html\Sentence;
use PhpantaSite\Text\ArchitectureText;
use PhpantaSite\Text\SiteText;

/**
 * The architecture: a request traced, the layers, the app, the wire, the signed API, navigation.
 */
final class ArchitectureView extends ProseView
{
    /**
     * @return Page
     */
    public function page(): Page
    {
        return Page::Architecture;
    }

    /**
     * @return Fragment
     */
    protected function body(): Fragment
    {
        return new Fragment(
            Prose::title(SiteText::Architecture),
            Prose::lede(new Sentence(ArchitectureText::Lede, index: Prose::code('index.php'))),
            Prose::heading(ArchitectureText::TheRequest),
            Prose::sample(CodeSample::TheRequest),
            Prose::paragraph(ArchitectureText::Handler),
            Prose::heading(ArchitectureText::Layers),
            Prose::sample(CodeSample::Layers),
            Prose::paragraph(new Sentence(ArchitectureText::LayersText, html: Prose::code('View\Html'))),
            Prose::heading(ArchitectureText::TheApp),
            Prose::paragraph(new Sentence(ArchitectureText::TheAppText, current: Prose::code('App::current()'))),
            Prose::heading(ArchitectureText::TheWire),
            Prose::paragraph(new Sentence(
                ArchitectureText::TheWireText,
                null: Prose::code('null'),
                get: Prose::code('GET'),
            )),
            Prose::heading(ArchitectureText::SignedApi),
            Prose::paragraph(new Sentence(ArchitectureText::SignedApiText, post: Prose::code('POST'))),
            Prose::heading(ArchitectureText::SpaNavigation),
            Prose::paragraph(new Sentence(
                ArchitectureText::SpaNavigationText,
                href: Prose::code('href'),
                navigation: Prose::code('Navigation'),
                requestedWith: Prose::code('X-Requested-With'),
                content: Prose::code('#content'),
            )),
            Prose::heading(ArchitectureText::FurtherReading),
            Prose::items(
                new Sentence(ArchitectureText::ReadArchitecture, doc: self::document('architecture.md')),
                new Sentence(ArchitectureText::ReadSecurity, doc: self::document('security.md')),
                new Sentence(ArchitectureText::ReadFrontend, doc: self::document('frontend.md')),
                new Sentence(ArchitectureText::ReadCollections, doc: self::document('collections.md')),
            ),
        );
    }

    /**
     * A link to one of the framework's documents in the repository, named by its path there.
     *
     * @param string $name
     * @return Element
     */
    private static function document(string $name): Element
    {
        return Prose::link(Site::REPOSITORY . '/blob/master/docs/' . $name, 'docs/' . $name);
    }
}
