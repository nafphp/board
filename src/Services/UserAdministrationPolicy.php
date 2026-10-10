<?php

declare(strict_types=1);

namespace Naf\Board\Services;

use Naf\Board\Domain\Failure;
use Naf\Board\Rbac\Installation;
use Naf\Rbac\Scope;
use PDO;

use function Naf\I18n\t;
use function Naf\Rbac\rbac;

/** Account credentials must not become a way around scoped privilege boundaries. @internal */
final class UserAdministrationPolicy
{
    public function __construct(private PDO $pdo)
    {
    }

    public function lock(): void
    {
        // Take current role/grant locks before the transaction's first snapshot.
        $this->pdo->query('SELECT id FROM rbac_roles ORDER BY id FOR UPDATE')->fetchAll();
        $this->pdo->query('SELECT role_id, permission FROM rbac_role_permissions ORDER BY role_id, permission FOR UPDATE')->fetchAll();
        $this->pdo->query('SELECT user_id, role_id, scope FROM rbac_user_roles ORDER BY user_id, role_id, scope FOR UPDATE')->fetchAll();
    }

    public function authorize(int $actor, int $target, bool $reset = false): void
    {
        $held     = rbac()->assignments->permissionsOf($actor);
        $required = [Installation::MANAGE_SETTINGS, Installation::VIEW_USERS, Installation::MANAGE_USERS];
        if ($reset) {
            $required[] = Installation::RESET_PASSWORDS;
        }
        if (array_diff($required, $held) !== []) {
            $this->denied();
        }
        // Changing an email or resetting a password grants control over every
        // scope this account can reach, including its private boards.
        $scopes = ['' => true];
        foreach (rbac()->assignments->grantsOf($target) as $grant) {
            $scopes[$grant['scope']] = true;
        }
        foreach (array_keys($scopes) as $stored) {
            $scope = Scope::parse($stored);
            if (array_diff(
                rbac()->assignments->permissionsOf($target, $scope),
                rbac()->assignments->permissionsOf($actor, $scope),
            ) !== []) {
                $this->denied();
            }
        }
    }

    private function denied(): never
    {
        throw new Failure(t('Du darfst dieses Nutzerkonto nicht verwalten.'), 403);
    }
}
