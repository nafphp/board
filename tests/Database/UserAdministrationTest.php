<?php

declare(strict_types=1);

namespace Naf\Board\Tests\Database;

use Naf\Auth\Support\PasswordHasher;
use Naf\Board\Domain\Change;
use Naf\Board\Rbac\Grants;
use Naf\Board\Services\UserAdministration;
use Naf\Board\Tests\Support\BoardTestCase;
use Naf\Core\Config;
use Naf\Mail\Core\Mailer;
use Naf\Mail\Core\TransportInterface;
use Naf\Mail\Models\Mail;
use RuntimeException;

use function Naf\app;
use function Naf\event;
use function Naf\Rbac\rbac;

final class UserAdministrationTest extends BoardTestCase
{
    protected bool $transactional = false;
    private UserAdministration $administration;

    protected function setUp(): void
    {
        parent::setUp();
        Grants::makeAdmin((int) $this->alice->getId());
        $this->actAs($this->alice);
        $this->administration = app()->container()->make(UserAdministration::class);
    }

    public function testEditingPreservesGrantsInvalidatesCredentialsAndRecordsOnlyFieldNames(): void
    {
        $id     = (int) $this->bob->getId();
        $before = $this->fetchOne('SELECT * FROM users WHERE id=?', [$id]);
        $grants = rbac()->assignments->grantsOf($id);
        $this->pdo->prepare('UPDATE users SET email_verified_at=? WHERE id=?')->execute([gmdate('Y-m-d H:i:s'), $id]);
        $this->pdo->prepare('INSERT INTO account_email_changes(user_id,request_id,email,code_hash,security_version,expires_at) VALUES(?,?,?,?,?,?)')
            ->execute([$id, str_repeat('a', 32), 'pending@example.test', str_repeat('b', 64), 0, time() + 900]);
        $input = [...$this->input($id), 'name' => 'Changed Bob', 'email' => 'CHANGED@example.test'];
        self::assertFalse($this->administration->update($id, $input));
        $after = $this->fetchOne('SELECT * FROM users WHERE id=?', [$id]);
        self::assertSame('Changed Bob', $after['name']);
        self::assertSame('changed@example.test', $after['email']);
        self::assertNull($after['email_verified_at']);
        self::assertSame((int) $before['security_version'] + 1, (int) $after['security_version']);
        self::assertSame($before['password_hash'], $after['password_hash']);
        self::assertSame($grants, rbac()->assignments->grantsOf($id));
        self::assertSame(0, (int) $this->scalar('SELECT COUNT(*) FROM account_email_changes WHERE user_id=?', [$id]));
        $audit = $this->fetchOne("SELECT * FROM activities WHERE event_type='account.updated' ORDER BY id DESC LIMIT 1");
        self::assertSame((int) $this->alice->getId(), (int) $audit['actor_id']);
        self::assertStringNotContainsString('changed@example.test', $audit['payload']);
        self::assertSame(['name', 'email'], json_decode($audit['payload'], true)['fields']);
        $this->assertDenied(409, fn() => $this->administration->update($id, $input));
    }

    public function testMalformedUnknownAndDuplicateFieldsCannotPartiallyUpdateAnAccount(): void
    {
        $id     = (int) $this->viewer->getId();
        $before = $this->fetchOne('SELECT * FROM users WHERE id=?', [$id]);
        $input  = $this->input($id);
        foreach ([['name' => ['array']], ['name' => '  '], ['active' => 2], ['email' => ['array']], ['password_hash' => 'bad'], ['revision' => ''], ['revision' => []]] as $bad) {
            $this->assertDenied(422, fn() => $this->administration->update($id, [...$input, ...$bad]));
        }
        $this->assertDenied(409, fn() => $this->administration->update($id, [...$input, 'name' => 'Must roll back', 'email' => 'alice@example.test']));
        self::assertSame($before, $this->fetchOne('SELECT * FROM users WHERE id=?', [$id]));
    }

    public function testDelegationCannotTakeOverStrongerGlobalOrScopedAccountsAndResetIsSeparate(): void
    {
        $actor = (int) $this->viewer->getId();
        $low   = $this->createUser('unprivileged');
        $role  = rbac()->roles->create('account-manager', 'Accounts', '', ['settings.manage', 'users.view', 'users.manage', 'projects.create']);
        rbac()->assignments->assign($actor, [$role]);
        $lowInput   = $this->input((int) $low->getId());
        $bobInput   = $this->input((int) $this->bob->getId());
        $aliceInput = $this->input((int) $this->alice->getId());
        $this->actAs($this->viewer);
        self::assertFalse($this->administration->update((int) $low->getId(), [...$lowInput, 'name' => 'Allowed']));
        $this->assertDenied(403, fn() => $this->administration->update((int) $this->alice->getId(), $aliceInput));
        $this->assertDenied(403, fn() => $this->administration->update((int) $this->bob->getId(), $bobInput));
        $this->assertDenied(403, fn() => $this->administration->requestReset((int) $low->getId(), ['revision' => $lowInput['revision']]));
        self::assertFalse($this->administration->editor((int) $low->getId())['canReset']);
    }

    public function testDeactivationIsReversiblePreservesContentAndNeverDisablesTheActor(): void
    {
        $own = (int) $this->alice->getId();
        $this->assertDenied(403, fn() => $this->administration->update($own, [...$this->input($own), 'active' => 0]));
        $id      = (int) $this->bob->getId();
        $grants  = rbac()->assignments->grantsOf($id);
        $members = $this->fetchRows('SELECT * FROM project_members WHERE user_id=?', [$id]);
        $this->administration->update($id, [...$this->input($id), 'active' => 0]);
        self::assertSame(0, (int) $this->scalar('SELECT active FROM users WHERE id=?', [$id]));
        $this->assertDenied(409, fn() => $this->administration->requestReset($id, ['revision' => $this->input($id)['revision']]));
        $this->administration->update($id, [...$this->input($id), 'active' => 1]);
        self::assertSame(2, (int) $this->scalar('SELECT security_version FROM users WHERE id=?', [$id]));
        self::assertSame($grants, rbac()->assignments->grantsOf($id));
        self::assertSame($members, $this->fetchRows('SELECT * FROM project_members WHERE user_id=?', [$id]));
    }

    public function testResetLinkIsHashedSingleUseAndRevokesSessionsOnlyOnConsumption(): void
    {
        $id     = (int) $this->viewer->getId();
        $old    = $this->scalar('SELECT password_hash FROM users WHERE id=?', [$id]);
        $result = $this->administration->requestReset($id, ['revision' => $this->input($id)['revision']]);
        $token  = basename($result['reset_url']);
        $row    = $this->fetchOne('SELECT * FROM account_password_resets WHERE user_id=?', [$id]);
        self::assertSame(hash('sha256', $token), $row['token_hash']);
        self::assertStringNotContainsString($token, json_encode($row));
        self::assertSame(0, (int) $this->scalar('SELECT security_version FROM users WHERE id=?', [$id]));
        self::assertSame($old, $this->scalar('SELECT password_hash FROM users WHERE id=?', [$id]));
        self::assertGreaterThan(time() + 1700, $result['expires_at']);
        $this->administration->findReset($token);
        $this->assertDenied(422, fn() => $this->administration->resetPassword($token, ['password' => 'short', 'password_confirmation' => 'short']));
        self::assertSame($id, $this->administration->resetPassword($token, $this->password()));
        $new = $this->scalar('SELECT password_hash FROM users WHERE id=?', [$id]);
        self::assertTrue(app()->container()->get(PasswordHasher::class)->verify($this->password()['password'], $new));
        self::assertSame(1, (int) $this->scalar('SELECT security_version FROM users WHERE id=?', [$id]));
        self::assertSame(0, (int) $this->scalar('SELECT COUNT(*) FROM account_password_resets WHERE user_id=?', [$id]));
        $this->assertDenied(410, fn() => $this->administration->resetPassword($token, $this->password()));
        self::assertStringNotContainsString($token, (string) $this->scalar("SELECT payload FROM activities WHERE event_type='account.password_reset_requested' ORDER BY id DESC LIMIT 1"));
    }

    public function testReissuingExpiryAndCredentialChangesInvalidateOldLinks(): void
    {
        $id       = (int) $this->viewer->getId();
        $revision = $this->input($id)['revision'];
        $first    = basename($this->administration->requestReset($id, ['revision' => $revision])['reset_url']);
        $second   = basename($this->administration->requestReset($id, ['revision' => $revision])['reset_url']);
        $this->assertDenied(410, fn() => $this->administration->findReset($first));
        $this->pdo->prepare('UPDATE account_password_resets SET expires_at=? WHERE user_id=?')->execute([time() - 1, $id]);
        $this->assertDenied(410, fn() => $this->administration->findReset($second));
        $third = basename($this->administration->requestReset($id, ['revision' => $revision])['reset_url']);
        $this->administration->update($id, [...$this->input($id), 'email' => 'new-viewer@example.test']);
        $this->assertDenied(410, fn() => $this->administration->findReset($third));
    }

    public function testResetRechecksTheIssuersCurrentPrivileges(): void
    {
        $id    = (int) $this->viewer->getId();
        $token = basename($this->administration->requestReset($id, ['revision' => $this->input($id)['revision']])['reset_url']);
        $old   = $this->scalar('SELECT password_hash FROM users WHERE id=?', [$id]);
        rbac()->assignments->assign((int) $this->alice->getId(), [rbac()->roles->idOf('user')]);
        $this->assertDenied(410, fn() => $this->administration->findReset($token));
        $this->assertDenied(410, fn() => $this->administration->resetPassword($token, $this->password()));
        self::assertSame($old, $this->scalar('SELECT password_hash FROM users WHERE id=?', [$id]));
    }

    public function testExternalAccountsCannotGainALocalPasswordOrChangeTheirProviderEmail(): void
    {
        $id = (int) $this->viewer->getId();
        $this->pdo->prepare('UPDATE users SET password_hash=NULL WHERE id=?')->execute([$id]);
        self::assertFalse($this->administration->editor($id)['canReset']);
        $this->assertDenied(409, fn() => $this->administration->requestReset($id, ['revision' => $this->input($id)['revision']]));
        $this->assertDenied(422, fn() => $this->administration->update($id, [...$this->input($id), 'email' => 'external-changed@example.test']));
        $this->administration->update($id, [...$this->input($id), 'name' => 'External display name']);
        self::assertNull($this->scalar('SELECT password_hash FROM users WHERE id=?', [$id]));
    }

    public function testMailFailurePreservesThePreviousLinkAndSuccessfulMailUsesConfiguredOrigin(): void
    {
        $original                             = app()->container()->get(Config::class);
        $settings                             = $original->all();
        $settings['nafinity']['mail_enabled'] = true;
        app()->container()->set(Config::class, new Config($settings));
        $transport = new class implements TransportInterface {
            public array $messages = [];
            public bool $succeed   = false;

            public function sendMail(Mail $mail): bool
            {
                $this->messages[] = $mail;

                return $this->succeed;
            }
        };
        $service = app()->container()->make(UserAdministration::class, ['mailer' => new Mailer($transport)]);

        try {
            $id    = (int) $this->viewer->getId();
            $input = ['revision' => $this->input($id)['revision']];
            $first = basename($service->requestReset($id, $input)['reset_url']);
            $this->assertDenied(503, fn() => $service->requestReset($id, [...$input, 'send_email' => '1']));
            $service->findReset($first);
            $transport->succeed = true;
            $sent               = $service->requestReset($id, [...$input, 'send_email' => '1']);
            self::assertTrue($sent['sent']);
            self::assertStringStartsWith('http://127.0.0.1:', $sent['reset_url']);
            self::assertStringContainsString($sent['reset_url'], $transport->messages[1]->getTextContent());
            $this->assertDenied(410, fn() => $service->findReset($first));
            $this->assertDenied(429, fn() => $service->requestReset($id, $input));
        } finally {
            app()->container()->set(Config::class, $original);
        }
    }

    public function testAuditFailureRollsBackThePasswordAndLeavesTheLinkUsable(): void
    {
        $id     = (int) $this->viewer->getId();
        $token  = basename($this->administration->requestReset($id, ['revision' => $this->input($id)['revision']])['reset_url']);
        $before = $this->fetchOne('SELECT * FROM users WHERE id=?', [$id]);
        $queued = (int) $this->scalar('SELECT COUNT(*) FROM naf_queue_jobs');
        $armed  = true;
        event()->listen(Change::class, static function (Change $change) use (&$armed): void {
            if ($armed && $change->type === 'account.password_changed') {
                $armed = false;
                throw new RuntimeException('Test reset audit refusal');
            }
        });

        try {
            $this->administration->resetPassword($token, $this->password());
            self::fail('The listener should refuse the write.');
        } catch (RuntimeException $failure) {
            self::assertSame('Test reset audit refusal', $failure->getMessage());
        }
        self::assertSame($before, $this->fetchOne('SELECT * FROM users WHERE id=?', [$id]));
        $this->administration->findReset($token);
        self::assertSame($queued, (int) $this->scalar('SELECT COUNT(*) FROM naf_queue_jobs'));
    }

    private function input(int $id): array
    {
        $editor = $this->administration->editor($id);

        return ['name' => $editor['person']['name'], 'email' => $editor['person']['email'], 'active' => (int) $editor['person']['active'], 'revision' => $editor['revision']];
    }

    private function password(): array
    {
        return ['password' => 'A newly reset account password!', 'password_confirmation' => 'A newly reset account password!'];
    }
}
