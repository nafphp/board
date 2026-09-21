<?php

declare(strict_types=1);

namespace Naf\Board\Tests\Database;

use Naf\Board\Tests\Support\AccountTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Changing a password.
 *
 * It needs the current one, because a signed-in session left open on a shared
 * machine must not be enough to take an account over. And it ends every other
 * session, because the point of changing a password is usually that someone else
 * knows the old one.
 */
final class PasswordChangeTest extends AccountTestCase
{
    public function testTheCurrentPasswordIsRequired(): void
    {
        [$user, $accounts] = $this->newAccount();
        $before            = $this->scalar('SELECT password_hash FROM users WHERE id=?', [$user->getId()]);

        $this->assertDenied(403, fn() => $accounts->changePassword(
            [...self::NEW_PASSWORD, 'current_password' => 'wrong'],
        ));

        $this->assertSame(
            $before,
            $this->scalar('SELECT password_hash FROM users WHERE id=?', [$user->getId()]),
            'a refused change was written anyway',
        );
    }

    /**
     * @return array<string,array{array<string,string>}>
     */
    public static function unusablePasswords(): array
    {
        return [
            'the confirmation does not match'         => [['password_confirmation' => 'Different enough password!']],
            'too short'                               => [['password' => 'short']],
            'long in characters, short in what it is' => [['password' => 'äääääääääääääääääääääääääääääääääääää']],
        ];
    }

    /** @param array<string,string> $overrides */
    #[DataProvider('unusablePasswords')]
    public function testAnUnusableNewPasswordIsRefused(array $overrides): void
    {
        [, $accounts] = $this->newAccount();

        $this->assertDenied(422, fn() => $accounts->changePassword([...self::NEW_PASSWORD, ...$overrides]));
    }

    public function testTheNewPasswordIsHashedAndTheOldOneStopsWorking(): void
    {
        [$user, $accounts] = $this->newAccount();

        $accounts->changePassword(self::NEW_PASSWORD);

        $hash = $this->scalar('SELECT password_hash FROM users WHERE id=?', [$user->getId()]);
        $this->assertTrue($this->hasher->verify(self::NEW_PASSWORD['password'], $hash));
        $this->assertFalse(
            $this->hasher->verify(self::CURRENT_PASSWORD, $hash),
            'the old password still opens the account',
        );
    }

    public function testChangingThePasswordEndsEveryOtherSession(): void
    {
        [$user, $accounts] = $this->newAccount();

        $accounts->changePassword(self::NEW_PASSWORD);

        $this->assertSame(
            1,
            (int) $this->scalar('SELECT security_version FROM users WHERE id=?', [$user->getId()]),
            'the sessions were not revoked',
        );
    }

    /**
     * A pending address change was started by whoever knew the old password. It
     * cannot be allowed to complete afterwards.
     */
    public function testChangingThePasswordDropsAPendingAddressChange(): void
    {
        [$user, $accounts] = $this->newAccount();
        $accounts->requestEmail($this->emailRequest('pending-' . $user->getId() . '@example.test'));

        $accounts->changePassword(self::NEW_PASSWORD);

        $this->assertSame(
            0,
            (int) $this->scalar('SELECT COUNT(*) FROM account_email_changes WHERE user_id=?', [$user->getId()]),
            'the pending request survived the password change',
        );
        $this->assertDenied(401, fn() => $accounts->cancelEmail(), 'the old session still acts');
    }
}
