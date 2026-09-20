<?php

declare(strict_types=1);

namespace Naf\Board\Domain;

use Naf\Board\Rbac\Project;
use Naf\Rbac\Scope;

/**
 * Something happened, and this is everything the history will ever know about it.
 *
 * It carries a scope -- the same `type:id` naf/rbac grants are held at -- so that
 * "what happened on this board" and "who may read it" are asked in one language.
 * A change inside a project takes that project's scope without being told; one
 * that belongs to the installation says so by having no project, which is what
 * settings, roles and accounts are.
 */
final readonly class Change
{
    /** Where this happened, as it is stored: `project:7`, or empty for the installation. */
    public string $scope;

    /** @param array<string,mixed> $data */
    public function __construct(
        public ?int $projectId,
        public ?int $ticketId,
        public int $actorId,
        public string $type,
        public array $data = [],
    ) {
        $this->scope = $this->projectId === null
            ? (string) Scope::everywhere()
            : (string) Scope::of(Project::SCOPE, $this->projectId);
    }

    /** What changed about the installation itself, outside every board. */
    public static function inInstallation(int $actor, string $type, array $data = []): self
    {
        return new self(null, null, $actor, $type, $data);
    }
}
