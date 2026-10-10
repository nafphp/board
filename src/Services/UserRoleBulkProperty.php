<?php

declare(strict_types=1);

namespace Naf\Board\Services;

use Closure;
use LogicException;
use Naf\Board\Contracts\BulkPropertyHandlerInterface;
use Naf\Board\Definition\BulkProperty;
use Naf\Board\Domain\Failure;
use Naf\Board\Rbac\Installation;
use Naf\Rbac\Events\GrantsChanged;
use Naf\Rbac\Exceptions\PrivilegedActionDenied;
use Naf\Rbac\Policy\PrivilegePolicy;
use Naf\Rbac\Scope;
use PDO;

use function Naf\event;
use function Naf\I18n\t;
use function Naf\Rbac\rbac;

/** Global role additions/removals preserve every unrelated role and scope. @internal */
final class UserRoleBulkProperty implements BulkPropertyHandlerInterface
{
    public function __construct(private PDO $pdo, private PrivilegePolicy $policy)
    {
    }

    public function options(BulkProperty $property, int $actor): array
    {
        if (!in_array($property->key, ['add_role', 'remove_role'], true) || $property->resource !== 'users') {
            throw new LogicException('UserRoleBulkProperty only supports users:add_role and users:remove_role.');
        }
        // Query current assignments rather than a request's cached permission result.
        if ($this->pdo->inTransaction()) {
            // Policy repositories use ordinary reads. Take the role and global
            // assignment locks before their first snapshot, so validation and
            // writes share the same current privilege state, including gaps.
            $this->pdo->query('SELECT id FROM rbac_roles ORDER BY id FOR UPDATE')->fetchAll();
            $this->pdo->query('SELECT role_id, permission FROM rbac_role_permissions ORDER BY role_id, permission FOR UPDATE')->fetchAll();
            $this->pdo->query("SELECT user_id, role_id FROM rbac_user_roles WHERE scope='' ORDER BY user_id, role_id FOR UPDATE")->fetchAll();
        }
        $held = rbac()->assignments->permissionsOf($actor);
        if (array_diff([Installation::VIEW_USERS, Installation::MANAGE_USERS, 'rbac.manage'], $held) !== []) {
            throw new Failure(t('Du hast für diese Aktion keine Berechtigung.'), 403);
        }
        $choices = ['' => t('Bitte triff eine Auswahl.')];
        foreach (rbac()->roles->all() as $role) {
            if ($role['scopeType'] === '' && array_diff($role['permissions'], $held) === []) {
                $choices[(string) $role['id']] = $role['label'];
            }
        }

        return [...$property->options, 'choices' => $choices];
    }

    public function prepare(BulkProperty $property, int $actor, array $targets, mixed $value): Closure
    {
        if (in_array($actor, $targets, true)) {
            throw new Failure(t('Deine eigenen Rollen kannst du nicht ändern. Das muss jemand anderes tun.'), 403);
        }
        $roleId = (int) $value;
        $locks  = $this->pdo->prepare('SELECT id, active FROM users WHERE id IN ('
            . implode(',', array_fill(0, count($targets) + 1, '?')) . ') ORDER BY id FOR UPDATE');
        $locks->execute([...$targets, $actor]);
        $users = $locks->fetchAll(PDO::FETCH_KEY_PAIR);
        if (count($users) !== count($targets) + 1 || (int) ($users[$actor] ?? 0) !== 1) {
            throw new Failure(t('Mindestens ein Konto ist nicht mehr verfügbar.'), 409);
        }
        $roleLock = $this->pdo->prepare('SELECT id FROM rbac_roles WHERE id=? AND scope_type=? FOR UPDATE');
        $roleLock->execute([$roleId, '']);
        if (!$roleLock->fetchColumn()) {
            throw new Failure(t('Diese Auswahl gibt es nicht.'), 422);
        }
        $grantLock = $this->pdo->prepare('SELECT user_id, role_id FROM rbac_user_roles WHERE user_id IN ('
            . implode(',', array_fill(0, count($targets) + 1, '?')) . ') ORDER BY user_id, role_id, scope FOR UPDATE');
        $grantLock->execute([...$targets, $actor]);
        $grantLock->fetchAll();

        $this->plans($property, $actor, $targets, $roleId);

        return function () use ($property, $actor, $targets, $roleId): void {
            foreach ($this->plans($property, $actor, $targets, $roleId) as $target => [$before, $after]) {
                if ($before === $after) {
                    continue;
                }
                rbac()->assignments->assign($target, array_keys($after), Scope::everywhere(), $actor);
                rbac()->forget($target);
                event()->dispatch(new GrantsChanged($actor, $target, '', array_values($before), array_values($after)));
            }
        };
    }

    private function plans(BulkProperty $property, int $actor, array $targets, int $roleId): array
    {
        $plans = [];
        foreach ($targets as $target) {
            $before = [];
            foreach (rbac()->assignments->grantsOf($target) as $grant) {
                if ($grant['scope'] === '') {
                    $before[$grant['role_id']] = $grant['label'];
                }
            }
            $after = $before;
            if ($property->key === 'add_role') {
                $after[$roleId] = rbac()->roles->find($roleId)['label'];
            } else {
                unset($after[$roleId]);
            }

            try {
                $this->policy->assertAssignment($actor, $target, array_keys($after), Scope::everywhere());
            } catch (PrivilegedActionDenied $failure) {
                throw new Failure($failure->getMessage(), 403);
            }
            $plans[$target] = [$before, $after];
        }

        return $plans;
    }
}
