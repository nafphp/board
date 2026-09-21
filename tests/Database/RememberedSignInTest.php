<?php

declare(strict_types=1);

namespace Naf\Board\Tests\Database;

use Naf\Board\Services\RememberService;
use Naf\Board\Services\RememberStore;
use Naf\Board\Support\RememberToken;
use Naf\Board\Tests\Support\AccountTestCase;

/**
 * Coming back after the session is gone -- and the four ways that must not work.
 *
 * Each of these is a promise the application already makes elsewhere, which a
 * cookie outliving a session is the obvious way to break: that signing out ends
 * it, that changing a password ends it everywhere, that a copied credential is
 * worth nothing to whoever copied it, and that nothing expired keeps working.
 */
final class RememberedSignInTest extends AccountTestCase
{
    private RememberService $remember;
    private RememberStore $store;

    protected function setUp(): void
    {
        parent::setUp();
        $this->store    = new RememberStore($this->pdo);
        $this->remember = new RememberService($this->auth, $this->store, $this->pdo);
        $_COOKIE        = [];
    }

    public function testSomebodyRememberedIsSignedBackIn(): void
    {
        $user = $this->remembered();
        $this->auth->logout();

        $this->assertTrue($this->remember->resume(), 'a valid cookie did not sign anybody in');
        $this->assertSame((string) $user, $this->auth->id());
    }

    /**
     * The rotation, which is what makes a copied cookie findable: the one just
     * presented stops working the moment it is used.
     */
    public function testTheCookieJustUsedIsNoLongerTheOneThatWorks(): void
    {
        $this->remembered();
        $spent = $_COOKIE[RememberService::COOKIE];
        $this->auth->logout();

        $this->assertTrue($this->remember->resume());
        $this->assertNotSame($spent, $_COOKIE[RememberService::COOKIE], 'the token was not rotated');

        // Somebody who copied the cookie earlier arrives with the spent one.
        $this->auth->logout();
        $_COOKIE[RememberService::COOKIE] = $spent;

        $this->assertFalse($this->remember->resume(), 'a spent cookie still worked');
    }

    /**
     * And what that costs the account: everything. Which of the two held the
     * real cookie cannot be told from here, so neither is trusted.
     */
    public function testACopiedCookieEndsEveryRememberedSignInOfThatAccount(): void
    {
        $user  = $this->remembered();
        $spent = $_COOKIE[RememberService::COOKIE];
        $this->auth->logout();
        $this->remember->resume();

        $rotated = $_COOKIE[RememberService::COOKIE];
        $this->auth->logout();
        $_COOKIE[RememberService::COOKIE] = $spent;
        $this->remember->resume();

        $this->assertSame(0, $this->rowsFor($user), 'the account kept a token after a copy was seen');

        // Even the honest one, which is the price of not knowing who is who.
        $this->auth->logout();
        $_COOKIE[RememberService::COOKIE] = $rotated;
        $this->assertFalse($this->remember->resume(), 'the rotated cookie outlived the theft');
    }

    /** Signing out on this device takes the cookie and the row with it. */
    public function testSigningOutForgetsTheDevice(): void
    {
        $user = $this->remembered();
        $this->remember->forget();
        $this->auth->logout();

        $this->assertSame(0, $this->rowsFor($user));
        $this->assertFalse($this->remember->resume());
    }

    /**
     * The promise this would quietly break. Changing a password raises the
     * account's security version, and a cookie issued before it is over.
     */
    public function testChangingThePasswordEndsARememberedSignIn(): void
    {
        $user = $this->remembered();
        $this->auth->logout();

        $this->pdo
            ->prepare('UPDATE users SET security_version = security_version + 1 WHERE id=?')
            ->execute([$user]);

        $this->assertFalse($this->remember->resume(), 'a cookie survived a password change');
        $this->assertNull($this->auth->id());
    }

    public function testAnExpiredCookieIsOver(): void
    {
        $user = $this->remembered();
        $this->auth->logout();

        $this->pdo
            ->prepare('UPDATE remembered_sign_ins SET expires_at=? WHERE user_id=?')
            ->execute([gmdate('Y-m-d H:i:s', time() - 60), $user]);

        $this->assertFalse($this->remember->resume());
        $this->assertSame(0, $this->rowsFor($user), 'an expired row was left behind');
    }

    /** A cookie from nowhere is nobody, and does not reach a query as one. */
    public function testACookieThatNamesNothingSignsNobodyIn(): void
    {
        $_COOKIE[RememberService::COOKIE] = RememberToken::issue()->cookie();

        $this->assertFalse($this->remember->resume());
        $this->assertNull($this->auth->id());
    }

    public function testPruningDropsWhatHasExpiredAndKeepsWhatHasNot(): void
    {
        $user = $this->remembered();
        $this->store->remember($user, RememberToken::issue(), $this->versionOf($user), 90);
        $this->pdo
            ->prepare('UPDATE remembered_sign_ins SET expires_at=? WHERE user_id=? AND selector<>?')
            ->execute([
                gmdate('Y-m-d H:i:s', time() - 60),
                $user,
                RememberToken::parse($_COOKIE[RememberService::COOKIE])->selector,
            ]);

        $this->assertSame(1, $this->store->prune());
        $this->assertSame(1, $this->rowsFor($user));
    }

    /** An account with a remember-me cookie in hand, signed in. */
    private function remembered(): int
    {
        [$user] = $this->newAccount();
        $id     = (int) $user->getId();
        $token  = RememberToken::issue();

        $this->store->remember($id, $token, $this->versionOf($id), 90);
        $_COOKIE[RememberService::COOKIE] = $token->cookie();

        return $id;
    }

    private function versionOf(int $user): int
    {
        $statement = $this->pdo->prepare('SELECT security_version FROM users WHERE id=?');
        $statement->execute([$user]);

        return (int) $statement->fetchColumn();
    }

    private function rowsFor(int $user): int
    {
        $statement = $this->pdo->prepare('SELECT COUNT(*) FROM remembered_sign_ins WHERE user_id=?');
        $statement->execute([$user]);

        return (int) $statement->fetchColumn();
    }
}
