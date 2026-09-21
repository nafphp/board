<?php

declare(strict_types=1);

namespace Naf\Board\Rbac;

use Naf\Board\Domain\ProjectPermissions;
use Naf\Board\Modules\CorePermissions;
use Naf\Rbac\Definition\PermissionDefinition;
use Naf\Rbac\Definition\RoleDefinition;
use Naf\Rbac\Registry\PermissionRegistry;
use Naf\Rbac\Registry\RoleRegistry;

/**
 * What somebody may do inside one board.
 *
 * The same names the board has always stored in project_role_permissions --
 * "write", "manage", "structure". They are unprefixed where the installation's
 * own permissions are not, which is a deliberate stop rather than a style: the
 * names appear in 36 places across services and views, and in every grant an
 * installation has stored. Renaming them is a migration of its own and worth
 * doing separately from moving where they live.
 *
 * The four roles are the ones the board has always had. Their permission lists
 * come from ProjectPermissions, so the declaration cannot drift from what the
 * old resolution answered.
 *
 * @internal
 */
final class Project
{
    public const string SCOPE = 'project';

    /** @var array<string, string> role key to the label somebody sees */
    public const array ROLES = [
        'owner'   => 'Owner',
        'manager' => 'Manager',
        'member'  => 'Member',
        'viewer'  => 'Viewer',
    ];

    public static function declare(PermissionRegistry $permissions, RoleRegistry $roles): void
    {
        $index = 10;
        foreach (ProjectPermissions::LABELS as $name => $label) {
            $permissions->add(new PermissionDefinition($name, $label, '', 'Im Board', $index));
            $index += 10;
        }

        foreach (CorePermissions::OWNER_ACTIONS as $name => $label) {
            $permissions->add(
                new PermissionDefinition($name, $label, 'Gehört dem Owner eines Boards.', 'Board verwalten', $index),
            );
            $index += 10;
        }

        $order = 10;
        foreach (self::ROLES as $name => $label) {
            $roles->add(new RoleDefinition(
                $name,
                $label,
                '',
                ProjectPermissions::defaults($name),
                self::SCOPE,
                $order,
            ));
            $order += 10;
        }
    }
}
