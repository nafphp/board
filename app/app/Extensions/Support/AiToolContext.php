<?php

declare(strict_types=1);

namespace Nafinity\Support;

use App\Domain\ProjectScope;

/**
 * The authorized situation a tool provider is asked in.
 */
final readonly class AiToolContext
{
    /**
     * @param int               $actorId The authenticated user
     * @param ProjectScope|null $scope   Authorized project, or null outside one
     */
    public function __construct(public int $actorId, public ?ProjectScope $scope = null)
    {
    }

    public function projectId(): ?int
    {
        return $this->scope === null ? null : (int) $this->scope->project['id'];
    }
}
