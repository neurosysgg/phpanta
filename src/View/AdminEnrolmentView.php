<?php

declare(strict_types=1);

namespace Phpanta\View;

use Phpanta\Http\Api\AccessAction;
use Phpanta\Http\Api\ActionField;
use Phpanta\Http\Api\ApiService;
use Phpanta\Http\Api\ApiVersion;
use Phpanta\Http\RequestHeader;
use Phpanta\Support\BareArray;
use Phpanta\Text\AdminText;
use Phpanta\Text\Translatable;
use Phpanta\View\Html\Element;
use Phpanta\View\Html\HtmlAttribute;
use Phpanta\View\Html\HtmlTag;
use Phpanta\View\Html\Node;

/**
 * The AdminEnrolmentView class. What a device that has just registered is shown: the enrolment code,
 * the key's fingerprint, and the signed call that turns the one into an enrolled device.
 *
 * The code is sealed under the deployment's session key, so it is only ever something this deployment
 * made, and it lasts ten minutes. The fingerprint is how a person matches what this page shows to what
 * `access v1 passkeys` lists afterwards.
 */
final class AdminEnrolmentView extends View
{
    /** How the command is started on the signing machine; the rest of it is the admin's own words. */
    private const string COMMAND = 'php tools/api.php';

    /**
     * Constructs an instance of {@link self}.
     *
     * @param string $code        The enrolment code, sealed.
     * @param string $fingerprint The registered key's fingerprint.
     */
    public function __construct(
        private readonly string $code,
        private readonly string $fingerprint,
    ) {}

    /**
     * @return Translatable
     */
    public function pageTitle(): Translatable
    {
        return self::title(AdminText::EnrolmentCode);
    }

    /**
     * @return Node
     */
    public function content(): Node
    {
        $command = implode(' ', [
            self::COMMAND,
            ApiService::Access->value,
            ApiVersion::V1->value,
            AccessAction::Enrol->value,
            '--' . ActionField::Name->value,
            AdminText::RegisterDevice->value,
            '--' . ActionField::Code->value,
            $this->code,
        ]);

        return new Element(HtmlTag::Section)->containing(
            new Element(HtmlTag::H1)->containing(AdminText::EnrolmentCode),
            new Element(HtmlTag::P)->containing(
                AdminText::Fingerprint,
                ' ',
                new Element(HtmlTag::Strong)->containing($this->fingerprint),
            ),
            new Element(HtmlTag::P)->containing(AdminText::EnrolmentHowTo),
            new Element(HtmlTag::Textarea)
                ->attr(HtmlAttribute::Readonly)
                ->attr(HtmlAttribute::AriaLabel, AdminText::EnrolmentCode)
                ->containing($command),
        );
    }

    /**
     * Answered to a page request only.
     *
     * @return list<RequestHeader>
     */
    #[BareArray('overrides View::varyOn(), whose own attribute says why it is an array')]
    public function varyOn(): array
    {
        return [RequestHeader::Accept];
    }
}
