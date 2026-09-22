<?php

declare(strict_types=1);

namespace Naf\Board\Services;

use Naf\Auth\Auth;
use Naf\Board\Contracts\AccessInterface;
use Naf\Board\Contracts\ProjectAccessInterface;
use Naf\Board\Domain\Failure;
use Naf\Board\Domain\ProjectScope;
use Naf\ORM\Core\EntityManager;
use PDO;
use Throwable;

use function Naf\I18n\t;

/** @internal */
final class Access implements AccessInterface
{
    public function __construct(
        private PDO $pdo,
        private Auth $auth,
        private EntityManager $entityManager,
        private ProjectAccessInterface $projects,
    ) {
    }

    public function actor(): int
    {
        $this->auth->requireLogin();

        return (int) $this->auth->id();
    }

    public function project(int $id, string $action = 'read', bool $locked = false): ProjectScope
    {
        $scope = $this->projects->forUser($id, $this->actor(), $locked);
        if (!$this->auth->allows($action, $scope)) {
            throw new Failure(t('Du hast für diese Aktion keine Berechtigung.'), 403);
        }

        return $scope;
    }

    public function permissions(int $project, string $role, ?int $customRoleId = null): array
    {
        return $this->projects->permissions($project, $role, $customRoleId);
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
