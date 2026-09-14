<?php

declare(strict_types=1);

namespace Phpanta\View;

use Phpanta\Http\RequestHeader;
use Phpanta\Model\Passkey\CeremonyType;
use Phpanta\Model\Passkey\EntranceCeremony;
use Phpanta\Support\BareArray;
use Phpanta\Support\Collection;
use Phpanta\Text\AdminText;
use Phpanta\Text\Translatable;
use Phpanta\View\Html\Element;
use Phpanta\View\Html\HtmlTag;
use Phpanta\View\Html\Node;

/**
 * The AdminEntranceView class. `/admin`, for a visitor it has not let in.
 *
 * The one admin page anybody may see. It says that there is an admin — which the site may say anyway,
 * with a link to it — and, where this deployment lets a browser in, offers the two things a browser
 * can do here: unlock with an enrolled passkey, or register this device for an enrolment code. Every
 * deeper address sends a stranger here, whether it exists or not.
 */
final class AdminEntranceView extends View
{
    /**
     * Constructs an instance of {@link self}.
     *
     * @param Collection<Translatable> $messages  What to say first — why the last attempt failed, or
     *                                            that browsers cannot sign in here.
     * @param string|null              $token     The session's form token; null shows no form.
     * @param string|null              $challenge The entrance's challenge, answered by either ceremony.
     */
    public function __construct(
        private readonly Collection $messages = new Collection(Translatable::class),
        private readonly ?string    $token = null,
        private readonly ?string    $challenge = null,
    ) {}

    /**
     * @return Translatable
     */
    public function pageTitle(): Translatable
    {
        return self::title(AdminText::Admin);
    }

    /**
     * @return Node
     */
    public function content(): Node
    {
        $section = new Element(HtmlTag::Section)->containing(
            new Element(HtmlTag::H1)->containing(AdminText::Admin),
            new Element(HtmlTag::P)->containing(AdminText::Entrance),
            ...$this->messages->map(static fn(Translatable $said): Node => new Element(HtmlTag::P)->containing(
                new Element(HtmlTag::Strong)->containing($said),
            ))->toValues(),
        );

        if ($this->token === null || $this->challenge === null) {
            return $section;
        }

        return $section->containing(
            AdminForm::entrance(
                EntranceCeremony::Unlock,
                CeremonyType::Get,
                $this->token,
                $this->challenge,
                AdminText::Unlock,
            ),
            new Element(HtmlTag::P)->containing(AdminText::RegisterHowTo),
            AdminForm::entrance(
                EntranceCeremony::Register,
                CeremonyType::Create,
                $this->token,
                $this->challenge,
                AdminText::RegisterDevice,
            ),
        );
    }

    /**
     * The entrance is what a request for a page gets; one for data gets a `401` instead.
     *
     * @return list<RequestHeader>
     */
    #[BareArray('overrides View::varyOn(), whose own attribute says why it is an array')]
    public function varyOn(): array
    {
        return [RequestHeader::Accept];
    }
}
