<?php

declare(strict_types=1);

namespace Phpanta\Http\Api;

use JsonSerializable;
use Phpanta\Support\AdminPath;
use Phpanta\Support\Collection;
use Phpanta\Text\Language;

/**
 * The ApiListing class. What is under one admin address: the services at the entrance, a service's
 * versions, a version's actions.
 *
 * **The listing is the vocabulary, asked.** It is built from {@link ApiService::versions()} and
 * {@link ApiService::actions()}, the same members {@link ApiService::action()} resolves an address
 * with — so a listing cannot name an action the admin would not answer, nor leave out one it would.
 * That is the whole of the admin's discoverability: nothing is registered for it, and nothing can be
 * forgotten.
 *
 * It is written as a page by {@link \Phpanta\View\ApiListingView} and as data by
 * {@link self::jsonSerialize()}, in the language it was asked in, so the descriptions a script reads
 * are the words the page shows.
 */
final readonly class ApiListing implements JsonSerializable
{
    /**
     * Constructs an instance of {@link self}.
     *
     * @param string $address The address this lists.
     * @param Language $language The language its descriptions are written in, as data.
     * @param Collection<ListingEntry> $entries In the vocabulary's order.
     */
    private function __construct(
        public string       $address,
        private Language    $language,
        private Collection  $entries,
    ) {}

    /**
     * The entrance: every service this deployment offers anything at — so a service a deployment
     * must switch on, and has not, is not named at all.
     *
     * @param Language $language
     * @return self
     */
    public static function services(Language $language): self
    {
        return new self(AdminPath::Index->to(), $language, new Collection(ApiService::class)
            ->with(...ApiService::cases())
            ->where(static fn(ApiService $service): bool => !$service->versions()->isEmpty())
            ->map(static fn(ApiService $service): ListingEntry => new ListingEntry(
                $service->value,
                AdminPath::Service->to($service->value),
                $service->describe(),
            )));
    }

    /**
     * One service: the versions it offers.
     *
     * @param ApiService $service
     * @param Language $language
     * @return self
     */
    public static function versions(ApiService $service, Language $language): self
    {
        return new self(AdminPath::Service->to($service->value), $language, $service->versions()
            ->map(static fn(ApiVersion $version): ListingEntry => new ListingEntry(
                $version->value,
                AdminPath::Version->to($service->value, $version->value),
                $version->describe(),
            )));
    }

    /**
     * One version of one service: its actions. The version is one {@link ApiService::versions()}
     * offered, which is what makes this never empty.
     *
     * @param ApiService $service
     * @param ApiVersion $version
     * @param Language $language
     * @return self
     */
    public static function actions(ApiService $service, ApiVersion $version, Language $language): self
    {
        return new self(AdminPath::Version->to($service->value, $version->value), $language, $service
            ->actions($version)
            ->map(static fn(ApiAction $action): ListingEntry => new ListingEntry(
                (string) $action->value,
                AdminPath::Action->to($service->value, $version->value, (string) $action->value),
                $action->describe(),
                $action,
            )));
    }

    /**
     * What is listed, in order.
     *
     * @return Collection<ListingEntry>
     */
    public function entries(): Collection
    {
        return $this->entries;
    }

    /**
     * The listing as data: the address it lists, and each entry with its description in the language
     * the listing was asked in.
     *
     * @return mixed
     */
    public function jsonSerialize(): mixed
    {
        $entries = [];

        foreach ($this->entries as $entry) {
            $entries[] = $entry->data($this->language);
        }

        return [ResultKey::Address->value => $this->address, ResultKey::Entries->value => $entries];
    }
}
