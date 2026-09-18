<?php

declare(strict_types=1);

namespace Example\ExtensionB\Services;

use Nafinity\Contracts\TicketServiceInterface;

/**
 * A decorator around whatever ticket service is bound.
 *
 * It counts accepted writes and forwards everything else untouched — including
 * exceptions, so a rejected change is still rejected and nothing it counted
 * survives a rolled-back transaction, because the count is only taken after the
 * inner call returns.
 */
final class CountingTicketService implements TicketServiceInterface
{
    private int $writes = 0;

    public function __construct(private TicketServiceInterface $inner)
    {
    }

    public function writes(): int
    {
        return $this->writes;
    }

    public function create(int $project, array $data): int
    {
        $id = $this->inner->create($project, $data);
        $this->writes++;

        return $id;
    }

    public function update(int $project, int $id, array $data): void
    {
        $this->inner->update($project, $id, $data);
        $this->writes++;
    }

    public function move(int $project, int $id, array $data): void
    {
        $this->inner->move($project, $id, $data);
        $this->writes++;
    }

    public function transfer(int $project, int $id, array $data): array
    {
        $result = $this->inner->transfer($project, $id, $data);
        $this->writes++;

        return $result;
    }

    public function state(int $project, int $id, array $data): void
    {
        $this->inner->state($project, $id, $data);
        $this->writes++;
    }

    public function link(int $project, int $id, array $data): void
    {
        $this->inner->link($project, $id, $data);
        $this->writes++;
    }

    public function resolve(int $project, string $reference): int
    {
        return $this->inner->resolve($project, $reference);
    }

    public function reference(int $project, int $id): string
    {
        return $this->inner->reference($project, $id);
    }

    public function ticket(int $project, int $id): array
    {
        return $this->inner->ticket($project, $id);
    }

    public function board(int $project): array
    {
        return $this->inner->board($project);
    }
}
