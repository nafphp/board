<?php

declare(strict_types=1);

namespace Nafinity\Contracts;

/**
 * The authorized read side of boards, tickets, activity and preferences.
 */
interface BoardQueryInterface
{
    public function projects(): array;

    public function transferTargets(int $exclude): array;

    public function board(int $project, array $query = []): array;

    public function detail(int $project, int $ticket): array;

    public function activity(int $project): array;

    public function preferences(): array;
}
