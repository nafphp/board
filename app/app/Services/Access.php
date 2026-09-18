<?php

declare(strict_types=1);

namespace App\Services;

use App\Domain\Failure;
use App\Domain\ProjectPermissions;
use App\Domain\ProjectScope;
use Naf\Auth\Auth;
use Naf\ORM\Core\EntityManager;
use Nafinity\Contracts\AccessInterface;
use PDO;
use Throwable;

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
            throw new Failure('Projekt nicht gefunden.', 404);
        }
        $customRoleId = $row['custom_role_id'] === null ? null : (int) $row['custom_role_id'];
        $permissions  = $this->permissions($id, $row['role'], $customRoleId);
        $roleName     = ucfirst($row['role']);
        if ($customRoleId !== null) {
            $statement = $this->pdo->prepare('SELECT name FROM project_roles WHERE project_id=? AND id=?');
            $statement->execute([$id, $customRoleId]);
            $roleName = (string) $statement->fetchColumn();
        }
        $scope = new ProjectScope($row, (string) $user, $row['role'], $permissions, $roleName);
        if (!$this->auth->allows($action, $scope)) {
            throw new Failure('Du hast für diese Aktion keine Berechtigung.', 403);
        }

        return $scope;
    }

    /** Resolve rights for both authenticated requests and the background upload worker. */
    public function permissions(int $project, string $role, ?int $customRoleId = null): array
    {
        if ($customRoleId === null) {
            return ProjectPermissions::defaults($role);
        }
        $statement = $this->pdo->prepare('SELECT permission FROM project_role_permissions WHERE project_id=? AND role_id=?');
        $statement->execute([$project, $customRoleId]);

        return array_values(array_intersect($statement->fetchAll(PDO::FETCH_COLUMN), array_keys(ProjectPermissions::LABELS)));
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
