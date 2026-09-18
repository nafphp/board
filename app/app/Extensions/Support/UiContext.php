<?php

declare(strict_types=1);

namespace Nafinity\Support;

use App\Domain\ProjectScope;

/**
 * The authorized situation a contribution is rendered in.
 *
 * The actor is already authenticated and the scope, when present, is already
 * authorized for reading. A context never carries results of other projects and
 * is immutable, so it is safe to hand to several contributions in turn.
 */
final readonly class UiContext
{
    public const string MODE_PAGE   = 'page';
    public const string MODE_DETAIL = 'detail';
    public const string MODE_CREATE = 'create';

    /**
     * @param int               $actorId   The authenticated user
     * @param ProjectScope|null $scope     Authorized project, or null outside a project
     * @param string            $mode      One of page, detail or create
     * @param string|null       $routeName Name of the route currently rendering
     * @param int|null          $recordId  Ticket id in detail mode, null while creating
     * @param array             $record    The loaded record, when the caller has it
     */
    public function __construct(
        public int $actorId,
        public ?ProjectScope $scope = null,
        public string $mode = self::MODE_PAGE,
        public ?string $routeName = null,
        public ?int $recordId = null,
        public array $record = [],
    ) {
    }

    public function projectId(): ?int
    {
        return $this->scope === null ? null : (int) $this->scope->project['id'];
    }

    /**
     * Whether the actor may see something guarded by this project action
     *
     * A contribution without a permission still needs a login; a project
     * permission additionally needs an authorized project scope.
     *
     * @param string|null $permission Project action, or null for none
     */
    public function allows(?string $permission): bool
    {
        if ($permission === null) {
            return true;
        }

        return $this->scope !== null && $this->scope->allows($permission);
    }

    public function withRecord(?int $recordId, array $record = []): self
    {
        return new self(
            $this->actorId,
            $this->scope,
            $this->mode,
            $this->routeName,
            $recordId,
            $record,
        );
    }

    public function withMode(string $mode): self
    {
        return new self(
            $this->actorId,
            $this->scope,
            $mode,
            $this->routeName,
            $this->recordId,
            $this->record,
        );
    }
}
