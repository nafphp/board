<?php

declare(strict_types=1);

namespace Naf\Board\Contracts;

/**
 * Custom project roles and the permissions they grant.
 */
interface RoleServiceInterface
{
    public function list(int $project): array;

    public function save(int $project, array $data): void;
}
