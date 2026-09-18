<?php

declare(strict_types=1);

namespace App\Support;

use function Naf\I18n\t;

final class ActivityLabel
{
    public static function for(string $type): string
    {
        return t(
            [
                'project.created'         => 'Projekt erstellt',
                'project.updated'         => 'Projekt bearbeitet',
                'project.archived'        => 'Projekt archiviert',
                'project.restored'        => 'Projekt wiederhergestellt',
                'project.member_changed'  => 'Mitgliedschaft geändert',
                'board.structure_changed' => 'Board-Struktur geändert',
                'ticket.created'          => 'Ticket erstellt',
                'ticket.updated'          => 'Ticket bearbeitet',
                'ticket.moved'            => 'Ticket verschoben',
                'ticket.transferred'      => 'Ticket aus einem anderen Projekt verschoben',
                'ticket.linked'           => 'Ticket verknüpft',
                'ticket.unlinked'         => 'Ticketverknüpfung entfernt',
                'ticket.close'            => 'Ticket geschlossen',
                'ticket.reopen'           => 'Ticket wieder geöffnet',
                'ticket.archive'          => 'Ticket archiviert',
                'ticket.restore'          => 'Ticket wiederhergestellt',
                'comment.created'         => 'Kommentar erstellt',
                'comment.updated'         => 'Kommentar bearbeitet',
                'comment.deleted'         => 'Kommentar gelöscht',
                'timer.recorded'          => 'Zeit erfasst',
                'attachment.added'        => 'Anhang hinzugefügt',
                'attachment.deleted'      => 'Anhang gelöscht',
            ][$type] ?? 'Projekt aktualisiert',
        );
    }
}
