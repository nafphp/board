<?php

declare(strict_types=1);

namespace Nafinity\Definition;

/**
 * A project permission a role can grant.
 *
 * Registering a permission only makes it selectable and storable. It never
 * grants itself to an existing role, not even to owners.
 */
final readonly class PermissionDefinition
{
    /**
     * @param string $id          Permission name as stored in project_role_permissions
     * @param string $label       Short label shown in the role editor
     * @param string $description Optional explanation for the role editor
     * @param int    $index       Sort value, ascending
     * @param bool   $ownerOnly   Whether only the project owner may use it
     */
    public function __construct(
        public string $id,
        public string $label,
        public string $description = '',
        public int $index = 100,
        public bool $ownerOnly = false,
    ) {
    }
}
