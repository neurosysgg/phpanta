<?php

declare(strict_types=1);

namespace Phpanta\Http\Api;

use Phpanta\Text\Language;
use Phpanta\Text\Translatable;

/**
 * The ListingEntry class. One thing an admin listing names: a service, a version or an action.
 *
 * Each says where it is and what it is for. An action says more — the method it answers on, whether
 * it writes, whether a browser may run it, and which fields it takes — because that is what a caller
 * needs to make the call, and a listing that left it out would only send them to the source.
 */
final readonly class ListingEntry
{
    /**
     * Constructs an instance of {@link self}.
     *
     * @param string         $name        The segment it is addressed by.
     * @param string         $href        The address of what it names.
     * @param Translatable   $description What it is for.
     * @param ApiAction|null $action      The action it names, or null for a service or a version.
     */
    public function __construct(
        public string       $name,
        public string       $href,
        public Translatable $description,
        public ?ApiAction   $action = null,
    ) {}

    /**
     * The entry as data, its description in $language.
     *
     * @param Language $language
     * @return mixed
     */
    public function data(Language $language): mixed
    {
        $entry = [
            ResultKey::Name->value        => $this->name,
            ResultKey::Href->value        => $this->href,
            ResultKey::Description->value => $this->description->in($language),
        ];

        if ($this->action === null) {
            return $entry;
        }

        return [
            ...$entry,
            ResultKey::Method->value  => $this->action->method()->value,
            ResultKey::Writes->value  => $this->writes(),
            ResultKey::Browser->value => $this->action->fromBrowser(),
            ResultKey::Fields->value  => $this->action->fields()
                ->map(static fn(ActionField $field): string => $field->value)
                ->toValues(),
        ];
    }

    /**
     * Whether the action it names writes — true for one answering on a method that is not a read,
     * which a dry run of it still is not.
     *
     * @return bool
     */
    public function writes(): bool
    {
        return $this->action !== null && !$this->action->method()->isReadOnly();
    }
}
