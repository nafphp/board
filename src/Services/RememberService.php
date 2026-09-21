<?php

declare(strict_types=1);

namespace Naf\Board\Services;

use Naf\Auth\Auth;
use Naf\Board\Contracts\RememberStoreInterface;
use Naf\Board\Events\SignIn;
use Naf\Board\Support\RememberToken;
use PDO;

use function Naf\config;
use function Naf\event;

/**
 * Staying signed in after the session has gone.
 *
 * The rules, in the order they are checked, because each of them is the answer
 * to a way this goes wrong:
 *
 * A cookie that names no row is a cookie from a previous installation or a
 * guess. An expired row is over. A validator that does not match means two
 * copies of one cookie exist, so *every* token of that account is dropped --
 * the owner signs in again, and the thief is left with nothing. A security
 * version that has moved means the password or the address changed since, and
 * "changing your password signs you out everywhere" has to be true of this too.
 *
 * Only then is the person signed in, and the token rotated so that the cookie
 * just presented is no longer the one that works.
 */
final class RememberService
{
    public const string COOKIE   = 'naf_remember';
    public const string PROVIDER = 'remembered';

    /** Long enough to be worth having. An installation may say otherwise. */
    private const int DEFAULT_DAYS = 90;

    public function __construct(
        private Auth $auth,
        private RememberStoreInterface $store,
        private PDO $pdo,
    ) {
    }

    /** Remember whoever has just signed in, on this device. */
    public function issue(int $user): void
    {
        $token = RememberToken::issue();
        $this->store->remember($user, $token, $this->versionOf($user), $this->days());
        $this->send($token->cookie(), time() + $this->days() * 86400);
    }

    /**
     * Sign somebody in from their cookie, if it still says what it used to
     *
     * @return bool Whether an identity was published
     */
    public function resume(): bool
    {
        $token = RememberToken::parse((string) ($_COOKIE[self::COOKIE] ?? ''));
        if ($token === null) {
            return false;
        }

        $row = $this->store->find($token->selector);
        if ($row === null || $row['expired']) {
            $this->drop($token->selector);

            return false;
        }

        if (!$token->proves($row['validator_hash'])) {
            // Two copies of one cookie. Which of them is the owner cannot be
            // told from here, so neither is trusted and the account starts over.
            $this->store->forgetAll($row['user_id']);
            $this->clear();
            $this->announce($row['user_id'], false);

            return false;
        }

        if ($row['security_version'] !== $this->versionOf($row['user_id'])) {
            $this->drop($token->selector);

            return false;
        }

        $identity = $this->auth->load('users', (string) $row['user_id']);
        if ($identity === null) {
            $this->drop($token->selector);

            return false;
        }

        $fresh = $token->rotated();
        $this->store->rotate($token->selector, $fresh);
        $this->send($fresh->cookie(), time() + $this->days() * 86400);
        $this->auth->setIdentity($identity, 'users');
        $this->announce($row['user_id'], true);

        return true;
    }

    /** Sign out on this device: the cookie and the one row behind it. */
    public function forget(): void
    {
        $token = RememberToken::parse((string) ($_COOKIE[self::COOKIE] ?? ''));
        if ($token !== null) {
            $this->store->forget($token->selector);
        }

        $this->clear();
    }

    private function drop(string $selector): void
    {
        $this->store->forget($selector);
        $this->clear();
    }

    /**
     * Tell the application somebody arrived, or failed to.
     *
     * The provider says how rather than inventing a second event: `remembered`
     * is as true an answer to "which provider let them in" as `users` or `ldap`,
     * and it is what makes the log able to say that somebody came back on a
     * cookie rather than by typing anything.
     */
    private function announce(int $user, bool $succeeded): void
    {
        $statement = $this->pdo->prepare('SELECT email FROM users WHERE id=?');
        $statement->execute([$user]);

        event()->dispatch(new SignIn(
            (string) $statement->fetchColumn(),
            self::PROVIDER,
            $succeeded,
            $succeeded ? $user : null,
        ));
    }

    private function versionOf(int $user): int
    {
        $statement = $this->pdo->prepare('SELECT security_version FROM users WHERE id=? AND active=1');
        $statement->execute([$user]);

        return (int) $statement->fetchColumn();
    }

    private function days(): int
    {
        return max(1, (int) config('nafinity:remember_days', self::DEFAULT_DAYS));
    }

    /**
     * Send a cookie, and believe it for the rest of this request.
     *
     * setcookie() only queues a header; it leaves $_COOKIE holding what the
     * browser sent. Without the second line the application would spend the
     * remainder of the request disagreeing with the cookie it had just issued --
     * which is how a rotation looks like a theft to the next thing that reads it.
     */
    private function send(string $value, int $expires): void
    {
        if ($value === '') {
            unset($_COOKIE[self::COOKIE]);
        } else {
            $_COOKIE[self::COOKIE] = $value;
        }

        setcookie(self::COOKIE, $value, [
            'expires' => $expires,
            'path'    => '/',
            // Read by nothing in the browser, sent only over the connection the
            // application is served on, and never on a cross-site request that
            // is not a plain navigation.
            'httponly' => true,
            'secure'   => ($_SERVER['HTTPS'] ?? '') !== '',
            'samesite' => 'Lax',
        ]);
    }

    private function clear(): void
    {
        $this->send('', time() - 86400);
    }
}
