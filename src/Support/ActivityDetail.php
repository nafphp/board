<?php

declare(strict_types=1);

namespace Naf\Board\Support;

use function Naf\I18n\t;

/**
 * What actually changed, from what the change recorded at the time.
 *
 * The history said who touched which ticket and stopped there, while the row
 * underneath carried the answer all along: which fields were written, which
 * column a card came from and went to, how many minutes were booked. None of it
 * was shown, so the log could tell you that something happened and never what.
 *
 * A payload it cannot read gives nothing back rather than a guess -- these rows
 * were written by whatever version of the application was running that day, and
 * one of them will always be older than this file.
 *
 * @internal
 */
final class ActivityDetail
{
    /** Ticket columns under the names the people editing them see. */
    private const array FIELDS = [
        'title'            => 'Titel',
        'description'      => 'Beschreibung',
        'priority'         => 'Priorität',
        'color'            => 'Akzentfarbe',
        'status'           => 'Status',
        'due_date'         => 'Fälligkeit',
        'start_date'       => 'Start',
        'estimate_points'  => 'Story-Points',
        'estimate_minutes' => 'Schätzung',
        'spent_minutes'    => 'Erfasste Zeit',
        'column_id'        => 'Spalte',
        'swimlane_id'      => 'Swimlane',
    ];

    /**
     * Rich text is stored twice, as markup and as the text inside it, and both
     * are written together. Naming it twice in a log would describe the storage
     * rather than the edit.
     */
    private const array TWINS = ['description_html'];

    /**
     * The stored field names whose label reads like this term.
     *
     * A payload holds `description`; the page shows "Beschreibung". Somebody
     * searching the log types what the page showed them, so the search asks here
     * what that would have been stored as. The mapping is the same one the
     * rendering uses, which is why the two cannot drift apart.
     *
     * @return list<string>
     */
    public static function storedAs(string $term): array
    {
        $term = mb_strtolower(trim($term));
        if ($term === '') {
            return [];
        }

        $names = [];
        foreach (self::FIELDS as $field => $label) {
            /*
             * Both spellings: the label as it is written here and as this
             * installation translates it. The translator is set while a page is
             * rendered, and a search runs before that -- so asking only the
             * translation would make the result depend on when it was asked,
             * which is not something a search box should do.
             */
            foreach ([$label, t($label)] as $candidate) {
                if (str_contains(mb_strtolower($candidate), $term)) {
                    $names[] = $field;
                    break;
                }
            }
        }

        return $names;
    }

    /** @param array<string,mixed> $item one activity row, payload included */
    public static function of(array $item): string
    {
        $payload = json_decode((string) ($item['payload'] ?? ''), true);
        if (!is_array($payload)) {
            return '';
        }

        return match ((string) $item['event_type']) {
            'ticket.created', 'project.created' => self::quoted($payload['title'] ?? $payload['name'] ?? null),
            // The entry outlives the ticket, so it carries what the ticket was
            // called: the link beside it is gone with the row it pointed at.
            'ticket.deleted' => self::deleted($payload),
            'ticket.updated' => self::fields($payload),
            // Which switches, never what they were set to: settings hold the
            // passwords and keys a log must not become a second copy of.
            'settings.changed'        => self::settings($payload),
            'rbac.granted'            => self::grant($payload),
            'ticket.moved'            => self::move($payload),
            'timer.recorded'          => self::minutes($payload['minutes'] ?? null),
            'attachment.added'        => self::quoted($payload['name'] ?? null),
            'project.member_changed'  => self::text($payload['role'] ?? null),
            'board.structure_changed' => self::text($payload['kind'] ?? null),
            default                   => '',
        };
    }

    /** @param array<string,mixed> $payload */
    private static function fields(array $payload): string
    {
        $names = [];
        foreach ([...(array) ($payload['fields'] ?? []), ...(array) ($payload['metadata'] ?? [])] as $field) {
            if (!is_string($field) || in_array($field, self::TWINS, true)) {
                continue;
            }
            // A field this version has no name for keeps its own, which is still
            // more than "bearbeitet".
            $names[] = t(self::FIELDS[$field] ?? $field);
        }

        return implode(', ', array_unique($names));
    }

    /**
     * Who, and from what to what.
     *
     * Both ends, because "Rollen geändert" without them is the entry people
     * open this log for and do not find. The roles were recorded by label when
     * the grant was made, so this still reads after one was renamed or deleted.
     *
     * @param array<string,mixed> $payload
     */
    private static function grant(array $payload): string
    {
        $person = self::text($payload['person'] ?? null);
        $moved  = self::roles($payload['before'] ?? []) . ' → ' . self::roles($payload['after'] ?? []);

        return $person === '' ? $moved : $person . ': ' . $moved;
    }

    private static function roles(mixed $roles): string
    {
        $named = array_filter(array_map(self::text(...), (array) $roles));

        return $named === [] ? t('keine') : implode(', ', $named);
    }

    /** @param array<string,mixed> $payload */
    private static function deleted(array $payload): string
    {
        $key   = self::text($payload['key'] ?? null);
        $title = self::quoted($payload['title'] ?? null);

        return trim($key . ' ' . $title);
    }

    /** @param array<string,mixed> $payload */
    private static function settings(array $payload): string
    {
        $touched = [
            ...array_map(self::text(...), (array) ($payload['keys'] ?? [])),
            ...array_map(
                static fn(mixed $key): string => self::text($key) . ' ' . t('zurückgesetzt'),
                (array) ($payload['reset'] ?? []),
            ),
        ];

        return implode(', ', array_filter($touched));
    }

    /** @param array<string,mixed> $payload */
    private static function move(array $payload): string
    {
        $to = self::text($payload['column'] ?? null);
        // Only moves recorded since this was written know where they came from;
        // an older row names its destination and nothing else.
        $from = self::text($payload['from_column'] ?? null);

        if ($to === '') {
            return '';
        }

        return $from === '' ? '→ ' . $to : $from . ' → ' . $to;
    }

    private static function minutes(mixed $value): string
    {
        return is_numeric($value) ? Duration::format((int) $value) : '';
    }

    private static function quoted(mixed $value): string
    {
        $text = self::text($value);

        return $text === '' ? '' : '„' . $text . '“';
    }

    private static function text(mixed $value): string
    {
        return is_string($value) || is_numeric($value) ? trim((string) $value) : '';
    }
}
