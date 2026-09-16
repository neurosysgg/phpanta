<?php

declare(strict_types=1);

namespace Phpanta\Test\Unit;

use Phpanta\Http\Api\AccessAction;
use Phpanta\Http\Api\ActionField;
use Phpanta\Http\Api\ApiListing;
use Phpanta\Http\Api\ApiService;
use Phpanta\Http\Api\ApiVersion;
use Phpanta\Http\Api\CapabilityAction;
use Phpanta\Http\Api\HealthAction;
use Phpanta\Http\Api\ListingEntry;
use Phpanta\Http\Api\UpdateAction;
use Phpanta\Http\HttpMethod;
use Phpanta\Http\RequestHeader;
use Phpanta\Model\Update\UpdateManifest;
use Phpanta\Text\AdminText;
use Phpanta\Text\Language;
use Phpanta\View\AdminEntranceView;
use Phpanta\View\ApiListingView;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The admin, discovering itself: what each service, version and action says about itself, and the
 * listings built from it.
 *
 * **The claim is that the listing is the vocabulary.** A listing is built from
 * {@link ApiService::versions()} and {@link ApiService::actions()}, and an address is resolved
 * through the same members, so what the admin names and what it answers cannot disagree. How a
 * listing reaches a caller — the gate, the representation — is {@link ApiTest}'s.
 */
#[CoversClass(ApiService::class)]
#[CoversClass(ApiVersion::class)]
#[CoversClass(UpdateAction::class)]
#[CoversClass(HealthAction::class)]
#[CoversClass(CapabilityAction::class)]
#[CoversClass(AccessAction::class)]
#[CoversClass(ActionField::class)]
#[CoversClass(ApiListing::class)]
#[CoversClass(ListingEntry::class)]
#[CoversClass(AdminText::class)]
#[CoversClass(ApiListingView::class)]
#[CoversClass(AdminEntranceView::class)]
final class DiscoveryTest extends TestCase
{
    /**
     * Every service offers the one version there is — but `machine` and `drop`, which a deployment
     * switches on and this one has not, and which therefore offer nothing at all.
     *
     * @return void
     */
    public function testEveryServiceOffersTheVersionThereIs(): void
    {
        foreach (ApiService::cases() as $service) {
            self::assertSame(
                $service === ApiService::Machine || $service === ApiService::Drop ? [] : [ApiVersion::V1],
                $service->versions()->toValues(),
                $service->value,
            );
        }
    }

    /**
     * An action is resolved from the same list that names it, and a segment that names none is
     * nothing rather than a throw.
     *
     * @return void
     */
    public function testAnActionIsResolvedFromTheListThatNamesIt(): void
    {
        self::assertSame(UpdateAction::cases(), ApiService::Update->actions(ApiVersion::V1)->toValues());
        self::assertSame(HealthAction::cases(), ApiService::Health->actions(ApiVersion::V1)->toValues());
        self::assertSame(CapabilityAction::cases(), ApiService::Capability->actions(ApiVersion::V1)->toValues());
        self::assertSame(AccessAction::cases(), ApiService::Access->actions(ApiVersion::V1)->toValues());

        foreach (ApiService::cases() as $service) {
            foreach ($service->actions(ApiVersion::V1) as $action) {
                self::assertSame($action, $service->action(ApiVersion::V1, (string) $action->value));
            }

            self::assertNull($service->action(ApiVersion::V1, 'nope'));
        }
    }

    /**
     * Every service, version, action and field says what it is, in each language, and says it
     * differently in each — a German case left in English is the mistake this would catch.
     *
     * @return void
     */
    public function testEverythingListedSaysWhatItIsInBothLanguages(): void
    {
        $said = [ApiVersion::V1->describe(), ...array_map(
            static fn(ActionField $field) => $field->describe(),
            ActionField::cases(),
        )];

        foreach (ApiService::cases() as $service) {
            $said[] = $service->describe();

            foreach ($service->actions(ApiVersion::V1) as $action) {
                $said[] = $action->describe();
            }
        }

        foreach ($said as $text) {
            self::assertNotSame('', $text->in(Language::English));
            self::assertNotSame($text->in(Language::English), $text->in(Language::German));
        }
    }

    /**
     * An action's fields are the manifest's own keys, and each action names the ones it reads; only
     * a push is the signing commands' alone.
     *
     * @return void
     */
    public function testAnActionNamesTheFieldsItsManifestReads(): void
    {
        self::assertSame(UpdateManifest::APPLY, ActionField::Apply->value);
        self::assertSame(UpdateManifest::MIRROR, ActionField::Mirror->value);

        self::assertSame([ActionField::Apply, ActionField::Mirror], UpdateAction::Patch->fields()->toValues());
        self::assertSame([ActionField::Apply], UpdateAction::Rollback->fields()->toValues());
        self::assertSame([ActionField::Apply], UpdateAction::Probe->fields()->toValues());
        self::assertTrue(UpdateAction::Version->fields()->isEmpty());

        self::assertFalse(UpdateAction::Patch->fromBrowser());
        self::assertTrue(UpdateAction::Rollback->fromBrowser());

        foreach ([...HealthAction::cases(), ...CapabilityAction::cases(), AccessAction::Passkeys] as $read) {
            self::assertTrue($read->fields()->isEmpty(), $read->value);
            self::assertTrue($read->fromBrowser(), $read->value);
        }

        // Enrolment is where trust starts, so a browser cannot enrol itself; it can revoke.
        self::assertSame(
            [ActionField::Code, ActionField::Name, ActionField::Apply],
            AccessAction::Enrol->fields()->toValues(),
        );
        self::assertSame([ActionField::Passkey, ActionField::Apply], AccessAction::Revoke->fields()->toValues());
        self::assertFalse(AccessAction::Enrol->fromBrowser());
        self::assertTrue(AccessAction::Revoke->fromBrowser());
        self::assertSame(HttpMethod::Post, AccessAction::Enrol->method());
        self::assertSame(HttpMethod::Get, AccessAction::Passkeys->method());
    }

    /**
     * No action but the machine service's takes a path after it: every other acts on the deployment
     * as a whole, or on a device named by a field.
     *
     * @return void
     */
    public function testOnlyTheMachineServiceTakesAPath(): void
    {
        foreach ([...UpdateAction::cases(), ...HealthAction::cases(), ...CapabilityAction::cases(), ...AccessAction::cases()] as $action) {
            self::assertFalse($action->takesPath(), $action->value);
        }
    }

    /**
     * The entrance lists every service, where it is and what it is for, and nothing more.
     *
     * @return void
     */
    public function testTheEntranceListsEveryService(): void
    {
        $data = self::data(ApiListing::services(Language::English));

        self::assertSame('/admin', $data['address']);
        self::assertSame(['update', 'health', 'capability', 'access'], array_column($data['entries'], 'name'));
        self::assertSame(
            ['/admin/update', '/admin/health', '/admin/capability', '/admin/access'],
            array_column($data['entries'], 'href'),
        );
        self::assertSame(
            [
                'name'        => 'update',
                'href'        => '/admin/update',
                'description' => AdminText::ServiceUpdate->in(Language::English),
            ],
            $data['entries'][0],
        );
    }

    /**
     * A service lists its versions, and a version its actions — each action with what a caller
     * needs to make the call, in the language the listing was asked in.
     *
     * @return void
     */
    public function testAVersionListsItsActionsAndWhatEachTakes(): void
    {
        $versions = self::data(ApiListing::versions(ApiService::Update, Language::English));
        $actions  = self::data(ApiListing::actions(ApiService::Update, ApiVersion::V1, Language::German));

        self::assertSame('/admin/update', $versions['address']);
        self::assertSame(
            [[
                'name'        => 'v1',
                'href'        => '/admin/update/v1',
                'description' => AdminText::VersionOne->in(Language::English),
            ]],
            $versions['entries'],
        );

        self::assertSame('/admin/update/v1', $actions['address']);
        self::assertSame([
            'name'        => 'patch',
            'href'        => '/admin/update/v1/patch',
            'description' => AdminText::UpdatePatch->in(Language::German),
            'method'      => 'POST',
            'writes'      => true,
            'browser'     => false,
            'fields'      => ['apply', 'mirror'],
        ], $actions['entries'][0]);
        self::assertSame([
            'name'        => 'version',
            'href'        => '/admin/update/v1/version',
            'description' => AdminText::UpdateVersion->in(Language::German),
            'method'      => 'GET',
            'writes'      => false,
            'browser'     => true,
            'fields'      => [],
        ], $actions['entries'][1]);
    }

    /**
     * A page links what it lists — but a write only by name, since following a link is a read, and
     * says which only the signing commands can run.
     *
     * @return void
     */
    public function testAPageLinksWhatItListsAndOnlyNamesAWrite(): void
    {
        $view = new ApiListingView(ApiListing::actions(ApiService::Update, ApiVersion::V1, Language::English));
        $html = self::flat($view->content()->render(0, Language::English));

        self::assertStringContainsString('<a href="/admin/update/v1/version">version</a>', $html);
        self::assertStringContainsString('<strong>patch</strong>', $html);
        self::assertStringNotContainsString('href="/admin/update/v1/patch"', $html);
        self::assertStringContainsString('<td>' . AdminText::CliOnly->in(Language::English) . '</td>', $html);
        self::assertStringContainsString('<td>' . AdminText::Writes->in(Language::English) . '</td>', $html);
        self::assertStringContainsString('<td>' . AdminText::Reads->in(Language::English) . '</td>', $html);

        $services = new ApiListingView(ApiListing::services(Language::English));

        self::assertStringContainsString(
            '<a href="/admin/update">update</a>',
            $services->content()->render(0, Language::English),
        );
        self::assertSame([RequestHeader::Accept], $services->varyOn());
        self::assertStringStartsWith('/admin', $services->pageTitle()->in(Language::English));
    }

    /**
     * The entrance says there is an admin and that it needs a credential, and nothing about what is
     * in it.
     *
     * @return void
     */
    public function testTheEntranceSaysOnlyThatThereIsAnAdmin(): void
    {
        $view = new AdminEntranceView();

        self::assertStringContainsString(
            AdminText::Entrance->in(Language::German),
            $view->content()->render(0, Language::German),
        );
        self::assertStringStartsWith(AdminText::Admin->in(Language::German), $view->pageTitle()->in(Language::German));
        self::assertSame([RequestHeader::Accept], $view->varyOn());
    }

    /**
     * $listing as the data a caller receives.
     *
     * @param ApiListing $listing
     * @return array{address: string, entries: list<array<string, mixed>>}
     */
    private static function data(ApiListing $listing): array
    {
        /** @var array{address: string, entries: list<array<string, mixed>>} $data */
        $data = json_decode((string) json_encode($listing, JSON_THROW_ON_ERROR), true, 8, JSON_THROW_ON_ERROR);

        return $data;
    }

    /**
     * $html with the indentation between tags taken out, so an assertion reads the structure.
     *
     * @param string $html
     * @return string
     */
    private static function flat(string $html): string
    {
        return (string) preg_replace('/>\s+</', '><', $html);
    }
}
