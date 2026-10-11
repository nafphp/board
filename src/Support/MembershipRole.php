<?php

declare(strict_types=1);

namespace Naf\Board\Support;

use Naf\Board\Contracts\AccessInterface;
use Naf\Board\Domain\Failure;
use Naf\Board\Domain\ProjectScope;
use PDO;

use function Naf\I18n\t;

/** Shared role checks for direct membership changes and invitations. @internal */
final class MembershipRole
{
    public function __construct(private PDO $pdo, private AccessInterface $access)
    {
    }

    /** @return array{role:string,custom_role_id:?int} */
    public function resolve(ProjectScope $scope, mixed $role, array $old = [], bool $allowRemoval = false): array
    {
        $builtIn = ['owner', 'manager', 'member', 'viewer'];
        if ($allowRemoval) {
            $builtIn[] = 'remove';
        }
        if (!is_string($role) || (!in_array($role, $builtIn, true) && preg_match('/^custom:[1-9][0-9]*$/D', $role) !== 1)) {
            throw new Failure(t('Ungültige Projektrolle.'));
        }
        $project      = (int) $scope->project['id'];
        $customRoleId = str_starts_with($role, 'custom:') ? Input::id(substr($role, 7)) : null;
        $storedRole   = $customRoleId === null ? $role : 'viewer';
        if ($customRoleId !== null) {
            $statement = $this->pdo->prepare('SELECT id FROM project_roles WHERE project_id=? AND id=?');
            $statement->execute([$project, $customRoleId]);
            if (!$statement->fetchColumn()) {
                throw new Failure(t('Rolle nicht gefunden.'), 404);
            }
        }
        $newRights = $this->access->permissions($project, $storedRole, $customRoleId);
        $oldRights = $old ? $this->access->permissions($project, $old['role'], isset($old['custom_role_id']) ? (int) $old['custom_role_id'] : null) : [];
        if ($scope->role !== 'owner' && (
            in_array($role, ['owner', 'manager'], true)
            || in_array($old['role'] ?? '', ['owner', 'manager'], true)
            || array_diff([...$newRights, ...$oldRights], $scope->permissions ?? [])
        )) {
            throw new Failure(t('Diese Rolle kann nur ein Owner vergeben oder ändern.'), 403);
        }

        return ['role' => $storedRole, 'custom_role_id' => $customRoleId];
    }
}
