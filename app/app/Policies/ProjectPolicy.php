<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\ProjectScope;
use Naf\Auth\Identity\IdentityInterface;

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
