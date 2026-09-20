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

    /** @param array<string,mixed> $item one activity row, payload included */
    public static function of(array $item): string
    {
        $payload = json_decode((string) ($item['payload'] ?? ''), true);
        if (!is_array($payload)) {
            return '';
        }

        return match ((string) $item['event_type']) {
            'ticket.created', 'project.created' => self::quoted($payload['title'] ?? $payload['name'] ?? null),
            'ticket.updated'                    => self::fields($payload),
            // Which switches, never what they were set to: settings hold the
            // passwords and keys a log must not become a second copy of.
            'settings.changed'        => self::settings($payload),
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
