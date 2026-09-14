<?php

declare(strict_types=1);

namespace App\Services;

use App\Domain\{Failure,ProjectScope};
use Naf\Auth\Auth;
use Naf\ORM\Core\EntityManager;
use PDO;

final class Access
{
    public function __construct(private PDO $pdo, private Auth $auth, private EntityManager $em)
    {
    }
    public function actor(): int
    {
        $this->auth->requireLogin();
        return (int)$this->auth->id();
    }
    public function project(int $id, string $action = 'read', bool $locked = false): ProjectScope
    {
        $user = $this->actor();
        $stmt = $this->pdo->prepare('SELECT p.*, m.role FROM projects p JOIN project_members m ON m.project_id=p.id WHERE p.id=? AND m.user_id=? AND m.active=1'.($locked ? ' FOR UPDATE' : ''));
        $stmt->execute([$id,$user]);
        $row = $stmt->fetch();
        if (!$row) {
            throw new Failure('Projekt nicht gefunden.', 404);
        }
        $scope = new ProjectScope($row, (string)$user, $row['role']);
        if (!$this->auth->allows($action, $scope)) {
            throw new Failure('Du hast für diese Aktion keine Berechtigung.', 403);
        }
        return $scope;
    }
    public function write(int $projectId, string $action, callable $operation): mixed
    {
        $this->em->begin();
        try {
            $lock = $this->pdo->prepare('SELECT id FROM projects WHERE id=? FOR UPDATE');
            $lock->execute([$projectId]);
            $scope = $this->project($projectId, $action, true);
            $result = $operation($scope);
            $this->em->commit();
            return $result;
        } catch (\Throwable $e) {
            $this->em->rollback();
            throw $e;
        }
    }
}
