<?php

declare(strict_types=1);

namespace App\Domain;

final class ProjectPermissions
{
    public const LABELS = [
        'write'     => 'Tickets erstellen und bearbeiten',
        'comment'   => 'Kommentieren und eigene Kommentare ändern',
        'moderate'  => 'Kommentare anderer bearbeiten',
        'upload'    => 'Anhänge hochladen und entfernen',
        'manage'    => 'Projektdetails bearbeiten',
        'members'   => 'Mitglieder verwalten',
        'structure' => 'Spalten, Swimlanes und Labels verwalten',
    ];

    public static function defaults(string $role): array
    {
        return match ($role) {
            'owner'   => [...array_keys(self::LABELS), 'roles', 'owners', 'archive', 'restore'],
            'manager' => array_keys(self::LABELS),
            'member'  => ['write', 'comment', 'upload'],
            default   => [],
        };
    }
}
