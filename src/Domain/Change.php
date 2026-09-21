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

    /**
     * @param array<string,mixed> $data
     * @param string|null         $scope where this happened, when it is not simply
     *                                   the project named above -- a grant held on
     *                                   every board of a kind has no single project
     */
    public function __construct(
        public ?int $projectId,
        public ?int $ticketId,
        public ?int $actorId,
        public string $type,
        public array $data = [],
        ?string $scope = null,
    ) {
        $this->scope = $scope ?? ($this->projectId === null
            ? (string) Scope::everywhere()
            : (string) Scope::of(Project::SCOPE, $this->projectId));
    }

    /**
     * What changed about the installation itself, outside every board.
     *
     * The actor may be absent. Almost everything recorded here was done by
     * somebody, but not quite everything: a refused sign-in is the work of
     * whoever typed the address, and naming them would mean asserting who they
     * were on the strength of a password that did not match.
     */
    public static function inInstallation(?int $actor, string $type, array $data = []): self
    {
        return new self(null, null, $actor, $type, $data);
    }

    /**
     * Something that happened at a scope somebody else names.
     *
     * The project is passed too where there is one, so an entry can still be
     * joined to its board; a scope like `project:*` has none, and says so.
     *
     * @param array<string,mixed> $data
     */
    public static function at(string $scope, ?int $project, int $actor, string $type, array $data = []): self
    {
        return new self($project, null, $actor, $type, $data, $scope);
    }
}
