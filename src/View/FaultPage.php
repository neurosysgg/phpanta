<?php

declare(strict_types=1);

namespace Phpanta\View;

use NoDiscard;
use Phpanta\Http\Answer;
use Phpanta\Http\CacheControl;
use Phpanta\Http\ContentLanguage;
use Phpanta\Http\Header;
use Phpanta\Http\HttpStatusCode;
use Phpanta\Http\MimeType;
use Phpanta\Http\Request;
use Phpanta\Http\Response;
use Phpanta\Http\ResponseHeader;
use Phpanta\Http\TextBody;
use Phpanta\Support\Collection;
use Phpanta\Text\FrameworkText;
use Phpanta\Text\Language;
use Phpanta\Text\Verbatim;
use Phpanta\View\Html\Document;
use Phpanta\View\Html\Element;
use Phpanta\View\Html\Fragment;
use Phpanta\View\Html\HtmlAttribute;
use Phpanta\View\Html\HtmlTag;
use Throwable;

/**
 * The FaultPage class. A fault, its chain and its trace, for a developer on their own machine.
 *
 * Only {@link \Phpanta\App::fault()} builds one, and only when the environment shows faults to the
 * request — see {@link \Phpanta\Environment::showsFaultsTo()}. Everyone else gets a bare 500.
 *
 * **It is drawn in a document of its own, not in the app's shell.** The shell is the site's, it
 * reads the site's data, and it is exactly as likely as anything else to be what just threw; a fault
 * page that needed it could not show the one fault it exists for. What it does use is the markup
 * tree, so every message, file and frame is escaped by the same rule as every other word.
 */
final readonly class FaultPage implements Response
{
    /**
     * Constructs an instance of {@link self}.
     *
     * @param Throwable $fault What was thrown; its previous faults are shown beneath it.
     */
    public function __construct(private Throwable $fault) {}

    /**
     * A 500, not to be kept, carrying the page.
     *
     * @param Request $request
     * @return Answer
     */
    #[NoDiscard(
        'answer() works out what would be sent and sends nothing; a call whose result goes nowhere '
        . 'answered no one',
    )]
    public function answer(Request $request): Answer
    {
        $language = $request->language();

        return new Answer(
            HttpStatusCode::InternalServerError,
            new Collection(Header::class)->with(
                new Header(ResponseHeader::ContentType, MimeType::html()),
                new Header(ResponseHeader::ContentLanguage, new ContentLanguage($language)),
                new Header(ResponseHeader::CacheControl, CacheControl::doNotStore()),
            ),
            new TextBody($this->document($language)->render(0, $language)),
        );
    }

    /**
     * @param Language $language
     * @return Document
     */
    private function document(Language $language): Document
    {
        $sections = [];

        for ($fault = $this->fault; $fault !== null; $fault = $fault->getPrevious()) {
            $sections[] = self::section($fault);
        }

        return new Document(
            new Element(HtmlTag::Html)
                ->attr(HtmlAttribute::Lang, $language)
                ->containing(
                    new Element(HtmlTag::Head)->containing(
                        new Element(HtmlTag::Title)->containing(FrameworkText::Fault),
                    ),
                    new Element(HtmlTag::Body)->containing(
                        new Element(HtmlTag::Main)->containing(
                            new Element(HtmlTag::H1)->containing(FrameworkText::Fault),
                            new Fragment(...$sections),
                        ),
                    ),
                ),
        );
    }

    /**
     * One fault of the chain: its class, its message, where it was thrown, and the frames that led
     * there.
     *
     * @param Throwable $fault
     * @return Element
     */
    private static function section(Throwable $fault): Element
    {
        $frames = [];

        // PHP's own rendering of the trace, one frame a line, rather than the frames' arrays read by
        // key: the words are PHP's, and so is the format a developer already knows how to read.
        foreach (explode("\n", $fault->getTraceAsString()) as $frame) {
            $frames[] = new Element(HtmlTag::Li)->containing(new Verbatim($frame));
        }

        return new Element(HtmlTag::Section)->containing(
            new Element(HtmlTag::H2)->containing(new Verbatim($fault::class)),
            new Element(HtmlTag::P)->containing(
                new Element(HtmlTag::Strong)->containing(new Verbatim($fault->getMessage())),
            ),
            new Element(HtmlTag::P)->containing(
                new Element(HtmlTag::Small)->containing(new Verbatim($fault->getFile() . ':' . $fault->getLine())),
            ),
            new Element(HtmlTag::Ul)->containing(...$frames),
        );
    }
}
