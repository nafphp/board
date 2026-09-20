<?php

declare(strict_types=1);

namespace Naf\Board\Services;

use Naf\Auth\Auth;
use Naf\Board\Contracts\AccessInterface;
use Naf\Board\Domain\Failure;
use Naf\Board\Domain\ProjectPermissions;
use Naf\Board\Domain\ProjectScope;
use Naf\Board\Rbac\Installation;
use Naf\Board\Rbac\Project;
use Naf\ORM\Core\EntityManager;
use Naf\Rbac\Scope;
use PDO;
use Throwable;

use function Naf\Board\extensions;
use function Naf\I18n\t;
use function Naf\Rbac\rbac;

/** @internal */
final class Access implements AccessInterface
{
    public function __construct(private PDO $pdo, private Auth $auth, private EntityManager $entityManager)
    {
    }

    public function actor(): int
    {
        $this->auth->requireLogin();

        return (int) $this->auth->id();
    }

    public function project(int $id, string $action = 'read', bool $locked = false): ProjectScope
    {
        $user      = $this->actor();
        $statement = $this->pdo->prepare(
            <<<'SQL'
            SELECT p.*,
                   m.role,
                   m.custom_role_id
            FROM projects p
            JOIN project_members m ON m.project_id = p.id
            WHERE p.id = ?
                AND m.user_id = ?
                AND m.active = 1
            SQL
                . ($locked ? ' FOR UPDATE' : ''),
        );
        $statement->execute([$id, $user]);
        $row = $statement->fetch();
        if (!$row) {
            $row = $this->asAdministrator($id, $user, $locked);
        }
        $customRoleId = $row['custom_role_id'] === null ? null : (int) $row['custom_role_id'];

        /*
         * What they may do comes from naf/rbac, scoped to this board. The row
         * above still decides whether they are in the project at all -- that is
         * membership, and half the schema points at it -- but it no longer says
         * what being in it means.
         *
         * A grant held installation-wide reaches in here too, which is how an
         * administrator's rights apply inside a board.
         */
        $permissions = rbac()->permissionsOf($user, Scope::of(Project::SCOPE, $id));

        // A role somebody made for this board still lives in project_roles, so
        // its grants are added here. The seam disappears when that half moves;
        // until then both answers count, and neither can take the other away.
        if ($customRoleId !== null) {
            $permissions = array_values(array_unique(
                [...$permissions, ...$this->permissions($id, $row['role'], $customRoleId)],
            ));
        }
        // No membership means no membership role to name; what such a person is
        // doing here is administering, so that is what the interface says.
        $roleName = $row['role'] === '' ? Installation::ADMIN_ROLE_NAME : ucfirst($row['role']);
        if ($customRoleId !== null) {
            $statement = $this->pdo->prepare('SELECT name FROM project_roles WHERE project_id=? AND id=?');
            $statement->execute([$id, $customRoleId]);
            $roleName = (string) $statement->fetchColumn();
        }
        $scope = new ProjectScope($row, (string) $user, $row['role'], $permissions, $roleName);
        if (!$this->auth->allows($action, $scope)) {
            throw new Failure(t('Du hast für diese Aktion keine Berechtigung.'), 403);
        }

        return $scope;
    }

    /**
     * The project row for somebody who administers every board.
     *
     * Membership is how people reach a board, and nothing here changes that: a
     * person who is not a member and does not administer every board gets the
     * same 404 as before, which does not say whether the project exists.
     *
     * What it does is honour `projects.administer`, whose whole description is
     * "auch ohne Mitgliedschaft" and which until now was declared and never
     * asked. The row is synthesised rather than written: an administrator
     * looking at a board does not become a member of it, and closing the tab
     * leaves no trace in project_members.
     *
     * @return array<string, mixed>
     */
    private function asAdministrator(int $id, int $user, bool $locked): array
    {
        if (!rbac()->allows($user, Installation::ADMIN_PROJECTS)) {
            throw new Failure(t('Projekt nicht gefunden.'), 404);
        }

        $statement = $this->pdo->prepare(
            'SELECT * FROM projects WHERE id = ?' . ($locked ? ' FOR UPDATE' : ''),
        );
        $statement->execute([$id]);
        $project = $statement->fetch();
        if (!$project) {
            throw new Failure(t('Projekt nicht gefunden.'), 404);
        }

        /*
         * The role is empty on purpose. It is the membership role, and there is
         * no membership -- ProjectPermissions::defaults('') answers nothing, so
         * a missing rbac grant cannot fall back to a project role nobody gave.
         * What this person may do comes from their installation-wide grants.
         */
        return [...$project, 'role' => '', 'custom_role_id' => null];
    }

    /**
     * Resolve rights for both authenticated requests and the background upload worker.
     *
     * A stored grant counts once a definition for it exists, so a plugin's own
     * permission works as soon as the plugin is installed. A grant whose plugin
     * is gone stays in the database and simply does not authorize anything.
     */
    public function permissions(int $project, string $role, ?int $customRoleId = null): array
    {
        if ($customRoleId === null) {
            return ProjectPermissions::defaults($role);
        }
        $statement = $this->pdo->prepare('SELECT permission FROM project_role_permissions WHERE project_id=? AND role_id=?');
        $statement->execute([$project, $customRoleId]);

        // Before the extension pass has run — in a bare host, a unit test, the
        // audit probe — nothing is registered yet. Falling back to the built-in
        // names keeps ProjectPermissions the compatible access it has always
        // been; a contributed permission still needs its definition.
        $available = extensions()->permissions()->names()
            ?: array_keys(ProjectPermissions::LABELS);

        return array_values(array_intersect($statement->fetchAll(PDO::FETCH_COLUMN), $available));
    }

    public function write(int $projectId, string $action, callable $operation): mixed
    {
        $this->entityManager->begin();

        try {
            $lock = $this->pdo->prepare('SELECT id FROM projects WHERE id=? FOR UPDATE');
            $lock->execute([$projectId]);
            $scope  = $this->project($projectId, $action, true);
            $result = $operation($scope);
            $this->entityManager->commit();

            return $result;
        } catch (Throwable $exception) {
            $this->entityManager->rollback();
            throw $exception;
        }
    }
}
