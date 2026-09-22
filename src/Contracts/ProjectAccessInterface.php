<?php

declare(strict_types=1);

namespace Naf\Board\Contracts;

use Naf\Board\Domain\ProjectScope;

/** Shared project rights for HTTP requests and trusted background jobs. */
interface ProjectAccessInterface
{
    /**
     * Resolve an active user's membership and effective RBAC grants, independent
     * of the current session. Throws Failure for an inactive or inaccessible user.
     * Callers must also check the requested action with ProjectScope::allows().
     */
    public function forUser(int $id, int $user, bool $locked = false): ProjectScope;

    /** @return list<string> Configured role grants, not a user's effective grants. */
    public function permissions(int $project, string $role, ?int $customRoleId = null): array;
}
