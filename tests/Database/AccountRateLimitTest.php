<?php

declare(strict_types=1);

namespace Naf\Board\Tests\Database;

use Naf\Board\Tests\Support\AccountTestCase;

/**
 * Two counters, for the two things worth attacking.
 *
 * Verification mails are limited because otherwise an account is a way to send
 * mail to anyone; reauthentication is limited because otherwise a signed-in
 * session is an oracle for guessing the password behind it.
 */
final class AccountRateLimitTest extends AccountTestCase
{
    public function testSendingVerificationMailIsLimited(): void
    {
        [$user, $accounts] = $this->newAccount();

        for ($attempt = 0; $attempt < 3; ++$attempt) {
            $accounts->requestEmail($this->emailRequest('limited-' . $user->getId() . '@example.test'));
        }

        $this->assertDenied(429, fn() => $accounts->requestEmail(
            $this->emailRequest('limited-again@example.test'),
        ));
    }

    /**
     * And the limit is on attempts, not on failures: once it is reached, the
     * right password waits too. Otherwise a correct guess would be let straight
     * through the wall built to stop guessing.
     */
    public function testGuessingTheCurrentPasswordIsLimitedIncludingTheRightGuess(): void
    {
        [, $accounts] = $this->newAccount();

        for ($attempt = 0; $attempt < 10; ++$attempt) {
            $this->assertDenied(403, fn() => $accounts->changePassword(
                [...self::NEW_PASSWORD, 'current_password' => 'wrong'],
            ));
        }

        $this->assertDenied(429, fn() => $accounts->changePassword(self::NEW_PASSWORD));
    }
}
