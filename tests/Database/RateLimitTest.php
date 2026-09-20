<?php

declare(strict_types=1);

namespace Naf\Board\Tests\Database;

use Naf\Board\Tests\Support\DatabaseTestCase;
use Naf\RateLimit\PdoLimiter;

use function Naf\app;

/**
 * The counter that slows down password guessing.
 *
 * It lives in the database rather than in a process, because an installation may
 * serve requests from more than one of those and a limit each would be no limit
 * at all. The clock is passed in, so the expiry can be tested without waiting
 * for it.
 */
final class RateLimitTest extends DatabaseTestCase
{
    /**
     * Not transactional, and the limiter insists on it: a counter that a rolled
     * back transaction could take with it would let a failed login attempt go
     * uncounted, which is the one thing it exists to prevent. So the rows are
     * committed and the schema is rebuilt afterwards.
     */
    protected bool $transactional = false;

    public static function tearDownAfterClass(): void
    {
        self::clearAllRows();
        parent::tearDownAfterClass();
    }

    public function testAttemptsAreCountedPerKeyAndTheWindowExpires(): void
    {
        $limiter = app()->container()->make(PdoLimiter::class);

        $this->assertTrue($limiter->consume('test-account', 2, 60, 120)['allowed']);
        $this->assertTrue($limiter->consume('test-account', 2, 60, 121)['allowed']);

        $blocked = $limiter->consume('test-account', 2, 60, 122);
        $this->assertFalse($blocked['allowed'], 'the third attempt inside the window was allowed');
        $this->assertSame(58, $blocked['retry_after'], 'the wait is not the rest of the window');

        $this->assertTrue(
            $limiter->consume('different-account', 2, 60, 122)['allowed'],
            'one account being blocked blocked another',
        );
        $this->assertTrue(
            $limiter->consume('test-account', 2, 60, 180)['allowed'],
            'the window never reopened',
        );
    }
}
