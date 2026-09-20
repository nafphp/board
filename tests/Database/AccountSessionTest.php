<?php

declare(strict_types=1);

namespace Naf\Board\Tests\Database;

use Naf\Auth\Session\SessionStateStore;
use Naf\Board\Support\AccountStateStore;
use Naf\Board\Tests\Support\AccountTestCase;
use Naf\Session\Core\Session;

use function Naf\app;

/**
 * Which sessions still count.
 *
 * Every session carries the account's security version, and changing a password
 * or an address raises it. A session holding an older number is one that was
 * open before that change -- exactly the session the change was meant to end.
 */
final class AccountSessionTest extends AccountTestCase
{
    public function testASessionFromBeforeThisMechanismExistedStillWorks(): void
    {
        [$user] = $this->newAccount();
        $store  = $this->store();
        $native = app()->container()->get(SessionStateStore::class);

        $native->write('users', $user->getIdentifier());
        // A session written before the version was recorded has no number at all,
        // and must not be thrown out for it -- that would sign everybody out on
        // the deploy that introduced this.
        app()->container()->get(Session::class)->forget('account.security_version');

        $this->assertSame($user->getIdentifier(), $store->read()['identifier']);
    }

    public function testASessionOlderThanTheLastSecurityChangeIsRejected(): void
    {
        [$user] = $this->newAccount();
        $store  = $this->store();
        $native = app()->container()->get(SessionStateStore::class);
        $store->write('users', $user->getIdentifier());

        $this->pdo
            ->prepare('UPDATE users SET security_version=security_version+1 WHERE id=?')
            ->execute([$user->getId()]);

        $this->assertNull($store->read(), 'a revoked session was still accepted');
        $this->assertNull($native->read(), 'the revoked session was left behind for the next read');
    }

    /**
     * The gap between checking a session and acting on it.
     *
     * A write takes the account lock and then looks the identity up again, so a
     * revocation that lands in between is still seen. Without the second look, a
     * session validated a moment before the revocation could change credentials
     * a moment after it.
     */
    public function testAWriteChecksTheSessionAgainAfterTakingTheLock(): void
    {
        [$user, $accounts] = $this->newAccount();
        $this->pdo->prepare('UPDATE users SET security_version=1 WHERE id=?')->execute([$user->getId()]);
        $this->auth->setIdentity($this->auth->load('users', $user->getIdentifier()), 'users');

        // The session as it was before the revocation.
        app()->container()->get(Session::class)->set('account.security_version', 0);

        $this->assertDenied(401, fn() => $accounts->changePassword(self::NEW_PASSWORD));
        $this->assertSame(
            1,
            (int) $this->scalar('SELECT security_version FROM users WHERE id=?', [$user->getId()]),
            'a session restored from before the revocation changed the credentials',
        );
    }

    private function store(): AccountStateStore
    {
        return new AccountStateStore(
            app()->container()->get(SessionStateStore::class),
            app()->container()->get(Session::class),
            $this->pdo,
        );
    }
}
