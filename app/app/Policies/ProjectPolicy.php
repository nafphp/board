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
        if ($action === 'read') {
            return true;
        }
        if ($scope->project['archived_at'] !== null && $action !== 'restore') {
            return false;
        }

        return match ($action) {
            'write', 'comment', 'upload' => in_array(
                $scope->role,
                ['owner', 'manager', 'member'],
                true,
            ),
            'manage', 'members', 'structure' => in_array($scope->role, ['owner', 'manager'], true),
            'archive', 'restore', 'owners'   => $scope->role === 'owner',
            default                          => false,
        };
    }
}
