<?php

declare(strict_types=1);

namespace Naf\Board\Tests\Database;

use Naf\Board\Services\RoleService;
use Naf\Board\Tests\Support\AttachmentFixture;
use Naf\Board\Tests\Support\BoardTestCase;

use function Naf\app;

/**
 * Roles a project defines for itself.
 *
 * They are ordinary permission sets enforced by the same policy as the built-in
 * roles, with two boundaries that keep them from being a way around anything: a
 * custom role belongs to the project that defined it and nowhere else, and it
 * can never carry the rights that decide who has rights. Otherwise anyone who
 * may edit roles could write themselves an owner.
 */
final class CustomRoleTest extends BoardTestCase
{
    use AttachmentFixture;

    private RoleService $roles;
    private int $project;
    private int $elsewhere;
    private int $roleId;
    private string $roleKey;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAttachments();
        $this->roles   = app()->container()->make(RoleService::class);
        $this->project = $this->projects->create([
            'name'        => 'Custom roles',
            'description' => 'Role boundary regression',
        ]);
        $this->elsewhere = $this->projects->create([
            'name'        => 'Other roles',
            'description' => 'Foreign role regression',
        ]);

        $this->roles->save($this->project, [
            'name'        => 'Redaktion',
            'description' => '',
            'permissions' => ['comment', 'upload'],
        ]);
        $this->roleId  = (int) $this->roles->list($this->project)[0]['id'];
        $this->roleKey = 'custom:' . $this->roleId;
        $this->projects->member($this->project, ['email' => 'member@example.test', 'role' => $this->roleKey]);
    }

    public function testACustomRoleGrantsExactlyWhatItLists(): void
    {
        $this->actAs($this->member);

        $scope = $this->access->project($this->project);

        $this->assertSame('Redaktion', $scope->roleName);
        $this->assertTrue($scope->allows('comment'));
        $this->assertTrue($scope->allows('upload'));
        $this->assertDenied(403, fn() => $this->access->project($this->project, 'write'));
        $this->assertDenied(403, fn() => $this->access->project($this->project, 'members'));
        $this->assertSame(
            'Redaktion',
            $this->query->board($this->project)['scope']->roleName,
            'the query lost the role',
        );
    }

    public function testARoleOfAnotherProjectCannotBeAssignedHere(): void
    {
        $this->assertDenied(404, fn() => $this->projects->member($this->elsewhere, [
            'email' => 'member@example.test',
            'role'  => $this->roleKey,
        ]));
    }

    public function testARoleCannotCarryTheRightsThatDecideRights(): void
    {
        $this->assertDenied(422, fn() => $this->roles->save($this->project, [
            'name'        => 'Backdoor',
            'description' => '',
            'permissions' => ['owners'],
        ]));
    }

    public function testARoleWithNoPermissionsAtAllIsRefused(): void
    {
        $this->assertDenied(422, fn() => $this->roles->save($this->project, [
            'name'        => 'Owner',
            'description' => '',
            'permissions' => [],
        ]));
    }

    public function testTakingAPermissionAwayTakesEffectAtOnce(): void
    {
        $this->editRole(['permissions' => ['comment']]);

        $this->actAs($this->member);

        $this->assertDenied(403, fn() => $this->access->project($this->project, 'upload'));
    }

    public function testASaveFromAVersionSomeoneElseHasAlreadyChangedIsRefused(): void
    {
        $data = [
            'id'          => $this->roleId,
            'version'     => 1,
            'name'        => 'Redaktion',
            'description' => '',
            'permissions' => ['comment'],
        ];
        $this->roles->save($this->project, $data);

        $this->assertDenied(409, fn() => $this->roles->save($this->project, $data));
    }

    public function testSomeoneHoldingACustomRoleCannotWriteThemselvesABetterOne(): void
    {
        $this->actAs($this->member);

        $this->assertDenied(403, fn() => $this->roles->save($this->project, [
            'name'        => 'Self elevation',
            'description' => '',
            'permissions' => ['write'],
        ]));
    }

    public function testARoleStillHeldBySomebodyCannotBeDeleted(): void
    {
        $this->assertDenied(422, fn() => $this->roles->save($this->project, [
            'id'      => $this->roleId,
            'version' => $this->versionOfRole($this->roleId),
            'action'  => 'delete',
        ]));
    }

    public function testOnceNobodyHoldsItTheRoleCanBeDeleted(): void
    {
        $this->projects->member($this->project, ['email' => 'member@example.test', 'role' => 'remove']);

        $this->roles->save($this->project, [
            'id'      => $this->roleId,
            'version' => $this->versionOfRole($this->roleId),
            'action'  => 'delete',
        ]);

        $this->assertNotContains(
            $this->roleId,
            array_map('intval', array_column($this->roles->list($this->project), 'id')),
            'the deleted role is still listed',
        );
    }

    /**
     * Someone who may manage members may not hand out, or take away, more than
     * they hold themselves. Otherwise "may manage members" is quietly the right
     * to become anything.
     */
    public function testADelegatedMemberManagerCannotGrantOrRemoveAStrongerRole(): void
    {
        $this->roles->save($this->project, [
            'name'        => 'Teamhilfe',
            'description' => '',
            'permissions' => ['members'],
        ]);
        $helper = $this->roleNamed('Teamhilfe');
        $this->projects->member($this->project, [
            'email' => 'viewer@example.test',
            'role'  => 'custom:' . $helper['id'],
        ]);

        $this->actAs($this->viewer);
        $this->access->project($this->project, 'members');

        $this->assertDenied(403, fn() => $this->projects->member($this->project, [
            'email' => 'viewer@example.test',
            'role'  => 'member',
        ]), 'granted a built-in role');
        $this->assertDenied(403, fn() => $this->projects->member($this->project, [
            'email' => 'alice@example.test',
            'role'  => 'remove',
        ]), 'removed the owner');
        $this->assertDenied(403, fn() => $this->projects->member($this->project, [
            'email' => 'member@example.test',
            'role'  => 'remove',
        ]), 'removed someone holding a stronger role');
    }

    /**
     * A file is staged before it is promoted, and the promotion happens later.
     * If the right to upload is gone by then, the recovery must discard the file
     * rather than finish a job whose permission has since been withdrawn.
     */
    public function testAStagedFileIsDiscardedWhenTheUploadRightIsRevoked(): void
    {
        $this->roles->save($this->project, [
            'name'        => 'Uploads',
            'description' => '',
            'permissions' => ['upload'],
        ]);
        $uploader = $this->roleNamed('Uploads');
        $this->projects->member($this->project, [
            'email' => 'member@example.test',
            'role'  => 'custom:' . $uploader['id'],
        ]);
        $board  = $this->query->board($this->project);
        $ticket = $this->tickets->create($this->project, [
            'title'          => 'Upload revocation',
            'description'    => '',
            'priority'       => 'normal',
            'column_id'      => $board['columns'][0]['id'],
            'swimlane_id'    => $board['swimlanes'][0]['id'],
            'board_revision' => $board['board']['revision'],
        ]);

        $file = $this->stageWithBrokenPromotion($ticket);
        $this->assertSame(
            'staged',
            $this->scalar('SELECT state FROM attachments WHERE id=?', [$file]),
            'the upload did not stage',
        );

        $this->roles->save($this->project, [
            'id'          => $uploader['id'],
            'version'     => $this->versionOfRole((int) $uploader['id']),
            'name'        => 'Uploads',
            'description' => '',
            'permissions' => [],
        ]);
        $this->attachments->finalize($file);

        $this->assertSame(
            0,
            (int) $this->scalar('SELECT COUNT(*) FROM attachments WHERE id=?', [$file]),
            'a file uploaded under a right since revoked became visible',
        );
    }

    /** @param array<string,mixed> $changes */
    private function editRole(array $changes): void
    {
        $this->roles->save($this->project, array_replace([
            'id'          => $this->roleId,
            'version'     => $this->versionOfRole($this->roleId),
            'name'        => 'Redaktion',
            'description' => '',
        ], $changes));
    }

    private function versionOfRole(int $id): int
    {
        foreach ($this->roles->list($this->project) as $role) {
            if ((int) $role['id'] === $id) {
                return (int) $role['version'];
            }
        }

        $this->fail('No role with id ' . $id);
    }

    /** @return array<string,mixed> */
    private function roleNamed(string $name): array
    {
        foreach ($this->roles->list($this->project) as $role) {
            if ($role['name'] === $name) {
                return $role;
            }
        }

        $this->fail('No role named ' . $name);
    }

    private function stageWithBrokenPromotion(int $ticket): int
    {
        $ready    = $this->privateRoot . '/ready';
        $parked   = $this->privateRoot . '/ready-role-test-parked';
        $existing = is_dir($ready) && rename($ready, $parked);
        file_put_contents($ready, 'temporary promotion fault');

        try {
            $this->actAs($this->member);
            $this->attachments->upload($this->project, $ticket, $this->upload('A revoked staged file.'));

            return (int) $this->scalar('SELECT MAX(id) FROM attachments');
        } finally {
            unlink($ready);
            if ($existing) {
                rename($parked, $ready);
            }
            $this->actAs($this->alice);
        }
    }
}
