<?php

declare(strict_types=1);

namespace Naf\Board\Tests\Database;

use Closure;
use Naf\Board\Contracts\BulkPropertyHandlerInterface;
use Naf\Board\Definition\BulkProperty;
use Naf\Board\Domain\Failure;
use Naf\Board\Rbac\Grants;
use Naf\Board\Services\BulkUpdateService;
use Naf\Board\Tests\Support\BoardTestCase;
use Naf\Rbac\Events\GrantsChanged;
use PDO;
use RuntimeException;

use function Naf\app;
use function Naf\Board\extensions;
use function Naf\event;
use function Naf\Rbac\rbac;

final class BulkUpdateTest extends BoardTestCase
{
    private BulkUpdateService $bulk;

    protected function setUp(): void
    {
        parent::setUp();
        Grants::makeAdmin((int) $this->alice->getId());
        $this->actAs($this->alice);
        $this->bulk = app()->container()->make(BulkUpdateService::class);
    }

    protected function tearDown(): void
    {
        extensions()->bulkProperties()->remove('example:flag');
        extensions()->bulkProperties()->remove('example:z_failure');
        rbac()->forget();
        parent::tearDown();
    }

    public function testGlobalRoleUpdatesPreserveOtherRolesAndProjectMembershipsAndAreIdempotent(): void
    {
        $targets       = [(int) $this->viewer->getId(), (int) $this->member->getId()];
        $projectGrants = $this->fetchRows("SELECT * FROM rbac_user_roles WHERE scope<>'' ORDER BY user_id,role_id,scope");
        $memberships   = $this->fetchRows('SELECT * FROM project_members ORDER BY project_id,user_id');
        $admin         = rbac()->roles->idOf('admin');
        self::assertSame(2, $this->bulk->apply('users', $targets, ['add_role' => (string) $admin]));
        foreach ($targets as $target) {
            self::assertContains('admin', rbac()->assignments->rolesOf($target));
            self::assertContains('user', rbac()->assignments->rolesOf($target));
        }
        $audits = (int) $this->scalar("SELECT COUNT(*) FROM activities WHERE event_type='rbac.granted'");
        $this->bulk->apply('users', $targets, ['add_role' => (string) $admin]);
        self::assertSame($audits, (int) $this->scalar("SELECT COUNT(*) FROM activities WHERE event_type='rbac.granted'"));
        $this->bulk->apply('users', $targets, ['remove_role' => (string) $admin]);
        foreach ($targets as $target) {
            self::assertSame(['user'], rbac()->assignments->rolesOf($target));
        }
        self::assertSame($projectGrants, $this->fetchRows("SELECT * FROM rbac_user_roles WHERE scope<>'' ORDER BY user_id,role_id,scope"));
        self::assertSame($memberships, $this->fetchRows('SELECT * FROM project_members ORDER BY project_id,user_id'));
    }

    public function testTwoEnabledPropertiesMergeInsteadOfOverwritingTheirPreparedSnapshots(): void
    {
        $target = (int) $this->viewer->getId();
        $this->bulk->apply('users', [$target], [
            'add_role'    => (string) rbac()->roles->idOf('admin'),
            'remove_role' => (string) rbac()->roles->idOf('user'),
        ]);
        self::assertSame(['admin'], rbac()->assignments->rolesOf($target));
    }

    public function testSelfSelectionUnknownAccountsAndUnknownPropertiesNeverPartiallyApply(): void
    {
        $target = (int) $this->viewer->getId();
        $before = rbac()->assignments->grantsOf($target);
        $value  = ['add_role' => (string) rbac()->roles->idOf('admin')];
        $this->assertDenied(403, fn() => $this->bulk->apply('users', [$target, (int) $this->alice->getId()], $value));
        $this->assertDenied(409, fn() => $this->bulk->apply('users', [$target, 999999999], $value));
        $this->assertDenied(422, fn() => $this->bulk->apply('users', [$target], [...$value, 'password_hash' => 'x']));
        $this->assertDenied(422, fn() => $this->bulk->apply('users', [$target], ['add_role' => ['bad']]));
        $this->assertDenied(422, fn() => $this->bulk->apply('users', [$target], ['add_role' => '999999999']));
        self::assertSame($before, rbac()->assignments->grantsOf($target));
    }

    public function testAnOrdinaryUserCannotUseTheBulkService(): void
    {
        $this->actAs($this->viewer);
        self::assertSame([], $this->bulk->properties('users'));
        $this->assertDenied(403, fn() => $this->bulk->apply('users', [(int) $this->bob->getId()], [
            'add_role' => (string) rbac()->roles->idOf('admin'),
        ]));
    }

    public function testAGrantManagerCannotAlterAHigherPrivilegedTargetOrGrantBeyondTheirReach(): void
    {
        $role  = rbac()->roles->create('limited-bulk', 'Limited', '', ['users.view', 'users.manage', 'rbac.manage']);
        $actor = (int) $this->viewer->getId();
        rbac()->assignments->assign($actor, [$role]);
        $this->actAs($this->viewer);
        $this->assertDenied(422, fn() => $this->bulk->apply('users', [(int) $this->member->getId()], [
            'add_role' => (string) rbac()->roles->idOf('admin'),
        ]));
        $this->assertDenied(403, fn() => $this->bulk->apply('users', [(int) $this->alice->getId()], [
            'add_role' => (string) $role,
        ]));
    }

    public function testAnAuditListenerFailureRollsBackEarlierTargetsAndTheirAuditEntries(): void
    {
        $targets = [(int) $this->viewer->getId(), (int) $this->member->getId()];
        sort($targets);
        $before = array_map(fn($target) => rbac()->assignments->grantsOf($target), $targets);
        $audits = (int) $this->scalar('SELECT COUNT(*) FROM activities');
        $armed  = true;
        event()->listen(GrantsChanged::class, static function (GrantsChanged $change) use ($targets, &$armed): void {
            if ($armed && $change->targetId === $targets[1]) {
                $armed = false;
                throw new RuntimeException('Test audit failure');
            }
        });

        try {
            $this->bulk->apply('users', $targets, ['add_role' => (string) rbac()->roles->idOf('admin')]);
            self::fail('The listener did not reject the batch.');
        } catch (RuntimeException $failure) {
            self::assertSame('Test audit failure', $failure->getMessage());
        }
        self::assertSame($before, array_map(fn($target) => rbac()->assignments->grantsOf($target), $targets));
        self::assertSame($audits, (int) $this->scalar('SELECT COUNT(*) FROM activities'));
    }

    public function testSelectionMustBeExplicitBoundedAndNonEmpty(): void
    {
        $values = ['add_role' => (string) rbac()->roles->idOf('user')];
        foreach ([null, [], 'all', ['all'], range(1, 51), ['x' => 1]] as $selection) {
            $this->assertDenied(422, fn() => $this->bulk->apply('users', $selection, $values));
        }
        $this->assertDenied(422, fn() => $this->bulk->apply('users', [(int) $this->viewer->getId()], []));
    }

    public function testContributedPropertiesReuseTypesPreserveFalseAndRollbackEveryWriteOnFailure(): void
    {
        $registry = extensions()->bulkProperties();
        $registry->add(new BulkProperty('flag', 'example', 'Flag', 'boolean', ExampleBulkHandler::class));
        $registry->add(new BulkProperty('z_failure', 'example', 'Failure', 'boolean', ExampleBulkHandler::class));
        $targets = [(int) $this->viewer->getId(), (int) $this->member->getId()];
        $this->bulk->apply('example', $targets, ['flag' => false]);
        self::assertSame([0, 0], array_map(fn($id) => (int) $this->scalar('SELECT active FROM users WHERE id=?', [$id]), $targets));
        $this->assertDenied(422, fn() => $this->bulk->apply('example', $targets, ['flag' => 'yes']));
        $this->assertDenied(409, fn() => $this->bulk->apply('example', $targets, ['flag' => true, 'z_failure' => true]));
        self::assertSame([0, 0], array_map(fn($id) => (int) $this->scalar('SELECT active FROM users WHERE id=?', [$id]), $targets));
    }
}

/** Test-only example of another resource/property, using the same connection. */
final class ExampleBulkHandler implements BulkPropertyHandlerInterface
{
    public function __construct(private PDO $pdo)
    {
    }

    public function options(BulkProperty $property, int $actor): array
    {
        return $property->options;
    }

    public function prepare(BulkProperty $property, int $actor, array $targets, mixed $value): Closure
    {
        return function () use ($property, $targets, $value): void {
            $this->pdo->prepare('UPDATE users SET active=? WHERE id=?')->execute([(int) $value, $targets[0]]);
            if ($property->key === 'z_failure') {
                throw new Failure('Test write failure', 409);
            }
            $this->pdo->prepare('UPDATE users SET active=? WHERE id=?')->execute([(int) $value, $targets[1]]);
        };
    }
}
