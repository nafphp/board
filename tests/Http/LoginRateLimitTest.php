<?php

declare(strict_types=1);

namespace Naf\Board\Tests\Http;

use Naf\Board\Tests\Support\AcceptanceTestCase;
use Naf\Board\Tests\Support\HttpClient;

/**
 * Guessing a password is limited per account, not per browser.
 *
 * Someone guessing clears their cookies between attempts, or uses none at all,
 * so a limit that counted sessions would count to one forever. This counts the
 * account being guessed at -- and answers 429 with a Retry-After, because a
 * client that is told to wait can, and one that is only refused will not.
 */
final class LoginRateLimitTest extends AcceptanceTestCase
{
    /**
     * Guessing stops being answered, and how soon is not asserted.
     *
     * The limit is a count inside a window, so pinning it to a particular
     * attempt makes the test a race against that window: on a slow run the
     * earlier guesses fall out of it and the count starts again. What matters is
     * that it arrives, and that it arrives after guessing rather than instead of
     * an answer.
     */
    public function testGuessingTheSameAccountStopsBeingAnswered(): void
    {
        // An address of its own: this deliberately exhausts a limit, and doing
        // that to an account the rest of the suite signs in as would take the
        // rest of the suite with it.
        $email = 'rate-limit-' . bin2hex(random_bytes(4)) . '@example.test';
        // One browser, emptied between attempts -- no cookies carried over,
        // which is what somebody guessing would do, and quick enough that the
        // window does not expire underneath the test.
        $guesser  = new HttpClient(self::BASE, 'guess', self::AUTHORITY);
        $refused  = null;
        $attempts = 0;

        for (; $attempts < 25; ++$attempts) {
            $guesser->forgetSession();
            $page     = $guesser->request('/login');
            $response = $guesser->request('/login', [
                '_csrf'    => $guesser->token($page['body']),
                'email'    => $email,
                'password' => 'wrong-password',
            ]);

            if ($response['status'] === 429) {
                $refused = $response;
                break;
            }
            $this->assertSame(401, $response['status'], 'a wrong password was not refused');
        }

        $this->assertNotNull($refused, 'the guessing was never cut off');
        $this->assertGreaterThan(1, $attempts, 'the first attempt was already refused');
        $this->assertSame(
            1,
            preg_match('/retry-after:\s*(\d+)/i', $refused['headers'], $found),
            'the refusal does not say how long to wait',
        );
        $this->assertGreaterThan(0, (int) $found[1]);
    }
}
