<?php

declare(strict_types=1);

namespace Phpanta\View;

use Phpanta\App;
use Phpanta\Http\RequestHeader;
use Phpanta\Support\BareArray;
use Phpanta\Text\Joined;
use Phpanta\Text\Translatable;
use Phpanta\Text\Verbatim;
use Phpanta\View\Html\Node;

/**
 * The View abstract class. Base class for all page views.
 *
 * Each concrete view produces a page title and an HTML content fragment.
 * The fragment is embedded into the site's {@link Shell} for full-page
 * requests, or sent directly for AJAX fragment requests.
 *
 * The fragment is a {@link Node}, not a string: a view assembles a tree and something else decides
 * when it becomes markup. Nothing in this namespace concatenates HTML any more, so there is no
 * point at which a value could reach the page unescaped.
 */
abstract class View
{
    /**
     * Returns the page title for this view, to be put into the page's language when it renders.
     *
     * @return Translatable
     */
    abstract public function pageTitle(): Translatable;
    /**
     * Returns the HTML content fragment for this view.
     *
     * @return Node
     */
    abstract public function content(): Node;

    /**
     * The request headers this page's body depends on, beyond the ones every page depends on.
     *
     * **A page that reads a request header owes a `Vary` naming it**, and stating both facts in one
     * place is what stops the second being forgotten: {@link \Phpanta\Http\ViewResponse} builds
     * the header from this, so a view cannot start varying on something without saying so. Forget
     * it and there is no error — a cache simply becomes free to hand one visitor the page it built
     * for another, with nothing visibly wrong at all.
     *
     * Three are on no view's list because every page varies on them, so they belong to the response
     * rather than to the page: `X-Requested-With`, which decides document or fragment, and
     * `Accept-Language` and `Cookie`, which decide the language every page is written in — see
     * {@link \Phpanta\Http\Request::language()}. No view adds anything today.
     *
     * @return list<RequestHeader>
     */
    #[BareArray(
        'spread into Vary::on(), a variadic PHP already guards. A collection here would replace a '
        . 'check the language makes for free with one we make ourselves, and add a toValues() at '
        . 'the one call site.',
    )]
    public function varyOn(): array
    {
        return [];
    }

    /**
     * A page title: the section, then the site.
     *
     * Written once, here, rather than by each view: `' — neuro.SYS'` in six views is six chances to
     * use a hyphen where the others use an em dash and never notice. A translatable rather than a
     * string, because most sections are words, which the language decides when the title renders;
     * a section that is a name — a release's title — is the same in every language.
     *
     * @param Translatable|string|null $section
     * @return Translatable
     */
    protected static function title(Translatable|string|null $section = null): Translatable
    {
        $site = new Verbatim(App::current()->name());

        return match (true) {
            $section === null                => $site,
            $section instanceof Translatable => new Joined(' — ', $section, $site),
            default                          => new Joined(' — ', new Verbatim($section), $site),
        };
    }
}
