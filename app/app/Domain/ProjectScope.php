<?php

declare(strict_types=1);

namespace App\Domain;

final readonly class ProjectScope
{
    public function __construct(
        public array $project,
        public string $userId,
        public string $role,
        public ?array $permissions = null,
        public ?string $roleName = null,
    ) {
    }

    public function allows(string $action): bool
    {
        if ($action === 'read') {
            return true;
        }
        if ($this->project['archived_at'] !== null && $action !== 'restore') {
            return false;
        }

        return in_array($action, $this->permissions ?? ProjectPermissions::defaults($this->role), true);
    }
}
