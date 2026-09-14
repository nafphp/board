<?php

declare(strict_types=1);

namespace App\Domain;

final readonly class Change
{
    public function __construct(public int $projectId, public ?int $ticketId, public int $actorId, public string $type, public array $data = [])
    {
    }
}
