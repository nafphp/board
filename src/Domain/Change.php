<?php

declare(strict_types=1);

namespace Naf\Board\Domain;

final readonly class Change
{
    public function __construct(
        public int $projectId,
        public ?int $ticketId,
        public int $actorId,
        public string $type,
        public array $data = [],
    ) {
    }
}
