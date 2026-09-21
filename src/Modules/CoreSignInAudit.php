<?php

declare(strict_types=1);

namespace Naf\Board\Modules;

use Naf\Board\Contracts\ExtensionProviderInterface;
use Naf\Board\Domain\Change;
use Naf\Board\Events\SignIn;
use Naf\Board\ExtensionContext;
use PDO;
use Throwable;

use function Naf\app;
use function Naf\event;

/**
 * Who came in, and who tried.
 *
 * The log already knew when somebody changed their password and when they moved
 * their address. It did not know that anybody had signed in -- which in a system
 * whose promise is that everything is accountable is the entry people come
 * looking for first, and the one an intrusion is noticed by.
 *
 * A refused attempt is recorded with no actor. That is not a gap to be filled
 * later: naming somebody would mean asserting who they were on the strength of
 * a password that did not match. The address as typed is kept instead, which is
 * what a run of failures is grouped by, and it is kept whether or not any
 * account answers to it -- an attempt on an address nobody has says something
 * too.
 *
 * Its own transaction, because a sign-in is announced after the one that
 * published the session. That is deliberate: a listener must not be able to roll
 * back a sign-in that already happened, and an entry in the log is a second
 * write about it rather than part of it.
 *
 * @internal
 */
final class CoreSignInAudit implements ExtensionProviderInterface
{
    public const string SUCCEEDED = 'account.signed_in';
    public const string REFUSED   = 'account.sign_in_refused';

    public function register(ExtensionContext $context): void
    {
        event()->listen(SignIn::class, static function (SignIn $attempt): void {
            $pdo   = app()->container()->get(PDO::class);
            $owned = !$pdo->inTransaction();

            if ($owned) {
                $pdo->beginTransaction();
            }

            try {
                event()->dispatch(Change::inInstallation(
                    $attempt->accountId,
                    $attempt->succeeded ? self::SUCCEEDED : self::REFUSED,
                    [
                        'person'   => self::nameOf($pdo, $attempt->accountId) ?? $attempt->email,
                        'provider' => $attempt->provider,
                    ],
                ));

                if ($owned) {
                    $pdo->commit();
                }
            } catch (Throwable $failure) {
                if ($owned) {
                    $pdo->rollBack();
                }

                throw $failure;
            }
        });
    }

    /**
     * The name behind an account, resolved now.
     *
     * For the same reason a grant records role labels: an id names nothing once
     * the row behind it is gone, and this entry has to stay readable then. Null
     * for a refused attempt, where the caller falls back to what was typed.
     */
    private static function nameOf(PDO $pdo, ?int $account): ?string
    {
        if ($account === null) {
            return null;
        }

        $statement = $pdo->prepare('SELECT name FROM users WHERE id=?');
        $statement->execute([$account]);
        $name = $statement->fetchColumn();

        return $name === false ? null : (string) $name;
    }
}
