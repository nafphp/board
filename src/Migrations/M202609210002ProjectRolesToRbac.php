<?php

declare(strict_types=1);

namespace Naf\Board\Migrations;

use Naf\Database\Core\AbstractMigration;
use PDO;

/**
 * Move who may do what inside a board into naf/rbac.
 *
 * Membership stays where it is: project_members is what half the schema points
 * at, and "is this person in this project" is a different question from "what
 * may they do there". Only the second one moves.
 *
 * The role names and their permissions are written out here rather than read
 * from ProjectPermissions. A migration is a record of what happened on a
 * particular day, and one that asks today's code what it meant then will answer
 * differently the day that code changes -- which is the day nobody is looking.
 *
 * @internal
 */
final class M202609210002ProjectRolesToRbac extends AbstractMigration
{
    /** What each built-in role granted at the time of this migration. */
    private const array ROLES = [
        'owner' => [
            'label'       => 'Owner',
            'permissions' => [
                'write', 'comment', 'moderate', 'upload', 'manage', 'members', 'structure',
                'roles', 'owners', 'archive', 'restore',
            ],
        ],
        'manager' => [
            'label'       => 'Manager',
            'permissions' => ['write', 'comment', 'moderate', 'upload', 'manage', 'members', 'structure'],
        ],
        'member' => [
            'label'       => 'Member',
            'permissions' => ['write', 'comment', 'upload'],
        ],
        'viewer' => ['label' => 'Viewer', 'permissions' => []],
    ];

    public function up(PDO $connection): void
    {
        foreach (self::ROLES as $name => $role) {
            $this->ensureRole($connection, $name, $role['label'], $role['permissions']);
        }

        $members = $connection->query(
            'SELECT project_id, user_id, role, custom_role_id FROM project_members WHERE active = 1',
        )->fetchAll(PDO::FETCH_ASSOC);

        $grant = $connection->prepare(
            'INSERT INTO rbac_user_roles(user_id, role_id, scope) VALUES(?,?,?)',
        );

        foreach ($members as $member) {
            $scope = 'project:' . (int) $member['project_id'];
            $role  = $member['custom_role_id'] === null
                ? $this->idOf($connection, (string) $member['role'])
                : $this->migratedCustomRole($connection, (int) $member['project_id'], (int) $member['custom_role_id']);

            if ($role === null || $this->alreadyGranted($connection, (int) $member['user_id'], $role, $scope)) {
                continue;
            }

            $grant->execute([(int) $member['user_id'], $role, $scope]);
        }
    }

    /**
     * The grants go; the roles stay.
     *
     * Dropping roles that other things may since have been granted would take
     * more away than this added, and a migration down is not the place to guess
     * which of them were only ever ours.
     */
    public function down(PDO $connection): void
    {
        $connection->exec("DELETE FROM rbac_user_roles WHERE scope LIKE 'project:%'");
    }

    /** @param list<string> $permissions */
    private function ensureRole(PDO $connection, string $name, string $label, array $permissions): void
    {
        if ($this->idOf($connection, $name) !== null) {
            return;
        }

        $connection
            ->prepare(
                'INSERT INTO rbac_roles(name, scope_type, label, description, system, position)'
                . " VALUES(?, 'project', ?, '', 1, 100)",
            )
            ->execute([$name, $label]);

        $id     = $this->idOf($connection, $name);
        $insert = $connection->prepare(
            'INSERT INTO rbac_role_permissions(role_id, permission) VALUES(?,?)',
        );
        foreach ($permissions as $permission) {
            $insert->execute([$id, $permission]);
        }
    }

    /**
     * A role somebody made for one board becomes one of the installation's own.
     *
     * Its name says where it came from, because two boards may each have had a
     * "Redaktion" and they were not the same thing. What it grants is copied
     * exactly: this migration must not change anybody's access, only where it
     * is written down.
     */
    private function migratedCustomRole(PDO $connection, int $project, int $customRole): ?int
    {
        $statement = $connection->prepare(
            'SELECT r.name, p.name AS project FROM project_roles r JOIN projects p ON p.id = r.project_id'
            . ' WHERE r.project_id = ? AND r.id = ?',
        );
        $statement->execute([$project, $customRole]);
        $found = $statement->fetch(PDO::FETCH_ASSOC);

        if (!$found) {
            return null;
        }

        $name = sprintf('project-%d-role-%d', $project, $customRole);
        if (null === $id = $this->idOf($connection, $name)) {
            $permissions = $connection->prepare(
                'SELECT permission FROM project_role_permissions WHERE project_id = ? AND role_id = ?',
            );
            $permissions->execute([$project, $customRole]);

            $this->ensureRole(
                $connection,
                $name,
                sprintf('%s (%s)', $found['name'], $found['project']),
                $permissions->fetchAll(PDO::FETCH_COLUMN),
            );

            // Not a role the host declares, so it stays deletable.
            $connection->prepare('UPDATE rbac_roles SET system = 0 WHERE name = ?')->execute([$name]);
            $id = $this->idOf($connection, $name);
        }

        return $id;
    }

    private function idOf(PDO $connection, string $name): ?int
    {
        $statement = $connection->prepare('SELECT id FROM rbac_roles WHERE name = ?');
        $statement->execute([$name]);
        $id = $statement->fetchColumn();

        return $id === false ? null : (int) $id;
    }

    private function alreadyGranted(PDO $connection, int $user, int $role, string $scope): bool
    {
        $statement = $connection->prepare(
            'SELECT COUNT(*) FROM rbac_user_roles WHERE user_id = ? AND role_id = ? AND scope = ?',
        );
        $statement->execute([$user, $role, $scope]);

        return (int) $statement->fetchColumn() > 0;
    }
}
