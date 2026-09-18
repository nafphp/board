<?php

declare(strict_types=1);

namespace Nafinity\Contracts;

/**
 * Tickets, their versions, their board positions and their links.
 */
interface TicketServiceInterface
{
    public function create(int $project, array $data): int;

    public function update(int $project, int $id, array $data): void;

    public function move(int $project, int $id, array $data): void;

    public function transfer(int $project, int $id, array $data): array;

    public function state(int $project, int $id, array $data): void;

    public function link(int $project, int $id, array $data): void;

    public function resolve(int $project, string $reference): int;

    public function reference(int $project, int $id): string;

    public function ticket(int $project, int $id): array;

    public function board(int $project): array;
}
