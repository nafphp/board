<?php

declare(strict_types=1);

namespace Naf\Board\Modules;

use Naf\Board\Contracts\ExtensionProviderInterface;
use Naf\Board\Definition\ActivityType;
use Naf\Board\ExtensionContext;

/**
 * Readable sentences for the change types Nafinity records itself.
 *
 * A type nobody registered is shown under its own escaped name instead of
 * pretending the project was updated.
 *
 * @internal
 */
final class CoreActivity implements ExtensionProviderInterface
{
    /** Event type to sentence and icon. */
    /*
     * Every symbol here is one the bundled Material Symbols subset actually
     * carries. A name outside it is drawn as its own ligature text, so a log of
     * created tickets reads "TASK" down the left edge -- which is what happened
     * the first time these were rendered. The list of included glyphs is in
     * src/Resources/public/assets/vendor/material-symbols/README.md, together
     * with how to fetch a wider one.
     */
    private const array TYPES = [
        'project.created'         => ['Projekt erstellt', 'add'],
        'project.updated'         => ['Projekt bearbeitet', 'edit'],
        'project.archived'        => ['Projekt archiviert', 'folder_open'],
        'project.restored'        => ['Projekt wiederhergestellt', 'refresh'],
        'project.member_changed'  => ['Mitgliedschaft geändert', 'group'],
        'project.role_saved'      => ['Rolle gespeichert', 'shield'],
        'project.role_deleted'    => ['Rolle gelöscht', 'shield'],
        'project.settings_saved'  => ['Projekteinstellungen gespeichert', 'tune'],
        'board.structure_changed' => ['Board-Struktur geändert', 'view_kanban'],
        'settings.changed'        => ['Einstellungen geändert', 'tune'],
        'rbac.granted'            => ['Rollen geändert', 'shield'],
        'ticket.created'          => ['Ticket erstellt', 'add'],
        'ticket.updated'          => ['Ticket bearbeitet', 'edit'],
        'ticket.moved'            => ['Ticket verschoben', 'swap_horiz'],
        'ticket.transferred'      => ['Ticket aus einem anderen Projekt verschoben', 'drive_file_move'],
        'ticket.linked'           => ['Ticket verknüpft', 'link'],
        'ticket.unlinked'         => ['Ticketverknüpfung entfernt', 'remove'],
        'ticket.close'            => ['Ticket geschlossen', 'check_circle'],
        'ticket.reopen'           => ['Ticket wieder geöffnet', 'refresh'],
        'ticket.archive'          => ['Ticket archiviert', 'folder_open'],
        'ticket.restore'          => ['Ticket wiederhergestellt', 'refresh'],
        'comment.created'         => ['Kommentar erstellt', 'comment'],
        'comment.updated'         => ['Kommentar bearbeitet', 'comment'],
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
