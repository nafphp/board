<?php

declare(strict_types=1);

namespace App\Domain;

final readonly class ProjectScope
{
    public function __construct(public array $project, public string $userId, public string $role)
    {
    }
}
