<?php

declare(strict_types=1);

namespace Nafinity\Contracts;

/**
 * Running work timers and the spent minutes they produce.
 */
interface TimerServiceInterface
{
    public function act(int $project, int $ticket, array $data): array;

    public function state(int $project, int $ticket, ?int $actor = null): array;

    public function running(?int $actor = null): ?array;

    public function runningIn(int $project, ?int $actor = null): array;

    public function clear(int $project, int $ticket): int;
}
