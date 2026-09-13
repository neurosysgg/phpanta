<?php

declare(strict_types=1);

namespace Phpanta\Tool\Api;

use JsonException;
use Phpanta\Http\Api\ResultKey;
use Phpanta\Model\Health\HealthFact;
use Phpanta\Model\Health\HealthSection;
use Phpanta\Support\Collection;
use stdClass;

/**
 * The ListingReader class. An admin listing that came back as data, as the text a terminal shows.
 *
 * {@link ResultReader}'s counterpart for what `php tools/api.php` prints when it is given less than
 * a whole address: what the server offers there, one line each. The keys are the server's own
 * {@link ResultKey} cases, and the layout is {@link HealthSection}'s, so a listing reads like every
 * other answer the command prints.
 *
 * **The words are the command's, in English**, like every other line it writes — a listing's
 * descriptions arrive in whatever language the server answered in, and only the markers beside an
 * action's name are this class's own.
 *
 * Anything that is not a listing is null, never an exception, for {@link ResultReader}'s reason.
 */
final readonly class ListingReader
{
    /**
     * The listing $body is, as text, or null where it is not one.
     *
     * @param string $body
     * @return string|null
     */
    public static function text(string $body): ?string
    {
        try {
            $decoded = json_decode($body, false, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        if (!$decoded instanceof stdClass) {
            return null;
        }

        $address = $decoded->{ResultKey::Address->value} ?? null;
        $entries = $decoded->{ResultKey::Entries->value} ?? null;

        if (!is_string($address) || !is_array($entries)) {
            return null;
        }

        $facts = [];

        foreach ($entries as $entry) {
            $fact = self::fact($entry);

            if ($fact === null) {
                return null;
            }

            $facts[] = $fact;
        }

        return HealthSection::document(
            HealthSection::facts($address, new Collection(HealthFact::class)->with(...$facts)),
        );
    }

    /**
     * One entry as a line, or null where it is not one: a service or a version is its description;
     * an action is its method, whether it writes, and its description, then what it takes and
     * whether only this command can run it.
     *
     * @param mixed $entry
     * @return HealthFact|null
     */
    private static function fact(mixed $entry): ?HealthFact
    {
        if (!$entry instanceof stdClass) {
            return null;
        }

        $name        = $entry->{ResultKey::Name->value} ?? null;
        $description = $entry->{ResultKey::Description->value} ?? null;
        $method      = $entry->{ResultKey::Method->value} ?? null;

        if (!is_string($name) || !is_string($description)) {
            return null;
        }

        if ($method === null) {
            return new HealthFact($name, $description);
        }

        $writes  = $entry->{ResultKey::Writes->value} ?? null;
        $browser = $entry->{ResultKey::Browser->value} ?? null;
        $fields  = $entry->{ResultKey::Fields->value} ?? null;

        if (!is_string($method) || !is_bool($writes) || !is_bool($browser) || !is_array($fields)) {
            return null;
        }

        foreach ($fields as $field) {
            if (!is_string($field)) {
                return null;
            }
        }

        return new HealthFact($name, sprintf(
            '%-4s  %-6s  %s%s%s',
            $method,
            $writes ? 'writes' : 'reads',
            $description,
            $fields === [] ? '' : '  (' . implode(', ', $fields) . ')',
            $browser ? '' : '  [CLI only]',
        ));
    }
}
