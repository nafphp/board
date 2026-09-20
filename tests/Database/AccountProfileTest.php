<?php

declare(strict_types=1);

namespace Naf\Board\Tests\Database;

use Naf\Board\Tests\Support\AccountTestCase;

/**
 * What an account may see of itself, and what it may not.
 *
 * The profile is read by the browser, so it carries the address and whether a
 * local password exists -- and never the hash, nor the counter that decides
 * which sessions are still valid.
 */
final class AccountProfileTest extends AccountTestCase
{
    public function testTheProfileIsTheCallersOwnAccount(): void
    {
        [$user, $accounts] = $this->newAccount();

        $profile = $accounts->profile();

        $this->assertSame($user->getProfile()->email, $profile['email']);
        $this->assertTrue($profile['local_password']);
        $this->assertNull($profile['pending']);
    }

    public function testNoCredentialLeavesTheServer(): void
    {
        [, $accounts] = $this->newAccount();

        $profile = $accounts->profile();

        $this->assertArrayNotHasKey('password_hash', $profile);
        $this->assertArrayNotHasKey('security_version', $profile);
    }

    /**
     * An account that only signs in through an external provider has no local
     * password, so the flows that ask for one have nothing to check against and
     * must refuse rather than let anyone past an empty comparison.
     */
    public function testAnAccountWithoutALocalPasswordCannotUseThePasswordFlows(): void
    {
        [$user, $accounts] = $this->newAccount();
        $this->pdo->prepare('UPDATE users SET password_hash=NULL WHERE id=?')->execute([$user->getId()]);

        $this->assertFalse($accounts->profile()['local_password'], 'password controls were offered');
        $this->assertDenied(403, fn() => $accounts->changePassword(self::NEW_PASSWORD));
        $this->assertDenied(403, fn() => $accounts->requestEmail($this->emailRequest('external-new@example.test')));
    }
}
