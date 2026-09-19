<?php

declare(strict_types=1);

namespace Naf\Board\Policies;

use Naf\Auth\Identity\IdentityInterface;
use Naf\Board\Domain\ProjectScope;

final class ProjectPolicy
{
    public function __invoke(IdentityInterface $user, string $action, ProjectScope $scope): bool
    {
        if ($scope->userId !== $user->getIdentifier()) {
            return false;
        }

        return $scope->allows($action);
    }
}
