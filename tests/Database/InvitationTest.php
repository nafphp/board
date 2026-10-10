<?php

declare(strict_types=1);

namespace Naf\Board\Tests\Database;

use Naf\Board\Contracts\InvitationServiceInterface;
use Naf\Board\Domain\Change;
use Naf\Board\Domain\Failure;
use Naf\Board\Services\InvitationService;
use Naf\Board\Tests\Support\BoardTestCase;
use Naf\Core\Config;
use Naf\Mail\Core\Mailer;
use Naf\Mail\Core\TransportInterface;
use Naf\Mail\Models\Mail;

use function Naf\app;
use function Naf\event;

final class InvitationTest extends BoardTestCase
{
    protected bool $transactional = false;

    private InvitationServiceInterface $invitations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->invitations = app()->container()->get(InvitationServiceInterface::class);
    }

    public function testRegistrationConsumesTheHashedTokenAndGrantsOnlyOneBoard(): void
    {
        $invite = $this->invite();
        $token  = $this->token($invite);
        $stored = $this->fetchOne('SELECT * FROM project_invitations WHERE project_id=?', [$this->projectA]);
        $this->assertSame(hash('sha256', $token), $stored['token_hash']);
        $this->assertStringNotContainsString($token, json_encode($stored));
        $this->assertSame(0, (int) $this->scalar("SELECT COUNT(*) FROM users WHERE email='new@example.test'"));
        $this->auth->logout();
        $accepted = $this->invitations->accept($token, $this->registration(['email' => 'forged@example.test']));
        $user     = (int) $accepted['user_id'];
        $this->assertSame('new@example.test', $this->scalar('SELECT email FROM users WHERE id=?', [$user]));
        $hash = $this->scalar('SELECT password_hash FROM users WHERE id=?', [$user]);
        $this->assertTrue(password_verify('Invitation-Password-2026!', $hash));
        $this->assertSame(1, (int) $this->scalar('SELECT COUNT(*) FROM project_members WHERE user_id=?', [$user]));
        $this->assertSame('member', $this->scalar('SELECT role FROM project_members WHERE user_id=? AND project_id=?', [$user, $this->projectA]));
        $this->assertSame(0, (int) $this->scalar("SELECT COUNT(*) FROM rbac_user_roles WHERE user_id=? AND scope=''", [$user]));
        $this->assertSame(0, (int) $this->scalar('SELECT COUNT(*) FROM project_members WHERE user_id=? AND project_id=?', [$user, $this->projectB]));
        $this->assertDenied(410, fn() => $this->invitations->accept($token, $this->registration()));
        $this->assertSame(0, (int) $this->scalar('SELECT COUNT(*) FROM project_invitations WHERE project_id=?', [$this->projectA]));
    }

    public function testRenewalRevocationAndExpiryInvalidateTheLink(): void
    {
        $first  = $this->invite();
        $second = $this->invite();
        $this->assertNotSame($first['invitation_url'], $second['invitation_url']);
        $this->assertDenied(410, fn() => $this->invitations->find($this->token($first)));
        $pending = $this->invitations->pending($this->projectA);
        $this->assertCount(1, $pending);
        $this->assertArrayNotHasKey('token_hash', $pending[0]);
        $this->assertDenied(404, fn() => $this->invitations->revoke($this->projectB, (int) $pending[0]['id']));
        $this->invitations->revoke($this->projectA, (int) $pending[0]['id']);
        $this->assertDenied(410, fn() => $this->invitations->find($this->token($second)));
        $third = $this->invite();
        $this->pdo->prepare('UPDATE project_invitations SET expires_at=? WHERE project_id=?')->execute([time() - 1, $this->projectA]);
        $this->assertDenied(410, fn() => $this->invitations->accept($this->token($third), $this->registration()));
    }

    public function testWeakAndMismatchedPasswordsDoNotCreateAnAccountOrConsumeTheInvite(): void
    {
        $token = $this->token($this->invite());
        $this->auth->logout();
        foreach ([['password' => 'weak'], ['password_confirmation' => 'different'], ['name' => '   ']] as $data) {
            $this->assertDenied(422, fn() => $this->invitations->accept($token, $this->registration($data)));
            $this->assertSame(0, (int) $this->scalar("SELECT COUNT(*) FROM users WHERE email='new@example.test'"));
            $this->assertSame('new@example.test', $this->invitations->find($token)['email']);
        }
    }

    public function testCurrentSponsorRightsAndArchiveStateAreRechecked(): void
    {
        $this->projects->member($this->projectA, ['email' => 'member@example.test', 'role' => 'manager']);
        $this->actAs($this->member);
        $token = $this->token($this->invite());
        $this->assertDenied(403, fn() => $this->invite(['role' => 'owner']));
        $this->assertDenied(403, fn() => $this->invite(['role' => 'manager']));
        $this->actAs($this->alice);
        $this->projects->member($this->projectA, ['email' => 'member@example.test', 'role' => 'member']);
        $this->auth->logout();
        $this->assertDenied(410, fn() => $this->invitations->accept($token, $this->registration()));
        $this->actAs($this->alice);
        $token = $this->token($this->invite());
        $this->projects->archive($this->projectA, true);
        $this->auth->logout();
        $this->assertDenied(410, fn() => $this->invitations->accept($token, $this->registration()));
    }

    public function testCustomRolesAreProjectBoundAndMustStillExist(): void
    {
        $this->pdo->prepare("INSERT INTO project_roles(project_id,name) VALUES(?,'Reports')")->execute([$this->projectA]);
        $role  = (int) $this->pdo->lastInsertId();
        $token = $this->token($this->invite(['role' => 'custom:' . $role]));
        $this->pdo->prepare('DELETE FROM project_roles WHERE id=?')->execute([$role]);
        $this->auth->logout();
        $this->assertDenied(410, fn() => $this->invitations->accept($token, $this->registration()));
        $this->assertSame(0, (int) $this->scalar("SELECT COUNT(*) FROM users WHERE email='new@example.test'"));
    }

    public function testInvitationsAcceptProjectCustomRolesWithoutGrantingGlobalRights(): void
    {
        $this->pdo->prepare("INSERT INTO project_roles(project_id,name) VALUES(?,'Reports')")->execute([$this->projectA]);
        $role = (int) $this->pdo->lastInsertId();
        $this->pdo->prepare("INSERT INTO project_role_permissions(project_id,role_id,permission) VALUES(?,?,'read')")->execute([$this->projectA, $role]);
        $token = $this->token($this->invite(['role' => 'custom:' . $role]));
        $this->auth->logout();
        $result     = $this->invitations->accept($token, $this->registration());
        $membership = $this->fetchOne('SELECT role,custom_role_id FROM project_members WHERE project_id=? AND user_id=?', [$this->projectA, $result['user_id']]);
        $this->assertSame('viewer', $membership['role']);
        $this->assertSame($role, (int) $membership['custom_role_id']);
        $this->assertSame(0, (int) $this->scalar("SELECT COUNT(*) FROM rbac_user_roles WHERE user_id=? AND scope=''", [$result['user_id']]));
    }

    public function testExistingAddressesAndDisabledDeliveryCannotCreateInvitations(): void
    {
        $this->assertDenied(409, fn() => $this->invite(['email' => 'bob@example.test']));
        $this->assertDenied(503, fn() => $this->invite(['send_email' => true]));
        $this->assertSame([], $this->invitations->pending($this->projectA));
    }

    public function testAnAccountCreatedInTheMeantimeMustAuthenticateAndIsNeverOverwritten(): void
    {
        $token = $this->token($this->invite());
        $user  = $this->createUser('new');
        $hash  = $this->scalar('SELECT password_hash FROM users WHERE id=?', [$user->getId()]);
        $this->assertTrue($this->invitations->find($token)['existing']);
        $this->assertDenied(403, fn() => $this->invitations->accept($token, $this->registration()));
        $this->auth->logout();
        $this->assertDenied(403, fn() => $this->invitations->accept($token, $this->registration()));
        $this->actAs($user);
        $result = $this->invitations->accept($token, []);
        $this->assertFalse($result['created']);
        $this->assertSame($hash, $this->scalar('SELECT password_hash FROM users WHERE id=?', [$user->getId()]));
        $this->assertSame('member', $this->access->project($this->projectA)->role);
    }

    public function testAnExistingMembershipKeepsItsRoleWhenAnotherInviteIsAccepted(): void
    {
        $token = $this->token($this->invite(['role' => 'viewer']));
        $user  = $this->createUser('new');
        $this->projects->member($this->projectA, ['email' => 'new@example.test', 'role' => 'owner']);
        $this->actAs($user);
        $this->invitations->accept($token, []);
        $this->assertSame('owner', $this->access->project($this->projectA)->role);
    }

    public function testAccountSearchRequiresMemberManagementAndReturnsOnlyPublicAccountFields(): void
    {
        $this->assertSame([], $this->invitations->search($this->projectA, 'a'));
        $found = $this->invitations->search($this->projectA, 'bo');
        $this->assertCount(1, $found);
        $this->assertSame(['id', 'name', 'email'], array_keys($found[0]));
        $this->assertSame('bob@example.test', $found[0]['email']);
        $this->assertSame([], $this->invitations->search($this->projectA, '%%'));
        $this->actAs($this->viewer);
        $this->assertDenied(403, fn() => $this->invitations->search($this->projectA, 'bo'));
        $this->assertDenied(403, fn() => $this->invite());
        $this->actAs($this->bob);
        $this->assertDenied(404, fn() => $this->invitations->search($this->projectA, 'al'));
    }

    public function testTheInvitedAccountCanBeAddedToAnotherBoardThroughTheExistingMembershipService(): void
    {
        $token = $this->token($this->invite());
        $this->auth->logout();
        $result = $this->invitations->accept($token, $this->registration());
        $this->actAs($this->bob);
        $this->assertSame('new@example.test', $this->invitations->search($this->projectB, 'Invited')[0]['email']);
        $this->projects->member($this->projectB, ['email' => 'new@example.test', 'role' => 'viewer']);
        $this->assertSame(2, (int) $this->scalar('SELECT COUNT(*) FROM project_members WHERE user_id=? AND active=1', [$result['user_id']]));
    }

    public function testDeletingTheProjectInvalidatesItsPendingInvitation(): void
    {
        $token = $this->token($this->invite());
        $this->projects->delete($this->projectA, ['confirmation' => 'A']);
        $this->assertDenied(410, fn() => $this->invitations->find($token));
    }

    public function testAListenerRefusalRollsBackTheAccountMembershipAndTokenConsumption(): void
    {
        $token   = $this->token($this->invite());
        $enabled = true;
        event()->listen(Change::class, static function (Change $change) use (&$enabled): void {
            if ($enabled && $change->type === 'account.created') {
                throw new Failure('No registration today.', 409);
            }
        });
        $this->auth->logout();

        try {
            $this->assertDenied(409, fn() => $this->invitations->accept($token, $this->registration()));
        } finally {
            $enabled = false;
        }
        $this->assertSame(0, (int) $this->scalar("SELECT COUNT(*) FROM users WHERE email='new@example.test'"));
        $this->assertSame('new@example.test', $this->invitations->find($token)['email']);
    }

    public function testMailUsesTheConfiguredOriginAndFailurePreservesThePreviousInvitation(): void
    {
        $original                             = app()->container()->get(Config::class);
        $settings                             = $original->all();
        $settings['nafinity']['mail_enabled'] = true;
        app()->container()->set(Config::class, new Config($settings));
        $transport = new class implements TransportInterface {
            public array $messages = [];
            public bool $succeed   = true;

            public function sendMail(Mail $mail): bool
            {
                $this->messages[] = $mail;

                return $this->succeed;
            }
        };
        $mailer  = new Mailer($transport);
        $service = app()->container()->make(InvitationService::class, ['mailer' => $mailer]);

        try {
            $first = $service->create($this->projectA, ['email' => 'new@example.test', 'send_email' => true]);
            $this->assertTrue($first['sent']);
            $this->assertStringContainsString($first['invitation_url'], $transport->messages[0]->getContent());
            $transport->succeed = false;
            $this->assertDenied(503, fn() => $service->create($this->projectA, ['email' => 'new@example.test', 'send_email' => true]));
            $this->assertSame('new@example.test', $service->find($this->token($first))['email']);
        } finally {
            app()->container()->set(Config::class, $original);
        }
    }

    private function invite(array $data = []): array
    {
        return $this->invitations->create($this->projectA, ['email' => 'new@example.test', ...$data]);
    }

    private function token(array $invitation): string
    {
        return basename($invitation['invitation_url']);
    }

    private function registration(array $data = []): array
    {
        return ['name' => 'Invited Person', 'password' => 'Invitation-Password-2026!', 'password_confirmation' => 'Invitation-Password-2026!', ...$data];
    }
}
