<?php

declare(strict_types=1);

namespace App\Modules;

use App\Domain\ProjectPermissions;
use Nafinity\Contracts\ExtensionProviderInterface;
use Nafinity\Definition\PermissionDefinition;
use Nafinity\ExtensionContext;

/**
 * Nafinity's own project permissions.
 *
 * The seven role permissions and the four owner actions become ordinary
 * definitions. A contributed permission joins them and can be granted to a
 * custom role, but it is granted to nobody by registering it.
 */
final class CorePermissions implements ExtensionProviderInterface
{
    /** The owner actions that exist beside the grantable permissions. */
    public const array OWNER_ACTIONS = [
        'roles'   => 'Eigene Rollen verwalten',
        'owners'  => 'Weitere Owner ernennen',
        'archive' => 'Projekt archivieren',
        'restore' => 'Projekt wiederherstellen',
    ];

    public function register(ExtensionContext $context): void
    {
        $permissions = $context->permissions();
        $index       = 100;

        foreach (ProjectPermissions::LABELS as $id => $label) {
            $permissions->add(new PermissionDefinition($id, $label, '', $index));
            $index += 100;
        }

        foreach (self::OWNER_ACTIONS as $id => $label) {
            $permissions->add(new PermissionDefinition($id, $label, '', $index, true));
            $index += 100;
        }
    }
}
