<?php

declare(strict_types=1);

namespace Nafinity\Contracts;

use App\Domain\ProjectScope;

/**
 * Project authorization and the transaction every write runs in.
 */
interface AccessInterface
{
    /** The authenticated user, or a redirect to the login for everyone else. */
    public function actor(): int;

    /**
     * @param int    $id     Project id
     * @param string $action Project action to authorize
     * @param bool   $locked Whether the membership row is read FOR UPDATE
     */
    public function project(int $id, string $action = 'read', bool $locked = false): ProjectScope;

    /**
     * Resolve rights for both authenticated requests and background workers
     *
     * @param int      $project      Project id
     * @param string   $role         Built-in role name
     * @param int|null $customRoleId Custom role, when the membership has one
     *
     * @return list<string>
     */
    public function permissions(int $project, string $role, ?int $customRoleId = null): array;

    /**
     * @param int      $projectId Project id
     * @param string   $action    Project action to authorize
     * @param callable $operation fn(ProjectScope): mixed, run inside the transaction
     */
    public function write(int $projectId, string $action, callable $operation): mixed;
}
