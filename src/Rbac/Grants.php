<?php

declare(strict_types=1);

namespace Naf\Board\Rbac;

use Naf\Rbac\Scope;

use function Naf\Rbac\rbac;

/**
 * What a new account starts with.
 *
 * Until now every signed-in person could create boards, because the user model
 * said so in a line of code. It is a role now, which means an installation can
 * take it away -- and it also means somebody has to give it, at the one moment
 * an account comes into being.
 *
 * There is no registration here, so that moment is the seed, the invitation
 * flow when it arrives, and the test fixtures. All three come through here
 * rather than each knowing which role is the ordinary one.
 *
 * @internal
 */
final class Grants
{
    public const string DEFAULT_ROLE = 'user';

    /** Appoint an administrator. Idempotent, and it keeps what they already hold. */
    public static function makeAdmin(int $userId): void
    {
        self::add($userId, 'admin');
    }

    /** Give a new account what an ordinary account has. Silent when already held. */
    public static function ensureDefault(int $userId): void
    {
        self::add($userId, self::DEFAULT_ROLE);
    }

    /**
     * Set what somebody holds in one board, replacing whatever they held there.
     *
     * A null role takes them off it. Only the four built-in roles are written:
     * a project's own role still answers through project_role_permissions until
     * that half moves too, and Access unions the two.
     */
    public static function inProject(int $userId, int $projectId, ?string $role): void
    {
        $rbac  = rbac();
        $scope = Scope::of(Project::SCOPE, $projectId);
        $id    = $role === null ? null : $rbac->roles->idOf($role);

        $rbac->assignments->assign($userId, $id === null ? [] : [$id], $scope);
        $rbac->forget($userId);
    }

    /** Add one installation-wide role, leaving every other grant alone. */
    private static function add(int $userId, string $role): void
    {
        $rbac = rbac();
        $id   = $rbac->roles->idOf($role);

        if ($id === null || in_array($role, $rbac->assignments->rolesOf($userId), true)) {
            return;
        }

        $held = [];
        foreach ($rbac->assignments->grantsOf($userId) as $grant) {
            if ($grant['scope'] === '') {
                $held[] = $grant['role_id'];
            }
        }

        $rbac->assignments->assign($userId, [...$held, $id], Scope::everywhere());
        $rbac->forget($userId);
    }
}
