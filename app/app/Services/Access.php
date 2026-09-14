<?php

declare(strict_types=1);

namespace App\Services;

use App\Domain\Failure;
use App\Domain\ProjectScope;
use Naf\Auth\Auth;
use Naf\ORM\Core\EntityManager;
use PDO;
use Throwable;

final class Access
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
                   m.role
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
        $scope = new ProjectScope($row, (string) $user, $row['role']);
        if (!$this->auth->allows($action, $scope)) {
            throw new Failure('Du hast für diese Aktion keine Berechtigung.', 403);
        }

        return $scope;
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
