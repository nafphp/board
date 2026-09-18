<?php

declare(strict_types=1);

namespace Nafinity\Contracts;

/**
 * Projects, their members, their structure and their archive state.
 */
interface ProjectServiceInterface
{
    public function create(array $data): int;

    public function update(int $project, array $data): void;

    public function offScaleEstimates(int $project, mixed $scale): int;

    public function archive(int $project, bool $archived): void;

    public function member(int $project, array $data): void;

    public function structure(int $project, array $data): void;

    public function changed(int $project, string $type, array $data = []): void;

    public function color(mixed $color): string;
}
