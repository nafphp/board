<?php

declare(strict_types=1);

namespace App\Modules;

use Nafinity\Contracts\ExtensionProviderInterface;
use Nafinity\Definition\TicketFieldDefinition;
use Nafinity\Definition\UiContribution;
use Nafinity\ExtensionContext;
use Nafinity\Support\UiContext;

/**
 * The ticket's own panels and fields.
 *
 * Title, description and comments are deliberately absent: they are fixed parts
 * of a ticket, not entries in a list something could remove. Everything else on
 * the right-hand side is a definition, in the order it has always had.
 *
 * Core fields keep their existing services and partials — a column is still
 * moved, assignees are still a pivot, time is still the timer. Declaring them
 * here makes them sortable and replaceable, never differently validated.
 */
final class CoreTicket implements ExtensionProviderInterface
{
    /** Group id to the panel that shows it, with the panel's own order. */
    public const array PANELS = [
        'primary'     => ['core.ticket.primary', 'Status', 100],
        'details'     => ['core.ticket.details', 'Details', 200],
        'planning'    => ['core.ticket.planning', 'Planung & Zeit', 300],
        'information' => ['core.ticket.information', 'Informationen', 400],
    ];

    /** Group to its fields, in the order the ticket has always shown them. */
    private const array FIELDS = [
        'primary' => ['column_id' => 'Spalte', 'assignee_ids' => 'Zuständig'],
        'details' => [
            'priority'    => 'Priorität',
            'swimlane_id' => 'Swimlane',
            'label_ids'   => 'Labels',
            'color'       => 'Farbe',
        ],
        'planning' => [
            'estimate_points'  => 'Schätzung',
            'start_date'       => 'Start',
            'due_date'         => 'Fällig',
            'estimate_minutes' => 'Geschätzte Zeit',
            'spent_minutes'    => 'Erfasste Zeit',
        ],
        'information' => [
            'creator'     => 'Erstellt von',
            'project'     => 'Projekt',
            'created_at'  => 'Erstellt',
            'updated_at'  => 'Geändert',
            'closed_at'   => 'Geschlossen',
            'archived_at' => 'Archiviert',
            'version'     => 'Version',
        ],
    ];

    public function register(ExtensionContext $context): void
    {
        $ui = $context->ui();

        foreach (self::PANELS as $group => [$id, $label, $index]) {
            $ui->add(new UiContribution(
                $id,
                'ticket.sidebar.panels',
                'ticket/panels/' . $group,
                $index,
                null,
                'read',
                [UiContext::MODE_DETAIL, UiContext::MODE_CREATE],
            ));
        }

        $fields = $context->ticketFields();

        foreach (self::FIELDS as $group => $entries) {
            $index = 100;

            foreach ($entries as $key => $label) {
                $fields->add(new TicketFieldDefinition(
                    key: $key,
                    label: $label,
                    type: 'text',
                    group: $group,
                    index: $index,
                    readOnly: $group === 'information',
                    adapter: $key,
                    showOnCreate: $group !== 'information',
                ));
                $index += 100;
            }
        }
    }

    /**
     * Group ids a ticket field may point at right now
     *
     * The four built-in groups, plus one for every registered sidebar panel: a
     * plugin registers a panel and names it as the group of its own fields.
     *
     * @param ExtensionContext $context The booting context
     *
     * @return list<string>
     */
    public static function groups(ExtensionContext $context): array
    {
        $panels = [];

        foreach ($context->ui()->all() as $id => $contribution) {
            if ($contribution->slot === 'ticket.sidebar.panels') {
                $panels[] = $id;
            }
        }

        return [...array_keys(self::PANELS), ...$panels];
    }
}
