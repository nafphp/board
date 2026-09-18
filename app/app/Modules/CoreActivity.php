<?php

declare(strict_types=1);

namespace App\Modules;

use Nafinity\Contracts\ExtensionProviderInterface;
use Nafinity\Definition\ActivityType;
use Nafinity\ExtensionContext;

/**
 * Readable sentences for the change types Nafinity records itself.
 *
 * A type nobody registered is shown under its own escaped name instead of
 * pretending the project was updated.
 */
final class CoreActivity implements ExtensionProviderInterface
{
    /** Event type to sentence and icon. */
    private const array TYPES = [
        'project.created'         => ['Projekt erstellt', 'add_circle'],
        'project.updated'         => ['Projekt bearbeitet', 'edit'],
        'project.archived'        => ['Projekt archiviert', 'inventory_2'],
        'project.restored'        => ['Projekt wiederhergestellt', 'restore'],
        'project.member_changed'  => ['Mitgliedschaft geändert', 'group'],
        'project.role_saved'      => ['Rolle gespeichert', 'admin_panel_settings'],
        'project.role_deleted'    => ['Rolle gelöscht', 'admin_panel_settings'],
        'project.settings_saved'  => ['Projekteinstellungen gespeichert', 'tune'],
        'board.structure_changed' => ['Board-Struktur geändert', 'view_kanban'],
        'ticket.created'          => ['Ticket erstellt', 'add_task'],
        'ticket.updated'          => ['Ticket bearbeitet', 'edit_note'],
        'ticket.moved'            => ['Ticket verschoben', 'swap_horiz'],
        'ticket.transferred'      => ['Ticket aus einem anderen Projekt verschoben', 'move_down'],
        'ticket.linked'           => ['Ticket verknüpft', 'link'],
        'ticket.unlinked'         => ['Ticketverknüpfung entfernt', 'link_off'],
        'ticket.close'            => ['Ticket geschlossen', 'task_alt'],
        'ticket.reopen'           => ['Ticket wieder geöffnet', 'restart_alt'],
        'ticket.archive'          => ['Ticket archiviert', 'inventory_2'],
        'ticket.restore'          => ['Ticket wiederhergestellt', 'restore'],
        'comment.created'         => ['Kommentar erstellt', 'chat'],
        'comment.updated'         => ['Kommentar bearbeitet', 'chat'],
        'comment.deleted'         => ['Kommentar gelöscht', 'chat_bubble'],
        'timer.recorded'          => ['Zeit erfasst', 'timer'],
        'attachment.added'        => ['Anhang hinzugefügt', 'attach_file'],
        'attachment.deleted'      => ['Anhang gelöscht', 'delete'],
    ];

    public function register(ExtensionContext $context): void
    {
        $types = $context->activityTypes();
        $index = 100;

        foreach (self::TYPES as $id => [$label, $icon]) {
            $types->add(new ActivityType($id, $label, $icon, $index));
            $index += 100;
        }
    }
}
